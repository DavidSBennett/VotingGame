<?php
/**
 * engine.php — the server-authoritative rules engine.
 *
 * Pure functions over the $game and $players arrays that lib.php loads.
 * No echo, no HTTP, no SQL except the event log: an endpoint opens the
 * transaction, hands the arrays here, and writes back whatever the engine
 * mutated. That separation is what lets a rules change be a one-file diff
 * with no migration.
 *
 * THE CLIENT IS PRESENTATION-ONLY. Anything the browser computes is an
 * advisory mirror; if the two disagree the server is right and the client
 * is stale. Never trust a value that arrived in a request body when the
 * same value can be recomputed from stored state.
 *
 * ---------------------------------------------------------------------
 * THE RULES IN ONE PLACE  (docs/DESIGN.md has the reasoning)
 *
 * You are a media apparatus, 1796 to 1860. Fourteen presidential spaces,
 * two candidates each. Most WEALTH at the end wins — elections are the
 * means, not the end.
 *
 * Each turn you play one card, in one of three ways:
 *
 *   FINANCE     take the card money. +control_bonus if you control the
 *               sitting president. Always legal.
 *   SWAY        pay the card cost, put control points on ONE of the two
 *               candidates, and move the issue tracks by the card deltas.
 *               The direction is fixed by history, not by you.
 *   TRANSITION  (key cards only) flip one issue to its successor and
 *               shuffle that pack into the deck.
 *
 * When every seat has taken turns_per_space turns, the election resolves:
 *
 *   1. The ISSUE TRACKS decide WHICH CANDIDATE wins — the dot product of
 *      their stances with where the country currently sits.
 *   2. CONTROL POINTS decide WHICH PLAYER owns them. Most points on the
 *      winner controls the presidency until the next election; a tie means
 *      nobody does. Points on the loser are wasted.
 *
 * The game ends when the fourteenth election resolves, or when stability
 * reaches zero and the Union breaks. Either way, wealth is counted where
 * it stands.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/history_data.php';
require_once __DIR__ . '/cards_data.php';

/** Bump when the state JSON shape changes incompatibly. */
define('ENGINE_STATE_VERSION', 2);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/**
 * Frozen into vg_games.config at start, so a mid-series rules change never
 * rewrites a game already in progress.
 *
 * EVERY NUMBER HERE WAS SET BY SIMULATION, not by feel. tools/simulate.py
 * plays the game a few thousand times per setting; docs/DESIGN.md section 8
 * records what each run found. The three that matter most:
 *
 *   turns_per_space   The payback window. At 2 a sway costs a finance turn
 *                     plus its price and leaves one turn to earn it back,
 *                     so NO control_bonus makes investing pay. At 3 it does.
 *   control_bonus     4 puts investing ahead of hoarding without letting
 *                     the first presidency run away with the game; at 6 the
 *                     investor won 95% and wealth-as-score was a formality.
 *   losing_cp_payout  Support for the LOSING candidate still pays. Without
 *                     it a failed bid burns, contesting is negative-sum, and
 *                     the opener of a campaign took control in 14 of 14.
 */
function engine_default_config() {
  return [
    'engine_version'  => ENGINE_STATE_VERSION,
    'total_spaces'    => 14,
    'turns_per_space' => 3,
    'hand_size'       => 5,
    'start_money'     => 12,
    'control_bonus'   => 4,
    'stability_start' => 28,
    'stability_recovery' => 3,
    'losing_cp_payout' => 1,
    'track_min'       => -5,
    'track_max'       => 5,
    'min_players'     => 1,
    'max_players'     => 5,
    'bots'            => 1,
  ];
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------

/**
 * Deal the opening position. Called once, inside the start transaction,
 * with the final seat list. Mutates $game and $players in place.
 */
function engine_setup(&$game, &$players, $mysqli = null) {
  $config = array_merge(engine_default_config(), is_array($game['config']) ? $game['config'] : []);
  $game['config'] = $config;

  $game['status']       = 'active';
  $game['phase']        = 'campaign';
  $game['round_number'] = 1;
  $game['current_seat'] = 0;
  $game['winner_seat']  = null;
  $game['ended_reason'] = null;

  // The three issue tracks, as slots. A slot keeps its position on the
  // board when its axis is superseded; the VALUE resets, because the
  // country has not yet staked out ground on the new question.
  $slots = [
    ['axis' => 'market',  'value' => 0, 'transitioned' => false],
    ['axis' => 'tariff',  'value' => 0, 'transitioned' => false],
    ['axis' => 'federal', 'value' => 0, 'transitioned' => false],
  ];

  $deck = engine_build_deck();

  // Both the stability pool and its recovery are expressed PER TWO SEATS
  // and scaled to the table here. Drain is per card played, so it scales
  // with the number of seats; a fixed pool made three- and four-player
  // games collapse before the board was two thirds played.
  $seatCount = max(1, count($players));
  $stabilityPool = intdiv((int) $config['stability_start'] * $seatCount, 2);
  $config['stability_max'] = $stabilityPool;
  $game['config'] = $config;

  $game['state'] = [
    'engine_version'         => ENGINE_STATE_VERSION,
    'space'                  => 1,
    'slots'                  => $slots,
    'stability'              => $stabilityPool,
    'deck'                   => $deck,
    'discard'                => [],
    'removed'                => [],
    'control'                => [],     // candidate_key => [seat => points]
    'president'              => null,   // set after the first election
    'turns_taken_this_space' => 0,
    'start_seat'             => 0,
    'history'                => [],
  ];

  foreach ($players as $seat => $p) {
    $players[$seat]['public_state'] = [
      'money'              => (int) $config['start_money'],
      'controls_president' => false,
      'presidencies'       => 0,
      'cards_played'       => 0,
      'hand_count'         => 0,
    ];
    $players[$seat]['private_state'] = ['hand' => []];
    $players[$seat]['score'] = (int) $config['start_money'];
  }

  // Deal after every seat exists, so the deck depletes in seat order.
  foreach ($players as $seat => $p) {
    engine_draw_up($game, $players[$seat], $config['hand_size']);
  }

  if ($mysqli) {
    $e = vg_election_at(1);
    engine_log($mysqli, $game, null, 'campaign_begins',
      'The campaign of ' . $e['year'] . ' opens: ' .
      $e['candidates'][0]['name'] . ' against ' . $e['candidates'][1]['name'] . '.',
      ['space' => 1, 'year' => $e['year']]);
  }
}

/**
 * The starting deck: the base pack, shuffled, with the three key cards
 * seeded at spread depths so the transitions cannot all arrive at once
 * and cannot all fail to arrive.
 */
function engine_build_deck() {
  $base = vg_cards_in_pack('base');
  $keys = vg_key_cards();
  $base = array_values(array_diff($base, $keys));
  shuffle($base);

  // Seed each key card into a different third of the deck.
  shuffle($keys);
  $count = count($base);
  $third = max(1, (int) floor($count / 3));
  $positions = [
    random_int((int) ($third * 0.5), $third),
    random_int($third + 1, $third * 2),
    random_int($third * 2 + 1, max($third * 2 + 2, $count)),
  ];
  foreach ($keys as $i => $keyCard) {
    $at = min(count($base), $positions[$i]);
    array_splice($base, $at, 0, [$keyCard]);
  }
  return $base;
}

// ---------------------------------------------------------------------
// Deck handling
// ---------------------------------------------------------------------

/** Draw one card key, reshuffling the discard pile if the deck runs dry. */
function engine_draw_one(&$game) {
  if (empty($game['state']['deck'])) {
    if (empty($game['state']['discard'])) return null;
    $game['state']['deck'] = $game['state']['discard'];
    $game['state']['discard'] = [];
    shuffle($game['state']['deck']);
  }
  return array_shift($game['state']['deck']);
}

/** Refill one player up to the hand size. */
function engine_draw_up(&$game, &$player, $handSize) {
  $hand = isset($player['private_state']['hand']) ? $player['private_state']['hand'] : [];
  while (count($hand) < $handSize) {
    $card = engine_draw_one($game);
    if ($card === null) break;
    $hand[] = $card;
  }
  $player['private_state']['hand'] = $hand;
  $player['public_state']['hand_count'] = count($hand);
}

// ---------------------------------------------------------------------
// Track helpers
// ---------------------------------------------------------------------

/** Slot index for an axis key, or null when that axis is not live. */
function engine_slot_for_axis($game, $axis) {
  foreach ($game['state']['slots'] as $i => $slot) {
    if ($slot['axis'] === $axis) return $i;
  }
  return null;
}

/** Can this card be played for SWAY right now? */
function engine_card_swayable($game, $cardKey) {
  $card = vg_card($cardKey);
  if (!$card) return false;
  if (!empty($card['key'])) return false;          // key cards transition instead
  foreach (array_keys($card['deltas']) as $axis) {
    if (engine_slot_for_axis($game, $axis) === null) return false;
  }
  return true;
}

/** Apply a card deltas to the live tracks, clamped. Returns what moved. */
function engine_apply_deltas(&$game, $deltas) {
  $min = (int) $game['config']['track_min'];
  $max = (int) $game['config']['track_max'];
  $moved = [];
  foreach ($deltas as $axis => $delta) {
    $i = engine_slot_for_axis($game, $axis);
    if ($i === null) continue;
    $before = (int) $game['state']['slots'][$i]['value'];
    $after = max($min, min($max, $before + (int) $delta));
    $game['state']['slots'][$i]['value'] = $after;
    if ($after !== $before) $moved[$axis] = ['from' => $before, 'to' => $after];
  }
  return $moved;
}

// ---------------------------------------------------------------------
// Action dispatch
// ---------------------------------------------------------------------

/**
 * The single mutating entry point. Throw Exception with a player-facing
 * message to reject an illegal action — the endpoint rolls back and
 * returns it as { error }.
 *
 * @return string player-facing confirmation
 */
function engine_apply_action(&$game, &$players, $seat, $action, $params, $mysqli) {
  if ($game['status'] !== 'active') throw new Exception('This game is not in progress.');
  if (!isset($players[$seat]))      throw new Exception('You are not seated in this game.');
  if (!empty($players[$seat]['conceded'])) throw new Exception('You have already left this game.');

  if ($action === 'concede') {
    $players[$seat]['conceded'] = 1;
    $msg = $players[$seat]['player_name'] . ' shut down the presses.';
    engine_log($mysqli, $game, $seat, 'concede', $msg, null, $players[$seat]['player_name']);
    // engine_end_turn ends the game outright if that was the last human.
    engine_end_turn($game, $players, $mysqli);
    return $msg;
  }

  engine_require_turn($game, $seat);

  $msg = engine_play_card($game, $players, $seat, $action, $params, $mysqli);
  engine_end_turn($game, $players, $mysqli);
  return $msg;
}

/**
 * Play one card in one of the three ways. Shared by human turns and the
 * bot, so a bot can never do something a player could not.
 */
function engine_play_card(&$game, &$players, $seat, $action, $params, $mysqli) {
  $player = &$players[$seat];
  $config = $game['config'];

  $cardKey = isset($params['card']) ? (string) $params['card'] : '';
  $hand = isset($player['private_state']['hand']) ? $player['private_state']['hand'] : [];
  $at = array_search($cardKey, $hand, true);
  if ($at === false) throw new Exception('That card is not in your hand.');

  $card = vg_card($cardKey);
  if (!$card) throw new Exception('Unknown card.');

  $name = $player['player_name'];
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);

  switch ($action) {

    case 'finance': {
      $gain = (int) $card['finance'];
      $bonus = !empty($player['public_state']['controls_president']) ? (int) $config['control_bonus'] : 0;
      $player['public_state']['money'] = (int) $player['public_state']['money'] + $gain + $bonus;

      // The country is inflamed by the story being printed at all, not by
      // the motive for printing it. Charging stability on Finance as well as
      // Sway is what stops a player who never sways from free-riding on the
      // stability everybody else spends.
      engine_adjust_stability($game, (int) $card['stability'], $mysqli);

      $msg = $name . ' ran ' . $card['name'] . ' for ' . ($gain + $bonus) . ' wealth'
           . ($bonus ? ' (including ' . $bonus . ' from the administration).' : '.');
      engine_log($mysqli, $game, $seat, 'finance', $msg,
        ['card' => $cardKey, 'gain' => $gain, 'control_bonus' => $bonus,
         'stability_delta' => (int) $card['stability'],
         'stability' => $game['state']['stability'],
         'money' => $player['public_state']['money']], $name);
      break;
    }

    case 'sway': {
      if (!engine_card_swayable($game, $cardKey)) {
        throw new Exception($card['name'] . ' is no longer what the country argues about. You can still run it for money.');
      }
      $cost = (int) $card['sway_cost'];
      if ((int) $player['public_state']['money'] < $cost) {
        throw new Exception('You cannot afford that: it costs ' . $cost . ' and you hold ' . $player['public_state']['money'] . '.');
      }

      $candidateKey = isset($params['candidate']) ? (string) $params['candidate'] : '';
      $valid = false;
      foreach ($election['candidates'] as $c) {
        if ($c['key'] === $candidateKey) { $valid = true; $candidate = $c; break; }
      }
      if (!$valid) throw new Exception('That candidate is not standing in this election.');

      $player['public_state']['money'] -= $cost;

      $cp = (int) $card['sway_cp'];
      if (!isset($game['state']['control'][$candidateKey])) {
        $game['state']['control'][$candidateKey] = [];
      }
      $current = isset($game['state']['control'][$candidateKey][$seat])
        ? (int) $game['state']['control'][$candidateKey][$seat] : 0;
      $game['state']['control'][$candidateKey][$seat] = $current + $cp;

      $moved = engine_apply_deltas($game, $card['deltas']);
      $stabilityDelta = (int) $card['stability'];

      // Pushing a track that is already at an extreme inflames the country
      // further than the card alone would.
      foreach ($moved as $axis => $m) {
        if (abs($m['to']) >= 4) $stabilityDelta -= 1;
      }
      engine_adjust_stability($game, $stabilityDelta, $mysqli);

      $msg = $name . ' ran ' . $card['name'] . ' for ' . $candidate['name']
           . ' (+' . $cp . ' control, -' . $cost . ' wealth).';
      engine_log($mysqli, $game, $seat, 'sway', $msg,
        ['card' => $cardKey, 'candidate' => $candidateKey, 'cost' => $cost,
         'control_points' => $cp, 'tracks_moved' => $moved,
         'stability_delta' => $stabilityDelta,
         'stability' => $game['state']['stability']], $name);
      break;
    }

    case 'transition': {
      if (empty($card['key'])) throw new Exception('That is not a key card.');
      $earliest = (int) ($card['earliest_space'] ?? 1);
      if ($space < $earliest) {
        $gate = vg_election_at($earliest);
        throw new Exception('The country is not arguing about that yet. '
          . $card['name'] . ' cannot be played before ' . $gate['year'] . '.');
      }
      $axis = $card['transitions'];
      $i = engine_slot_for_axis($game, $axis);
      if ($i === null) throw new Exception('That question has already been superseded.');

      $tracks = vg_issue_tracks();
      $oldName = $tracks['early'][$axis]['name'];
      $newAxis = $card['unlocks'];
      $newName = $tracks['late'][$newAxis]['name'];

      $game['state']['slots'][$i] = [
        'axis' => $newAxis, 'value' => 0, 'transitioned' => true,
      ];

      // The pack this card unlocks joins the deck. Playing Manifest
      // Destiny is literally what brings Texas into the conversation.
      $pack = vg_cards_in_pack($newAxis);
      foreach ($pack as $newCard) $game['state']['deck'][] = $newCard;
      shuffle($game['state']['deck']);

      engine_adjust_stability($game, (int) $card['stability'], $mysqli);

      // Naming the new national question is itself the story you sell.
      // Without this the card is strictly worse than playing it for money,
      // and in 300 simulated games not one transition was ever fired.
      $gain = (int) $card['finance'];
      $player['public_state']['money'] = (int) $player['public_state']['money'] + $gain;

      $msg = $name . ' played ' . $card['name'] . '. The country stops arguing about '
           . $oldName . ' and starts arguing about ' . $newName . '.';
      engine_log($mysqli, $game, $seat, 'transition', $msg,
        ['card' => $cardKey, 'from' => $axis, 'to' => $newAxis,
         'gain' => $gain, 'cards_added' => count($pack)], $name);
      break;
    }

    default:
      throw new Exception('Unknown action: ' . $action);
  }

  // The card leaves the hand. Key cards leave the game entirely.
  array_splice($hand, $at, 1);
  $player['private_state']['hand'] = $hand;
  $player['public_state']['cards_played'] = 1 + (int) $player['public_state']['cards_played'];

  if ($action === 'transition') {
    $game['state']['removed'][] = $cardKey;
  } else {
    $game['state']['discard'][] = $cardKey;
  }

  engine_draw_up($game, $player, (int) $config['hand_size']);
  $player['score'] = (int) $player['public_state']['money'];

  return $msg;
}

/** Reject an out-of-turn action. */
function engine_require_turn($game, $seat) {
  if ($game['current_seat'] === null) return;
  if ((int) $game['current_seat'] !== (int) $seat) {
    throw new Exception('It is not your turn.');
  }
}

/** Seats still playing, bots included. */
function engine_active_seats($players) {
  $n = 0;
  foreach ($players as $p) if (empty($p['conceded'])) $n++;
  return $n;
}

/**
 * Seats still playing that are actually people.
 *
 * The distinction matters: a game whose only human has conceded is over,
 * however many rival papers are still willing to print. Counting bots as
 * active let the first live game run thirteen elections after its only
 * player left.
 */
function engine_human_seats($players) {
  $n = 0;
  foreach ($players as $p) {
    if (empty($p['conceded']) && empty($p['is_bot'])) $n++;
  }
  return $n;
}

// ---------------------------------------------------------------------
// Stability
// ---------------------------------------------------------------------

/**
 * Move the stability track. At zero the Union breaks and the game ends
 * where it stands — there is no bonus for causing it and no penalty for
 * it happening. You simply might not have banked your winnings yet.
 */
function engine_adjust_stability(&$game, $delta, $mysqli) {
  if ($delta === 0) return;
  $before = (int) $game['state']['stability'];
  $ceiling = (int) ($game['config']['stability_max'] ?? $game['config']['stability_start']);
  $after = max(0, min($ceiling, $before + $delta));
  $game['state']['stability'] = $after;

  if ($after < $before && $after <= 3 && $before > 3) {
    engine_log($mysqli, $game, null, 'stability_warning',
      'The Union is fraying badly.', ['stability' => $after]);
  }
}

// ---------------------------------------------------------------------
// Turn order and the election
// ---------------------------------------------------------------------

/**
 * End the current turn: either pass to the next seat, or — when every
 * seat has had its turns for this space — resolve the election.
 */
function engine_end_turn(&$game, &$players, $mysqli) {
  if ($game['status'] !== 'active') return;

  // No people left at the table: the game is over regardless of how many
  // bots would still happily play on. Checked here rather than in the
  // concede branch so it covers every path that can empty the table.
  if (engine_human_seats($players) < 1) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return;
  }

  // Stability collapse is checked here rather than inside the card play,
  // so the acting player always completes the action that caused it.
  if ((int) $game['state']['stability'] <= 0) {
    engine_end_game($game, $players, 'the_union_breaks', $mysqli);
    return;
  }

  $game['state']['turns_taken_this_space'] = 1 + (int) $game['state']['turns_taken_this_space'];

  $active = engine_active_seats($players);
  $needed = $active * (int) $game['config']['turns_per_space'];

  if ($game['state']['turns_taken_this_space'] >= $needed) {
    engine_resolve_election($game, $players, $mysqli);
    return;
  }
  engine_next_seat($game, $players);
}

/** Pass to the next non-conceded seat. */
function engine_next_seat(&$game, &$players) {
  $seatCount = count($players);
  if ($seatCount === 0) return;
  $from = (int) $game['current_seat'];
  for ($step = 1; $step <= $seatCount; $step++) {
    $next = ($from + $step) % $seatCount;
    if (isset($players[$next]) && empty($players[$next]['conceded'])) {
      $game['current_seat'] = $next;
      return;
    }
  }
}

/**
 * How well a candidate matches where the country currently sits: the dot
 * product of their stances with the three track positions.
 *
 * A track at zero contributes nothing — the country has not decided, so
 * that question does not help anybody. A candidate strongly for something
 * the country is strongly against scores strongly negative.
 */
function engine_candidate_alignment($game, $candidate) {
  $total = 0;
  $detail = [];
  foreach ($game['state']['slots'] as $slot) {
    $axis = $slot['axis'];
    $value = (int) $slot['value'];
    $stance = $slot['transitioned']
      ? (int) ($candidate['stance_late'][$axis] ?? 0)
      : (int) ($candidate['stance_early'][$axis] ?? 0);
    $contribution = $stance * $value;
    $total += $contribution;
    $detail[$axis] = ['stance' => $stance, 'track' => $value, 'points' => $contribution];
  }
  return ['total' => $total, 'detail' => $detail];
}

/** Total control points on a candidate, by seat. */
function engine_control_on($game, $candidateKey) {
  return isset($game['state']['control'][$candidateKey])
    ? $game['state']['control'][$candidateKey] : [];
}

/**
 * Resolve the election on the current space, award the presidency, and
 * advance the board.
 */
function engine_resolve_election(&$game, &$players, $mysqli) {
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);
  if (!$election) {
    engine_end_game($game, $players, 'board_exhausted', $mysqli);
    return;
  }

  $a = $election['candidates'][0];
  $b = $election['candidates'][1];
  $alignA = engine_candidate_alignment($game, $a);
  $alignB = engine_candidate_alignment($game, $b);

  $cpA = array_sum(engine_control_on($game, $a['key']));
  $cpB = array_sum(engine_control_on($game, $b['key']));

  // Issues decide the candidate. Control breaks a dead heat — when the
  // country is genuinely undecided, the loudest press wins. History
  // breaks a tie in that too.
  if ($alignA['total'] !== $alignB['total']) {
    $winner = ($alignA['total'] > $alignB['total']) ? $a : $b;
    $reason = 'issues';
  } elseif ($cpA !== $cpB) {
    $winner = ($cpA > $cpB) ? $a : $b;
    $reason = 'control';
  } else {
    $winner = ($election['historical_winner'] === $a['key']) ? $a : $b;
    $reason = 'history';
  }

  // Which player owns the winner. A tie means nobody does.
  $control = engine_control_on($game, $winner['key']);
  $controllerSeat = null;
  $best = 0;
  $tied = false;
  foreach ($control as $seat => $points) {
    $points = (int) $points;
    if ($points > $best) { $best = $points; $controllerSeat = (int) $seat; $tied = false; }
    elseif ($points === $best && $points > 0) { $tied = true; }
  }
  if ($tied || $best <= 0) $controllerSeat = null;

  foreach ($players as $seat => $p) {
    $has = ($controllerSeat !== null && (int) $seat === $controllerSeat);
    $players[$seat]['public_state']['controls_president'] = $has;
    if ($has) {
      $players[$seat]['public_state']['presidencies'] =
        1 + (int) $players[$seat]['public_state']['presidencies'];
    }
  }

  $controllerName = ($controllerSeat !== null && isset($players[$controllerSeat]))
    ? $players[$controllerSeat]['player_name'] : null;

  $msg = $election['year'] . ': ' . $winner['name'] . ' takes the presidency'
       . ($controllerName ? ', and ' . $controllerName . ' owns the administration.'
                          : ', with no paper able to claim him.');

  engine_log($mysqli, $game, null, 'election', $msg, [
    'space' => $space, 'year' => $election['year'],
    'winner' => $winner['key'], 'decided_by' => $reason,
    'alignment' => [$a['key'] => $alignA['total'], $b['key'] => $alignB['total']],
    'alignment_detail' => [$a['key'] => $alignA['detail'], $b['key'] => $alignB['detail']],
    'control' => [$a['key'] => engine_control_on($game, $a['key']),
                  $b['key'] => engine_control_on($game, $b['key'])],
    'controller_seat' => $controllerSeat,
    'historical_winner' => $election['historical_winner'],
    'stability' => (int) $game['state']['stability'],
  ]);

  $game['state']['history'][] = [
    'space' => $space, 'year' => $election['year'],
    'winner' => $winner['key'], 'winner_name' => $winner['name'],
    'decided_by' => $reason,
    'controller_seat' => $controllerSeat,
    'controller_name' => $controllerName,
    'matched_history' => ($winner['key'] === $election['historical_winner']),
    'stability' => (int) $game['state']['stability'],
  ];

  $game['state']['president'] = [
    'candidate' => $winner['key'],
    'name' => $winner['name'],
    'year' => $election['year'],
    'controller_seat' => $controllerSeat,
  ];

  // Support for the LOSING candidate is not wasted: you backed the wrong
  // man, but you sold newspapers doing it. Without this a failed bid burns
  // outright, contesting is negative-sum, and control silently goes
  // uncontested to whoever bids first — which is what the simulation found.
  $loser = ($winner['key'] === $a['key']) ? $b : $a;
  $rate = (int) ($game['config']['losing_cp_payout'] ?? 0);
  if ($rate > 0) {
    foreach (engine_control_on($game, $loser['key']) as $s => $pts) {
      if (!isset($players[$s])) continue;
      $paid = (int) $pts * $rate;
      if ($paid <= 0) continue;
      $players[$s]['public_state']['money'] =
        (int) $players[$s]['public_state']['money'] + $paid;
      $players[$s]['score'] = (int) $players[$s]['public_state']['money'];
      engine_log($mysqli, $game, (int) $s, 'losing_support',
        $players[$s]['player_name'] . ' sold papers for ' . $loser['name']
        . ' and took ' . $paid . ' wealth from a losing campaign.',
        ['candidate' => $loser['key'], 'points' => (int) $pts, 'paid' => $paid],
        $players[$s]['player_name']);
    }
  }

  // An election settles the country a little: the result is accepted,
  // and the argument starts over. This is the stability track income,
  // and it is what lets the early republic absorb quarrels the 1850s
  // cannot.
  // Proportional to the table: four seats play twice as many cards per
  // era as two, so a flat recovery made the game unplayable above two
  // players — it collapsed after 2.8 of 14 spaces. The rate is expressed
  // per two seats, which preserves the heads-up value it was tuned at.
  $recovery = intdiv((int) ($game['config']['stability_recovery'] ?? 0)
                     * max(1, count($players)), 2);
  engine_adjust_stability($game, $recovery, $mysqli);

  // Clear the slate for the next campaign.
  $game['state']['control'] = [];
  $game['state']['turns_taken_this_space'] = 0;
  $game['state']['space'] = $space + 1;
  $game['round_number'] = $space + 1;

  if ($game['state']['space'] > (int) $game['config']['total_spaces']) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }

  $next = vg_election_at($game['state']['space']);
  engine_log($mysqli, $game, null, 'campaign_begins',
    'The campaign of ' . $next['year'] . ' opens: ' .
    $next['candidates'][0]['name'] . ' against ' . $next['candidates'][1]['name'] . '.',
    ['space' => $game['state']['space'], 'year' => $next['year']]);

  // Rotate who OPENS the campaign. Without this the same paper opened
  // every era, bought control first and cheapest, and no rival could ever
  // profitably contest it.
  $seatCount = max(1, count($players));
  $game['state']['start_seat'] = ((int) ($game['state']['start_seat'] ?? 0) + 1) % $seatCount;
  $game['current_seat'] = (int) $game['state']['start_seat'];
  if (!empty($players[$game['current_seat']]['conceded'])) {
    engine_next_seat($game, $players);
  }
}

// ---------------------------------------------------------------------
// The bot — solo play
// ---------------------------------------------------------------------

/**
 * Run every consecutive bot seat until a human is on turn or the game
 * ends. Called after each human action, inside the same transaction, so
 * a solo player sees the whole round resolve in one response.
 *
 * The bot plays through engine_play_card like anybody else, so it can
 * never do something a player could not.
 */
function engine_run_bots(&$game, &$players, $mysqli, $limit = null) {
  // One full round of the current space, plus a small margin for the
  // rollover into the next one. This is a bound on NORMAL play, not just
  // an anti-infinite-loop backstop: the previous limit of 40 let a single
  // request play thirteen elections once every seat was a bot.
  if ($limit === null) {
    $limit = count($players) * (int) $game['config']['turns_per_space'] + 4;
  }
  $steps = 0;
  while ($game['status'] === 'active' && $steps < $limit) {
    // Never play on behalf of a table nobody is sitting at.
    if (engine_human_seats($players) < 1) break;
    $seat = $game['current_seat'];
    if ($seat === null || !isset($players[$seat])) break;
    if (empty($players[$seat]['is_bot']) || !empty($players[$seat]['conceded'])) break;

    list($action, $params) = engine_bot_choice($game, $players, $seat);
    try {
      engine_play_card($game, $players, $seat, $action, $params, $mysqli);
    } catch (Exception $e) {
      // A bot must never wedge the game. Fall back to the always-legal
      // move, and if even that fails, drop the card.
      $hand = $players[$seat]['private_state']['hand'];
      if (empty($hand)) { $players[$seat]['conceded'] = 1; break; }
      engine_play_card($game, $players, $seat, 'finance', ['card' => $hand[0]], $mysqli);
    }
    engine_end_turn($game, $players, $mysqli);
    $steps++;
  }
}

/**
 * The bot decision — the 'tycoon' heuristic, which is the strategy the
 * balance tuning was actually validated against (tools/simulate.py). If
 * this and strat_tycoon there drift apart, the tuning stops meaning
 * anything, so change them together.
 *
 * Deliberately a readable heuristic rather than a search: it should play
 * like a plausible rival press, not like a solver, and it has to be
 * explainable when a playtest says it did something odd.
 *
 *   1. Holding the presidency? Bank — the income is the whole point.
 *   2. Already leading the candidate the issues favour? Bank; do not bid
 *      against yourself.
 *   3. Otherwise take the lead with the CHEAPEST sway that does it.
 *   4. Nothing worth buying? Bank, preferring a key card, since a
 *      transition pays the same money and moves the board too.
 */
function engine_bot_choice($game, $players, $seat) {
  $player = $players[$seat];
  $hand = isset($player['private_state']['hand']) ? $player['private_state']['hand'] : [];
  if (empty($hand)) return ['finance', []];

  $money = (int) $player['public_state']['money'];
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);
  if (!$election) return engine_bot_bank($game, $players, $seat);

  if (!empty($player['public_state']['controls_president'])) {
    return engine_bot_bank($game, $players, $seat);
  }

  $a = $election['candidates'][0];
  $b = $election['candidates'][1];
  $favoured = (engine_candidate_alignment($game, $a)['total']
            >= engine_candidate_alignment($game, $b)['total']) ? $a : $b;

  $control = engine_control_on($game, $favoured['key']);
  $mine = isset($control[$seat]) ? (int) $control[$seat] : 0;
  $rival = 0;
  foreach ($control as $s => $pts) {
    if ((int) $s !== (int) $seat) $rival = max($rival, (int) $pts);
  }
  if ($mine > $rival) return engine_bot_bank($game, $players, $seat);

  $seats = max(1, count($players));
  $turnsLeft = intdiv($seats * (int) $game['config']['turns_per_space']
                    - (int) $game['state']['turns_taken_this_space'], $seats);
  if ($turnsLeft < 1) return engine_bot_bank($game, $players, $seat);

  // Cheapest card that actually takes the lead. Once losing support pays
  // out, a bid that fails refunds rather than burns, so a late bid is
  // worth making — guarding against it was what handed every presidency
  // to whoever opened the campaign.
  $bestCard = null;
  $bestCost = PHP_INT_MAX;
  foreach ($hand as $key) {
    $c = vg_card($key);
    if (!$c || !engine_card_swayable($game, $key)) continue;
    if ((int) $c['sway_cost'] > $money) continue;
    if ($mine + (int) $c['sway_cp'] <= $rival) continue;
    if ((int) $c['sway_cost'] < $bestCost) {
      $bestCost = (int) $c['sway_cost'];
      $bestCard = $key;
    }
  }
  if ($bestCard !== null) {
    return ['sway', ['card' => $bestCard, 'candidate' => $favoured['key']]];
  }
  return engine_bot_bank($game, $players, $seat);
}

/**
 * Bank a card — preferring a key card, because a transition pays the same
 * money AND moves the board. Held back only while the track it would reset
 * is one this bot is currently winning on: resetting that track would
 * throw away the position it just paid for.
 */
function engine_bot_bank($game, $players, $seat) {
  $player = $players[$seat];
  $hand = isset($player['private_state']['hand']) ? $player['private_state']['hand'] : [];
  if (empty($hand)) return ['finance', []];

  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);

  foreach ($hand as $key) {
    $c = vg_card($key);
    if (!$c || empty($c['key'])) continue;
    if ($space < (int) ($c['earliest_space'] ?? 1)) continue;
    $i = engine_slot_for_axis($game, $c['transitions']);
    if ($i === null) continue;

    $helpingMe = false;
    if ($election) {
      $a = $election['candidates'][0];
      $b = $election['candidates'][1];
      $favoured = (engine_candidate_alignment($game, $a)['total']
                >= engine_candidate_alignment($game, $b)['total']) ? $a : $b;
      $slot = $game['state']['slots'][$i];
      $stance = (int) ($favoured['stance_early'][$slot['axis']] ?? 0);
      $control = engine_control_on($game, $favoured['key']);
      $helpingMe = ($stance * (int) $slot['value']) > 2
        && isset($control[$seat]) && (int) $control[$seat] > 0;
    }
    if (!$helpingMe) return ['transition', ['card' => $key]];
  }

  $best = $hand[0];
  foreach ($hand as $key) {
    $c = vg_card($key);
    if ($c && (int) $c['finance'] > (int) vg_card($best)['finance']) $best = $key;
  }
  return ['finance', ['card' => $best]];
}

// ---------------------------------------------------------------------
// Ending and scoring
// ---------------------------------------------------------------------

/** Finish the game. Idempotent — several paths reach it. */
function engine_end_game(&$game, &$players, $reason, $mysqli) {
  if ($game['status'] === 'ended') return;

  $game['status']       = 'ended';
  $game['phase']        = 'results';
  $game['current_seat'] = null;
  $game['ended_reason'] = $reason;

  $best = null;
  foreach ($players as $seat => $p) {
    $result = engine_score_player($game, $players, $seat);
    $players[$seat]['final_score']     = (int) $result['total'];
    $players[$seat]['score']           = (int) $result['total'];
    $players[$seat]['score_breakdown'] = $result['breakdown'];
  }
  foreach ($players as $seat => $p) {
    if (!empty($p['conceded'])) continue;
    if ($best === null || (int) $players[$seat]['final_score'] > (int) $players[$best]['final_score']) {
      $best = (int) $seat;
    }
  }
  $game['winner_seat'] = $best;

  $reasonText = [
    'board_completed'   => 'The board is played out. It is 1860.',
    'the_union_breaks'  => 'The Union breaks. The presses stop where they stand.',
    'board_exhausted'   => 'The board ran out.',
    'all_conceded'      => 'Every paper has shut down.',
    'all_humans_left'   => 'The last editor walked away.',
    'no_active_players' => 'Nobody is left to print.',
  ];

  engine_log($mysqli, $game, null, 'game_ended',
    ($reasonText[$reason] ?? 'The game ended.') .
    ($best !== null ? ' ' . $players[$best]['player_name'] . ' ends richest.' : ''),
    ['reason' => $reason, 'winner_seat' => $best,
     'stability' => (int) $game['state']['stability'],
     'spaces_played' => count($game['state']['history'])]);
}

/**
 * Final score. Wealth IS the score — the brief is explicit that the
 * richest player wins, and nothing else is added on top. Presidencies
 * are reported because they explain the wealth, not because they score.
 */
function engine_score_player($game, $players, $seat) {
  $p = $players[$seat];
  $money = (int) ($p['public_state']['money'] ?? 0);
  return [
    'total' => $money,
    'breakdown' => [
      'wealth'       => $money,
      'presidencies' => (int) ($p['public_state']['presidencies'] ?? 0),
      'cards_played' => (int) ($p['public_state']['cards_played'] ?? 0),
    ],
  ];
}

// ---------------------------------------------------------------------
// Public projection — the ONLY thing getState.php serialises
// ---------------------------------------------------------------------

/**
 * Build the state blob the client polls. Every seat sees the same public
 * payload; exactly one private block is included, for the asking seat.
 *
 * This function is the hidden-information boundary. Hands are private —
 * other seats get a COUNT, never the contents.
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $tracks = vg_issue_tracks();

  $seats = [];
  foreach ($players as $seat => $p) {
    $seats[] = [
      'seat'            => (int) $seat,
      'player_name'     => $p['player_name'],
      'is_bot'          => (bool) $p['is_bot'],
      'conceded'        => (bool) $p['conceded'],
      'money'           => (int) ($p['public_state']['money'] ?? 0),
      'controls_president' => (bool) ($p['public_state']['controls_president'] ?? false),
      'presidencies'    => (int) ($p['public_state']['presidencies'] ?? 0),
      'hand_count'      => (int) ($p['public_state']['hand_count'] ?? 0),
      'score'           => (int) $p['score'],
      'final_score'     => $p['final_score'],
      'score_breakdown' => ($game['status'] === 'ended') ? $p['score_breakdown'] : null,
      'last_seen_at'    => $p['last_seen_at'],
      'is_you'          => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
    ];
  }

  $state = $game['state'];
  $space = (int) ($state['space'] ?? 1);
  $election = vg_election_at($space);

  // The live tracks, with the names the country currently uses for them.
  $liveTracks = [];
  foreach (($state['slots'] ?? []) as $slot) {
    $axis = $slot['axis'];
    $def = $slot['transitioned'] ? $tracks['late'][$axis] : $tracks['early'][$axis];
    $liveTracks[] = [
      'axis' => $axis, 'name' => $def['name'],
      'low' => $def['low'], 'high' => $def['high'],
      'value' => (int) $slot['value'],
      'transitioned' => (bool) $slot['transitioned'],
    ];
  }

  // The current race, with each candidate current standing. Alignment is
  // public: a newspaper can read the country as well as anyone.
  $race = null;
  if ($election && $game['status'] === 'active') {
    $cands = [];
    foreach ($election['candidates'] as $c) {
      $align = engine_candidate_alignment($game, $c);
      $cands[] = [
        'key' => $c['key'], 'name' => $c['name'], 'party' => $c['party'],
        'note' => $c['note'],
        'stance' => $align['detail'],
        'alignment' => $align['total'],
        'control' => engine_control_on($game, $c['key']),
      ];
    }
    $race = [
      'space' => $space, 'year' => $election['year'],
      'note' => $election['note'],
      'candidates' => $cands,
      'turns_taken' => (int) ($state['turns_taken_this_space'] ?? 0),
      'turns_needed' => engine_active_seats($players) * (int) $game['config']['turns_per_space'],
    ];
  }

  // The viewer hand, with per-card legality worked out server-side so the
  // UI never has to reimplement a rule to grey out a button.
  $you = null;
  if ($viewerSeat !== null && isset($players[$viewerSeat])) {
    $me = $players[$viewerSeat];
    $money = (int) ($me['public_state']['money'] ?? 0);
    $hand = [];
    foreach (($me['private_state']['hand'] ?? []) as $key) {
      $c = vg_card($key);
      if (!$c) continue;
      $hand[] = [
        'key' => $key, 'name' => $c['name'], 'year' => $c['year'],
        'flavor' => $c['flavor'],
        'finance' => (int) $c['finance'],
        'sway_cost' => (int) $c['sway_cost'],
        'sway_cp' => (int) $c['sway_cp'],
        'deltas' => $c['deltas'],
        'stability' => (int) $c['stability'],
        'is_key' => !empty($c['key']),
        'can_sway' => engine_card_swayable($game, $key) && $money >= (int) $c['sway_cost'],
        'can_transition' => !empty($c['key'])
          && engine_slot_for_axis($game, $c['transitions']) !== null
          && $space >= (int) ($c['earliest_space'] ?? 1),
        'earliest_space' => isset($c['earliest_space']) ? (int) $c['earliest_space'] : null,
      ];
    }
    $you = ['seat' => (int) $viewerSeat, 'hand' => $hand];
  }

  return [
    'game_id'       => (int) $game['game_id'],
    'join_code'     => $game['join_code'],
    'status'        => $game['status'],
    'variant'       => $game['variant'],
    'phase'         => $game['phase'],
    'space'         => $space,
    'total_spaces'  => (int) ($game['config']['total_spaces'] ?? 14),
    'current_seat'  => $game['current_seat'],
    'max_players'   => (int) $game['max_players'],
    'winner_seat'   => $game['winner_seat'],
    'ended_reason'  => $game['ended_reason'],
    'state_version' => (int) $game['state_version'],
    'config'        => $game['config'],
    'tracks'        => $liveTracks,
    'stability'     => (int) ($state['stability'] ?? 0),
    'stability_max' => (int) ($game['config']['stability_max']
                        ?? $game['config']['stability_start'] ?? 12),
    'president'     => $state['president'] ?? null,
    'race'          => $race,
    'history'       => $state['history'] ?? [],
    'deck_count'    => count($state['deck'] ?? []),
    'players'       => $seats,
    'you'           => $you,
    'available_actions' => engine_available_actions($game, $players, $viewerSeat),
  ];
}

/**
 * Legal actions for one seat. The advisory mirror the UI renders from;
 * the server re-checks every one of them anyway.
 */
function engine_available_actions($game, $players, $seat) {
  if ($seat === null || !isset($players[$seat])) return [];
  if ($game['status'] !== 'active') return [];
  if (!empty($players[$seat]['conceded'])) return [];

  $actions = ['concede'];
  if ($game['current_seat'] === null || (int) $game['current_seat'] === (int) $seat) {
    $actions[] = 'finance';
    $actions[] = 'sway';
    $actions[] = 'transition';
  }
  return $actions;
}

// ---------------------------------------------------------------------
// Logging + export
// ---------------------------------------------------------------------

/** log_event with the round and phase filled in from the game. */
function engine_log($mysqli, $game, $seat, $type, $message = '', $data = null, $playerName = null) {
  if (!$mysqli) return;
  log_event($mysqli, (int) $game['game_id'], $seat, $type, $message, $data,
    $playerName, (int) $game['round_number'], $game['phase']);
}

/**
 * The verbatim playthrough export: summary, every seat, the final board,
 * and the COMPLETE event log with detail. Lossless by policy — add
 * fields, never trim them.
 */
function engine_build_export($mysqli, $game, $players) {
  $seats = [];
  foreach ($players as $seat => $p) {
    $seats[] = [
      'seat'            => (int) $seat,
      'player_name'     => $p['player_name'],
      'is_bot'          => (bool) $p['is_bot'],
      'conceded'        => (bool) $p['conceded'],
      'final_score'     => $p['final_score'],
      'score'           => (int) $p['score'],
      'score_breakdown' => $p['score_breakdown'],
      'public_state'    => $p['public_state'],
      'private_state'   => $p['private_state'],
    ];
  }

  return [
    'export_version' => 2,
    'exported_at'    => gmdate('c'),
    'summary' => [
      'game_id'       => (int) $game['game_id'],
      'join_code'     => $game['join_code'],
      'variant'       => $game['variant'],
      'status'        => $game['status'],
      'phase'         => $game['phase'],
      'spaces_played' => count($game['state']['history'] ?? []),
      'total_spaces'  => (int) ($game['config']['total_spaces'] ?? 14),
      'stability'     => (int) ($game['state']['stability'] ?? 0),
      'winner_seat'   => $game['winner_seat'],
      'ended_reason'  => $game['ended_reason'],
      'created_at'    => $game['created_at'],
      'ended_at'      => $game['ended_at'],
      'config'        => $game['config'],
    ],
    'elections'   => $game['state']['history'] ?? [],
    'final_board' => $game['state'],
    'players'     => $seats,
    'events'      => all_events($mysqli, (int) $game['game_id']),
  ];
}

/**
 * Write one vg_scores row per seat at game end. Separate table so
 * clearing finished games never wipes the board.
 */
function engine_record_scores($mysqli, $game, $players) {
  if ($game['status'] !== 'ended') return;
  $playersCount = count($players);
  foreach ($players as $seat => $p) {
    if (!empty($p['is_bot'])) continue;       // bots do not take the board
    $detail = json_encode($p['score_breakdown'], JSON_UNESCAPED_UNICODE);
    $won = ($game['winner_seat'] !== null && (int) $game['winner_seat'] === (int) $seat) ? 1 : 0;
    $score = (int) ($p['final_score'] ?? 0);
    $stmt = $mysqli->prepare("
      INSERT IGNORE INTO vg_scores
        (game_id, seat, player_name, variant, score, players_count, rounds, ended_reason, won, detail)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $seatVal = (int) $seat;
    $rounds  = count($game['state']['history'] ?? []);
    $stmt->bind_param(
      'iissiiisis',
      $game['game_id'], $seatVal, $p['player_name'], $game['variant'], $score,
      $playersCount, $rounds, $game['ended_reason'], $won, $detail
    );
    @$stmt->execute();
    $stmt->close();
  }
}
