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
 * You are a newspaper, 1796 to 1860. Fourteen elections, one ROUND each,
 * every race a Nation candidate against a States candidate. Most MONEY at
 * the end wins, and money comes ONLY from playing cards for profit.
 *
 * Each card has up to three stats: profit, positive coverage, negative
 * coverage (plus the stability its negative coverage costs). Each round,
 * every paper commits BLIND, all at once: any number of cards (or none,
 * to pass), each played for
 *
 *   PROFIT     its profit in money -- doubled if you are the Patron;
 *   POSITIVE   its positive push on the track, counted as that much
 *              influence on the candidate you name;
 *   NEGATIVE   its negative push the same way, and it costs the Union its
 *              stability. At most max_negative such cards a round.
 *
 * Mark one card you played for coverage to reserve. When every paper has
 * committed, all reveal:
 *
 *   1. Stability pays for every negative play. At zero the Union breaks
 *      and the game ends; the paper with the most EXPOSURE (negative plays
 *      over the whole game; ties all pay) loses exposure_penalty, and the
 *      richest paper after that wins.
 *   2. Every push is added up on a track from States -5 to Nation +5. The
 *      side it leans toward wins. Level: the bigger total influence wins;
 *      failing that, whoever won in history.
 *   3. The most influence on the winner makes that paper PATRON: its profit
 *      plays pay double next round. A tie leaves nobody Patron.
 *   4. If the winner is not the man history elected, the Union loses
 *      history_shock (per two seats), which can break it too.
 *   5. Everyone except the new Patron takes its reserved coverage card
 *      back. Every other committed card is spent. Everyone draws
 *      draw_per_round, and the Union recovers stability_recovery.
 *
 * Cards are dated: each round shuffles in the events since the last one.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/game_data.php';

/**
 * Bump when the state JSON shape changes incompatibly. A game whose state
 * carries another version cannot be played by this engine; it is shown as
 * ended instead (see engine_is_current).
 */
define('ENGINE_STATE_VERSION', 6);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/**
 * Frozen into vg_games.config at start, so a mid-series rules change never
 * rewrites a game already in progress.
 *
 * EVERY NUMBER HERE WAS CHECKED BY SIMULATION. tools/simulate.py plays the
 * game a few thousand times per setting; docs/DESIGN.md records what each
 * run found. Change a number here and in the simulator's DEFAULTS together.
 */
function engine_default_config() {
  return [
    'engine_version'     => ENGINE_STATE_VERSION,
    'total_spaces'       => 14,
    'start_hand'         => 5,
    'draw_per_round'     => 2,
    'hand_limit'         => 10,
    'start_money'        => 12,
    // The Patron's profit plays pay this many times over, the round after.
    // With the reserve limited to coverage cards, x2 makes covering worth
    // it: a pure casher wins under 4% at any table size.
    'patron_multiplier'  => 2,
    // One negative card per paper per round. Uncapped, a single paper
    // flooding negative coverage broke the Union in 80-100% of games.
    'max_negative'       => 1,
    // Per two seats, scaled to the table. At 14 / 1 careful papers never
    // break the Union, but one that plays its costliest card negatively
    // every round breaks it ~59% of the time heads-up, ~32% at three seats,
    // 0% at four or more. (At 10 / 2 recovery refunded nearly every
    // negative play and stability never moved.)
    //
    // Ceiling 10 and a history shock of 2 (both per two seats): careful
    // papers change history in about a third of races, and the Union then
    // breaks in 0% / 16% / 9% / 25% of games at 2 / 3 / 4 / 5 seats. A paper
    // that backs the unhistorical man on purpose to shake the Union while
    // ahead lost to the bot (30% heads-up). At 14 and no shock, careful play
    // never broke it and the ceiling was never felt.
    'stability_start'    => 10,
    'stability_recovery' => 1,
    'history_shock'      => 2,
    // Paid by the most exposed paper when the Union breaks. Careful papers
    // (~1.3 negative plays a game) essentially never break it; two papers
    // playing a negative every round broke it 42% of the time, and there the
    // careful third paper's win rate rose with the penalty up to 25 and not
    // beyond: 25 is the smallest penalty with the full deterrent effect,
    // about a sixth of a typical final score.
    'exposure_penalty'   => 25,
    'track_min'          => -5,
    'track_max'          => 5,
    // 0: a paper may pass a round. Passing only grows the hand (draw 2, up
    // to hand_limit); no passing line tested beat a fair share -- passing
    // until holding 6 won 19% heads-up, until a full hand then dumping 0%.
    'min_commit'         => 0,
    'min_players'        => 1,
    'max_players'        => 5,
    'bots'               => 1,
  ];
}

/**
 * Host-adjustable knobs and their legal ranges. createGame.php accepts only
 * these, clamped.
 */
function engine_config_knobs() {
  return [
    'total_spaces'       => [1, 14],
    'start_hand'         => [3, 8],
    'draw_per_round'     => [1, 4],
    'start_money'        => [0, 50],
    'patron_multiplier'  => [1, 4],
    'stability_start'    => [4, 30],
    'stability_recovery' => [0, 6],
    'exposure_penalty'   => [0, 100],
    'history_shock'      => [0, 6],
  ];
}

/** Can this engine play the stored game? */
function engine_is_current($game) {
  return is_array($game['state'] ?? null)
    && (int) ($game['state']['engine_version'] ?? 0) === ENGINE_STATE_VERSION;
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------

/**
 * Deal the opening position. Called once, inside the start transaction,
 * with the final seat list. Mutates $game and $players in place.
 */
function engine_setup(&$game, &$players, $mysqli = null) {
  $stored = is_array($game['config']) ? $game['config'] : [];
  // A table opened under an older engine carries that engine's knobs, which
  // mean something else here. Keep only the seat count it was opened with.
  if ((int) ($stored['engine_version'] ?? 0) !== ENGINE_STATE_VERSION) {
    $stored = isset($stored['bots']) ? ['bots' => (int) $stored['bots']] : [];
  }
  $config = array_merge(engine_default_config(), $stored);
  $config['engine_version'] = ENGINE_STATE_VERSION;
  $game['config'] = $config;

  $game['status']       = 'active';
  $game['phase']        = 'commit';
  $game['round_number'] = 1;
  $game['current_seat'] = null;          // simultaneous: nobody is "on turn"
  $game['winner_seat']  = null;
  $game['ended_reason'] = null;

  // The opening deck: everything that has happened by the first campaign.
  $firstElection = vg_election_at(1);
  $opening = vg_cards_released(null, (int) $firstElection['year']);   // date order
  $deck = $opening;
  shuffle($deck);

  // Stability and its recovery are expressed per two seats and scaled here.
  $seatCount = max(1, count($players));
  $stabilityMax = intdiv((int) $config['stability_start'] * $seatCount, 2);
  $game['config']['stability_max'] = $stabilityMax;

  $game['state'] = [
    'engine_version' => ENGINE_STATE_VERSION,
    'space'          => 1,
    'stability'      => $stabilityMax,
    'commits'        => [],       // seat => {plays, reserve}. HIDDEN until reveal.
    'patron_seat'    => null,
    'last_released'  => $opening,
    'last_reveal'    => null,
    'deck'           => $deck,
    'discard'        => [],
    'president'      => null,
    'history'        => [],
  ];

  foreach ($players as $seat => $p) {
    $players[$seat]['public_state'] = [
      'money'        => (int) $config['start_money'],
      'is_patron'    => false,
      'patronages'   => 0,
      'cards_played' => 0,
      'positives'    => 0,
      'negatives'    => 0,
      'hand_count'   => 0,
      'committed'    => false,
    ];
    $players[$seat]['private_state'] = ['hand' => []];
    $players[$seat]['score'] = (int) $config['start_money'];
  }

  // Deal after every seat exists, so the deck depletes in seat order.
  foreach ($players as $seat => $p) {
    engine_draw($game, $players[$seat], (int) $config['start_hand']);
  }

  if ($mysqli) engine_log_campaign($mysqli, $game);
  engine_run_bots($game, $players, $mysqli);
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

/** Draw up to $n cards, never past the hand limit. */
function engine_draw(&$game, &$player, $n) {
  $limit = (int) ($game['config']['hand_limit'] ?? 10);
  $hand = $player['private_state']['hand'] ?? [];
  for ($i = 0; $i < $n && count($hand) < $limit; $i++) {
    $card = engine_draw_one($game);
    if ($card === null) break;
    $hand[] = $card;
  }
  $player['private_state']['hand'] = $hand;
  $player['public_state']['hand_count'] = count($hand);
}

/**
 * Shuffle into the deck every card dated after $previousYear and no later
 * than the campaign now opening. Events enter the game when they happened.
 */
function engine_release_cards(&$game, $previousYear, $mysqli) {
  $e = vg_election_at((int) $game['state']['space']);
  if (!$e) return;
  $fresh = vg_cards_released($previousYear, (int) $e['year']);
  $game['state']['last_released'] = $fresh;
  if (!$fresh) return;
  foreach ($fresh as $k) $game['state']['deck'][] = $k;
  shuffle($game['state']['deck']);

  $names = [];
  foreach ($fresh as $k) $names[] = vg_card($k)['name'];
  $msg = 'News reaches the presses: ' . implode(', ', $names) . '.';
  if (mb_strlen($msg) > 480) $msg = mb_substr($msg, 0, 477) . '...';
  engine_log($mysqli, $game, null, 'cards_released', $msg,
    ['space' => (int) $game['state']['space'], 'cards' => $fresh]);
}

// ---------------------------------------------------------------------
// Seats
// ---------------------------------------------------------------------

/** Seat numbers need not be contiguous; always walk the seats that exist. */
function engine_seat_list($players) {
  $seats = array_map('intval', array_keys($players));
  sort($seats);
  return $seats;
}

/** Seats still playing that are actually people. */
function engine_human_seats($players) {
  $n = 0;
  foreach ($players as $p) {
    if (empty($p['conceded']) && empty($p['is_bot'])) $n++;
  }
  return $n;
}

/** Has every paper still playing committed this round? */
function engine_all_committed($game, $players) {
  foreach ($players as $seat => $p) {
    if (!empty($p['conceded'])) continue;
    if (!isset($game['state']['commits'][$seat])) return false;
  }
  return true;
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
  if (!engine_is_current($game)) {
    throw new Exception('This game was started under the old rules and can no longer be played. Open a new table from the lobby.');
  }
  if (!isset($players[$seat]))      throw new Exception('You are not seated in this game.');
  if (!empty($players[$seat]['conceded'])) throw new Exception('You have already left this game.');

  if ($action === 'concede') {
    return engine_concede($game, $players, $seat, $mysqli);
  }
  if ($action !== 'commit') throw new Exception('Unknown action: ' . $action);

  $commit = engine_validate_commit($game, $players[$seat], $params);
  $replacing = isset($game['state']['commits'][$seat]);
  $game['state']['commits'][$seat] = $commit;
  $players[$seat]['public_state']['committed'] = true;

  $n = count($commit['plays']);
  $msg = $players[$seat]['player_name'] . ($replacing ? ' changed its commitment.' : ' committed '
       . $n . ' ' . ($n === 1 ? 'card' : 'cards') . '.');
  engine_log($mysqli, $game, $seat, 'commit', $msg, ['cards' => $n, 'replaced' => $replacing],
    $players[$seat]['player_name']);

  engine_run_bots($game, $players, $mysqli);
  return $msg;
}

/**
 * Check one commitment against the hand and the rules, and normalise it.
 *
 * params: { plays: [{card, action: 'profit'|'positive'|'negative',
 *                    side?: 'nation'|'states'}], reserve?: card }
 */
function engine_validate_commit($game, $player, $params) {
  $hand = $player['private_state']['hand'] ?? [];
  $plays = $params['plays'] ?? null;
  if (!is_array($plays)) throw new Exception('Choose the cards you are committing.');

  $min = min((int) ($game['config']['min_commit'] ?? 0), count($hand));
  if (count($plays) < $min) throw new Exception('Commit at least ' . $min . ' card' . ($min === 1 ? '' : 's') . '.');

  $out = [];
  $seen = [];
  $negatives = 0;
  foreach ($plays as $pl) {
    if (!is_array($pl)) throw new Exception('Malformed commitment.');
    $card = (string) ($pl['card'] ?? '');
    if (!in_array($card, $hand, true)) throw new Exception('A committed card is not in your hand.');
    if (isset($seen[$card])) throw new Exception('Each card can be committed once.');
    $seen[$card] = true;
    $def = vg_card($card);
    $mode = (string) ($pl['action'] ?? '');
    if ($mode === 'profit') {
      $out[] = ['card' => $card, 'action' => 'profit', 'side' => null];
      continue;
    }
    if ($mode !== 'positive' && $mode !== 'negative') {
      throw new Exception('Each committed card is played for profit, positive or negative coverage.');
    }
    if ((int) $def[$mode] === 0) {
      throw new Exception($def['name'] . ' has no ' . $mode . ' coverage.');
    }
    $side = (string) ($pl['side'] ?? '');
    if ($side !== 'nation' && $side !== 'states') {
      throw new Exception('Name the candidate each coverage card backs.');
    }
    if ($mode === 'negative') $negatives++;
    $out[] = ['card' => $card, 'action' => $mode, 'side' => $side];
  }
  $maxNeg = (int) ($game['config']['max_negative'] ?? 1);
  if ($negatives > $maxNeg) {
    throw new Exception('You may play only ' . $maxNeg . ' card' . ($maxNeg === 1 ? '' : 's') . ' for negative coverage a round.');
  }

  $covered = [];
  foreach ($out as $pl) if ($pl['action'] !== 'profit') $covered[] = $pl['card'];
  $reserve = isset($params['reserve']) ? (string) $params['reserve'] : '';
  if ($reserve !== '' && !in_array($reserve, $covered, true)) {
    throw new Exception('Only a card you played for coverage can be reserved.');
  }
  if ($reserve === '') {
    // Unmarked: keep back the most profitable card played for coverage.
    $reserve = null;
    foreach ($covered as $k) {
      if ($reserve === null || (int) vg_card($k)['profit'] > (int) vg_card($reserve)['profit']) $reserve = $k;
    }
  }
  return ['plays' => $out, 'reserve' => $reserve];
}

/** Leave the table. The round resolves at once if everyone left has committed. */
function engine_concede(&$game, &$players, $seat, $mysqli) {
  $players[$seat]['conceded'] = 1;
  unset($game['state']['commits'][$seat]);
  $players[$seat]['public_state']['committed'] = false;
  $msg = $players[$seat]['player_name'] . ' shut down the presses.';
  engine_log($mysqli, $game, $seat, 'concede', $msg, null, $players[$seat]['player_name']);

  if (engine_human_seats($players) < 1) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return $msg;
  }
  engine_run_bots($game, $players, $mysqli);
  return $msg;
}

// ---------------------------------------------------------------------
// Bots, and resolving the round once everyone is in
// ---------------------------------------------------------------------

/**
 * Commit for every bot that has not yet, then resolve the round if every
 * paper is in. Bots decide from their own hand and the public table only —
 * never from a commitment already on file.
 */
function engine_run_bots(&$game, &$players, $mysqli) {
  // Bound: one resolution per call. A round only resolves when a person
  // has committed, so this can never run the board unattended.
  if ($game['status'] !== 'active') return;
  if (engine_human_seats($players) < 1) return;

  foreach (engine_seat_list($players) as $seat) {
    $p = $players[$seat];
    if (empty($p['is_bot']) || !empty($p['conceded'])) continue;
    if (isset($game['state']['commits'][$seat])) continue;
    if (empty($p['private_state']['hand'])) {
      $players[$seat]['conceded'] = 1;       // nothing left to print
      continue;
    }
    $game['state']['commits'][$seat] = engine_bot_commit($game, $players[$seat]);
    $players[$seat]['public_state']['committed'] = true;
  }

  if (engine_all_committed($game, $players)) {
    engine_resolve_round($game, $players, $mysqli);
    if ($game['status'] === 'active') {
      // The next round opens with the bots already in.
      foreach (engine_seat_list($players) as $seat) {
        $p = $players[$seat];
        if (empty($p['is_bot']) || !empty($p['conceded']) || empty($p['private_state']['hand'])) continue;
        $game['state']['commits'][$seat] = engine_bot_commit($game, $players[$seat]);
        $players[$seat]['public_state']['committed'] = true;
      }
    }
  }
}

/**
 * The bot. Kept in step with make_bot in tools/simulate.py (weight 1,
 * margin 4) -- the tuning was validated against it, so change them together.
 *
 *   1. Keep four cards in hand; commit the rest (at least one).
 *   2. Patron? Play them all for profit: each pays double.
 *   3. Otherwise pick the side the hand can push hardest. Cheapest card
 *      first, cover a card for that side when its push is at least its
 *      profit: positively if that pushes the right way; negatively (one card
 *      at most) only if it does and stability stays above 4 after the cost.
 *      Name that side's candidate.
 *   4. Play the rest of the commitment for profit, best first.
 *   5. Reserve the most profitable card it covered.
 */
function engine_bot_commit($game, $player) {
  $hand = $player['private_state']['hand'] ?? [];
  $n = max(1, count($hand) - 4 + (int) ($game['config']['draw_per_round'] ?? 2));
  $byProfit = $hand;
  usort($byProfit, function ($a, $b) { return (int) vg_card($b)['profit'] - (int) vg_card($a)['profit']; });

  if (!empty($player['public_state']['is_patron'])) {
    $plays = [];
    foreach (array_slice($byProfit, 0, $n) as $k) $plays[] = ['card' => $k, 'action' => 'profit', 'side' => null];
    return ['plays' => $plays, 'reserve' => null];
  }

  $stability = (int) ($game['state']['stability'] ?? 0);
  $margin = 4;
  $maxNeg = (int) ($game['config']['max_negative'] ?? 1);

  // The side this hand can push hardest (negative options included).
  $reach = ['nation' => 0, 'states' => 0];
  foreach ($hand as $k) {
    $c = vg_card($k);
    foreach (['nation' => 1, 'states' => -1] as $side => $want) {
      $best = 0;
      if ((int) $c['positive'] * $want > 0) $best = max($best, abs((int) $c['positive']));
      if ((int) $c['negative'] * $want > 0) $best = max($best, abs((int) $c['negative']));
      $reach[$side] += $best;
    }
  }
  if ($reach['nation'] !== $reach['states']) {
    $side = ($reach['nation'] > $reach['states']) ? 'nation' : 'states';
  } else {
    $e = vg_election_at((int) $game['state']['space']);
    $side = $e ? $e['historical_winner'] : 'nation';
  }
  $want = ($side === 'nation') ? 1 : -1;

  $cheapest = array_reverse($byProfit);
  $plays = [];
  $used = [];
  $budget = $stability;
  $negs = 0;
  foreach ($cheapest as $k) {
    if (count($plays) >= $n) break;
    $c = vg_card($k);
    $options = [];
    if ((int) $c['positive'] * $want > 0) $options[] = [abs((int) $c['positive']), 'positive'];
    if ($negs < $maxNeg && $budget - (int) $c['stability'] > $margin && (int) $c['negative'] * $want > 0) {
      $options[] = [abs((int) $c['negative']), 'negative'];
    }
    if (!$options) continue;
    usort($options, function ($a, $b) { return $b[0] - $a[0]; });
    list($push, $mode) = $options[0];
    if ($push < (int) $c['profit']) continue;
    $plays[] = ['card' => $k, 'action' => $mode, 'side' => $side];
    $used[$k] = true;
    if ($mode === 'negative') { $budget -= (int) $c['stability']; $negs++; }
  }
  foreach ($byProfit as $k) {
    if (count($plays) >= $n) break;
    if (isset($used[$k])) continue;
    $plays[] = ['card' => $k, 'action' => 'profit', 'side' => null];
  }

  $reserve = null;
  foreach ($plays as $pl) {
    if ($pl['action'] === 'profit') continue;
    if ($reserve === null || (int) vg_card($pl['card'])['profit'] > (int) vg_card($reserve)['profit']) $reserve = $pl['card'];
  }
  return ['plays' => $plays, 'reserve' => $reserve];
}

/**
 * Reveal every commitment, charge the Union, hold the election, and open
 * the next round.
 */
function engine_resolve_round(&$game, &$players, $mysqli) {
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);
  if (!$election) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }
  $config = $game['config'];
  $mult = max(1, (int) ($config['patron_multiplier'] ?? 2));
  $commits = $game['state']['commits'];

  // 1. Reveal. Profit pays; coverage pushes and counts influence on the
  //    named candidate; negative coverage costs the Union.
  $track = 0;
  $influence = ['nation' => [], 'states' => []];
  $spent = 0;
  $reveal = [];
  foreach (engine_seat_list($players) as $seat) {
    if (!isset($commits[$seat])) continue;
    $p = &$players[$seat];
    $hand = $p['private_state']['hand'] ?? [];
    $wasPatron = !empty($p['public_state']['is_patron']);
    $earned = 0;
    $shown = [];
    foreach ($commits[$seat]['plays'] as $pl) {
      $card = vg_card($pl['card']);
      $at = array_search($pl['card'], $hand, true);
      if (!$card || $at === false) continue;          // defensive: never double-spend
      array_splice($hand, $at, 1);
      if ($pl['action'] === 'profit') {
        $gain = (int) $card['profit'] * ($wasPatron ? $mult : 1);
        $p['public_state']['money'] = (int) $p['public_state']['money'] + $gain;
        $earned += $gain;
        $shown[] = ['card' => $pl['card'], 'name' => $card['name'], 'action' => 'profit',
                    'side' => null, 'money' => $gain, 'push' => 0, 'stability' => 0];
      } else {
        $push = (int) $card[$pl['action']];
        $cost = ($pl['action'] === 'negative') ? (int) $card['stability'] : 0;
        $track += $push;
        $influence[$pl['side']][$seat] = (int) ($influence[$pl['side']][$seat] ?? 0) + abs($push);
        $spent += $cost;
        $key = ($pl['action'] === 'negative') ? 'negatives' : 'positives';
        $p['public_state'][$key] = 1 + (int) ($p['public_state'][$key] ?? 0);
        $shown[] = ['card' => $pl['card'], 'name' => $card['name'], 'action' => $pl['action'],
                    'side' => $pl['side'], 'money' => 0, 'push' => $push, 'stability' => $cost];
      }
      $p['public_state']['cards_played'] = 1 + (int) $p['public_state']['cards_played'];
    }
    $p['private_state']['hand'] = $hand;
    $reveal[$seat] = ['seat' => (int) $seat, 'plays' => $shown, 'earned' => $earned,
                      'influence' => ['nation' => (int) ($influence['nation'][$seat] ?? 0),
                                      'states' => (int) ($influence['states'][$seat] ?? 0)],
                      'reserve' => $commits[$seat]['reserve'], 'kept' => null];
    unset($p);
  }
  $track = max((int) $config['track_min'], min((int) $config['track_max'], $track));
  $before = (int) $game['state']['stability'];
  $game['state']['stability'] = max(0, $before - $spent);


  // The Union breaks: the game ends here, and the most exposed paper pays.
  if ($game['state']['stability'] <= 0) {
    foreach ($reveal as $s => $r) {
      engine_log($mysqli, $game, $s, 'reveal',
        engine_reveal_text($players[$s]['player_name'], $r, $election), $r, $players[$s]['player_name']);
    }
    list($blamed, $penalty) = engine_charge_exposure($game, $players, $mysqli);
    $game['state']['last_reveal'] = [
      'space' => $space, 'year' => $election['year'], 'broke' => true, 'broke_by' => 'coverage',
      'track' => $track, 'stability_spent' => $spent,
      'stability_before' => $before, 'stability_after' => 0,
      'blamed' => $blamed, 'penalty' => $penalty,
      'seats' => array_values($reveal),
    ];
    $game['state']['commits'] = [];
    engine_end_game($game, $players, 'the_union_breaks', $mysqli);
    return;
  }

  // 2. The election.
  if ($track > 0)      { $side = 'nation'; $decidedBy = 'track'; }
  elseif ($track < 0)  { $side = 'states'; $decidedBy = 'track'; }
  else {
    $n = array_sum($influence['nation']);
    $st = array_sum($influence['states']);
    if ($n !== $st) { $side = ($n > $st) ? 'nation' : 'states'; $decidedBy = 'influence'; }
    else { $side = $election['historical_winner']; $decidedBy = 'history'; }
  }
  $winner = $election[$side];
  $loser = $election[$side === 'nation' ? 'states' : 'nation'];

  // 3. The Patron: the single largest influence on the winner.
  $patron = null;
  $best = 0;
  $tied = false;
  foreach ($influence[$side] as $s => $inf) {
    $inf = (int) $inf;
    if ($inf > $best) { $best = $inf; $patron = (int) $s; $tied = false; }
    elseif ($inf === $best && $inf > 0) { $tied = true; }
  }
  if ($tied || $best <= 0) $patron = null;
  foreach ($players as $s => $p) {
    $is = ($patron !== null && (int) $s === $patron);
    $players[$s]['public_state']['is_patron'] = $is;
    if ($is) {
      $players[$s]['public_state']['patronages'] = 1 + (int) ($players[$s]['public_state']['patronages'] ?? 0);
    }
  }
  $game['state']['patron_seat'] = $patron;
  $patronName = ($patron !== null) ? $players[$patron]['player_name'] : null;

  // 4. Everyone but the Patron keeps its reserved coverage card; the rest is
  //    spent. (A reserve that could be a profit card let a paper cash its
  //    best card every round and made avoiding the Patronage the best line.)
  foreach ($commits as $s => $c) {
    if (!isset($reveal[$s])) continue;
    foreach ($c['plays'] as $pl) {
      $keep = $pl['card'] === $c['reserve'] && $pl['action'] !== 'profit'
           && (int) $s !== $patron && empty($players[$s]['conceded']);
      if ($keep) {
        $players[$s]['private_state']['hand'][] = $pl['card'];
        $reveal[$s]['kept'] = vg_card($pl['card'])['name'];
      } else {
        $game['state']['discard'][] = $pl['card'];
      }
    }
  }
  foreach ($players as $s => $p) {
    if (!empty($p['conceded'])) continue;
    engine_draw($game, $players[$s], (int) $config['draw_per_round']);
    $players[$s]['score'] = (int) $players[$s]['public_state']['money'];
    $players[$s]['public_state']['committed'] = false;
  }

  foreach ($reveal as $s => $r) {
    engine_log($mysqli, $game, $s, 'reveal',
      engine_reveal_text($players[$s]['player_name'], $r, $election), $r, $players[$s]['player_name']);
  }
  $msg = $election['year'] . ': ' . $winner['name'] . ' wins'
       . ($patronName ? ', and ' . $patronName . ' is his Patron.' : ', and no paper can claim him.');
  engine_log($mysqli, $game, null, 'election', $msg, [
    'space' => $space, 'year' => $election['year'], 'winner_side' => $side,
    'winner' => $winner['key'], 'decided_by' => $decidedBy, 'track' => $track,
    'influence' => engine_seat_amounts($influence), 'patron_seat' => $patron,
    'stability_spent' => $spent, 'stability' => (int) $game['state']['stability'],
    'historical_winner' => $election['historical_winner'],
  ]);

  // 5. History bends: a winner the country did not historically elect
  //    shakes the Union. Charged before recovery, so recovery cannot simply
  //    refund it; a break here ends the game like any other.
  $shock = 0;
  if ($side !== $election['historical_winner']) {
    $shock = intdiv((int) ($config['history_shock'] ?? 0) * max(1, count($players)), 2);
    $game['state']['stability'] = max(0, (int) $game['state']['stability'] - $shock);
    if ($shock > 0) {
      engine_log($mysqli, $game, null, 'history_shock',
        $winner['name'] . ' was never meant to win ' . $election['year'] . '. The Union shudders (-' . $shock . ').',
        ['shock' => $shock, 'stability' => (int) $game['state']['stability']]);
    }
  }
  $brokeByHistory = ((int) $game['state']['stability'] <= 0);
  $blamed = [];
  $penalty = 0;
  if ($brokeByHistory) {
    list($blamed, $penalty) = engine_charge_exposure($game, $players, $mysqli);
  }

  // 6. The Union settles a little.
  $after = (int) $game['state']['stability'];
  if (!$brokeByHistory) {
    $recover = intdiv((int) ($config['stability_recovery'] ?? 0) * max(1, count($players)), 2);
    $max = (int) ($config['stability_max'] ?? $before);
    $game['state']['stability'] = min($max, $after + $recover);
  }

  $game['state']['last_reveal'] = [
    'space' => $space, 'year' => $election['year'], 'broke' => $brokeByHistory,
    'broke_by' => $brokeByHistory ? 'history' : null,
    'history_shock' => $shock, 'blamed' => $blamed, 'penalty' => $penalty,
    'winner_side' => $side, 'winner_name' => $winner['name'], 'loser_name' => $loser['name'],
    'decided_by' => $decidedBy, 'track' => $track,
    'influence_totals' => ['nation' => array_sum($influence['nation']), 'states' => array_sum($influence['states'])],
    'stability_spent' => $spent, 'stability_before' => $before, 'stability_after' => $after,
    'stability_recovered' => (int) $game['state']['stability'] - $after,
    'patron_seat' => $patron, 'patron_name' => $patronName,
    'seats' => array_values($reveal),
  ];
  $game['state']['history'][] = [
    'space' => $space, 'year' => $election['year'],
    'winner_side' => $side, 'winner' => $winner['key'], 'winner_name' => $winner['name'],
    'loser_name' => $loser['name'], 'decided_by' => $decidedBy, 'track' => $track,
    'patron_seat' => $patron, 'patron_name' => $patronName,
    'stability' => (int) $game['state']['stability'],
    'matched_history' => ($side === $election['historical_winner']),
  ];
  $game['state']['president'] = [
    'name' => $winner['name'], 'year' => $election['year'], 'side' => $side,
    'patron_seat' => $patron,
  ];

  if ($brokeByHistory) {
    $game['state']['commits'] = [];
    engine_end_game($game, $players, 'the_union_breaks', $mysqli);
    return;
  }

  // Open the next round.
  $game['state']['commits'] = [];
  $game['state']['space'] = $space + 1;
  $game['round_number'] = $space + 1;
  if ($game['state']['space'] > (int) $config['total_spaces']) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }
  engine_log_campaign($mysqli, $game);
  engine_release_cards($game, (int) $election['year'], $mysqli);
}

/**
 * {side: {seat: n}} as {side: [{seat, amount}]}: PHP writes a seat-0-only
 * map as a JSON list, which made exported logs ambiguous.
 */
function engine_seat_amounts($bySide) {
  $out = [];
  foreach ($bySide as $side => $seats) {
    $out[$side] = [];
    foreach ($seats as $s => $n) $out[$side][] = ['seat' => (int) $s, 'amount' => (int) $n];
  }
  return $out;
}

/** "Name played X for profit (+5); covered Y for Jefferson; kept Y." */
function engine_reveal_text($name, $r, $election) {
  $profit = [];
  $cover = [];
  $neg = [];
  foreach ($r['plays'] as $pl) {
    if ($pl['action'] === 'profit') { $profit[] = $pl['name']; continue; }
    $who = $election[$pl['side']]['name'];
    $txt = $pl['name'] . ' for ' . $who;
    if ($pl['action'] === 'negative') $neg[] = $txt; else $cover[] = $txt;
  }
  $parts = [];
  if ($profit) $parts[] = 'played ' . implode(', ', $profit) . ' for profit (+' . $r['earned'] . ')';
  if ($cover) $parts[] = 'ran favourable coverage of ' . implode(', ', $cover);
  if ($neg) $parts[] = 'ran hostile coverage of ' . implode(', ', $neg);
  $msg = $name . ' ' . implode('; ', $parts) . '.';
  if ($r['kept']) $msg .= ' Kept ' . $r['kept'] . '.';
  if (mb_strlen($msg) > 480) $msg = mb_substr($msg, 0, 477) . '...';
  return $msg;
}

function engine_log_campaign($mysqli, $game) {
  $e = vg_election_at((int) $game['state']['space']);
  if (!$e || !$mysqli) return;
  engine_log($mysqli, $game, null, 'campaign_begins',
    'The campaign of ' . $e['year'] . ' opens: ' .
    $e['nation']['name'] . ' against ' . $e['states']['name'] . '.',
    ['space' => (int) $game['state']['space'], 'year' => $e['year']]);
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

  // Money is the score however the game ends; a broken Union has already
  // charged the most exposed paper before we get here.
  foreach ($players as $seat => $p) {
    $result = engine_score_player($players, $seat);
    $players[$seat]['final_score']     = (int) $result['total'];
    $players[$seat]['score']           = (int) $result['total'];
    $players[$seat]['score_breakdown'] = $result['breakdown'];
  }
  $best = null;
  foreach ($players as $seat => $p) {
    if (!empty($p['conceded'])) continue;
    if ($best === null || (int) $players[$seat]['final_score'] > (int) $players[$best]['final_score']) {
      $best = (int) $seat;
    }
  }
  $game['winner_seat'] = $best;

  engine_log($mysqli, $game, null, 'game_ended',
    engine_ended_text($reason) .
    ($best !== null ? ' ' . $players[$best]['player_name'] . ' ends richest.' : ''),
    ['reason' => $reason, 'winner_seat' => $best,
     'spaces_played' => count($game['state']['history'] ?? [])]);
}

function engine_ended_text($reason) {
  $text = [
    'board_completed'  => 'The board is played out. It is 1860.',
    'the_union_breaks' => 'The Union breaks. The most exposed paper pays for it.',
    'all_humans_left'  => 'The last editor walked away.',
    'rules_changed'    => 'This game was started under the old rules.',
  ];
  return $text[$reason] ?? 'The game ended.';
}

/**
 * The Union has broken: charge the most exposed paper(s) and log it.
 *
 * @return array [blamed seats, penalty]
 */
function engine_charge_exposure(&$game, &$players, $mysqli) {
  $penalty = (int) ($game['config']['exposure_penalty'] ?? 25);
  $blamed = engine_most_exposed($players);
  $names = [];
  foreach ($blamed as $s) {
    $players[$s]['public_state']['money'] = (int) $players[$s]['public_state']['money'] - $penalty;
    $players[$s]['public_state']['exposure_penalty'] = $penalty;
    $names[] = $players[$s]['player_name'];
  }
  if ($blamed) {
    engine_log($mysqli, $game, null, 'exposure_penalty',
      'The Union breaks. ' . implode(' and ', $names)
      . (count($names) === 1 ? ', the most exposed paper, loses ' : ', the most exposed papers, each lose ')
      . $penalty . '.', ['seats' => $blamed, 'penalty' => $penalty]);
  }
  return [$blamed, $penalty];
}

/**
 * Seats with the most exposure (negative plays over the game), ties
 * included, or none if nobody has any. Conceded seats count: leaving does
 * not wash your hands of what you printed.
 */
function engine_most_exposed($players) {
  $top = 0;
  foreach ($players as $p) $top = max($top, (int) ($p['public_state']['negatives'] ?? 0));
  if ($top === 0) return [];
  $out = [];
  foreach (engine_seat_list($players) as $s) {
    if ((int) ($players[$s]['public_state']['negatives'] ?? 0) === $top) $out[] = $s;
  }
  return $out;
}

/**
 * Exposure rank per seat: 1 = most exposed. Seats with equal exposure share
 * a rank; the next distinct count takes the next rank.
 */
function engine_exposure_ranks($players) {
  $counts = [];
  foreach ($players as $s => $p) $counts[(int) $s] = (int) ($p['public_state']['negatives'] ?? 0);
  $distinct = array_values(array_unique($counts));
  rsort($distinct);
  $ranks = [];
  foreach ($counts as $s => $n) $ranks[$s] = array_search($n, $distinct, true) + 1;
  return $ranks;
}

/** Final score. Money IS the score, after any exposure penalty. */
function engine_score_player($players, $seat) {
  $p = $players[$seat];
  $money = (int) ($p['public_state']['money'] ?? 0);
  return [
    'total' => $money,
    'breakdown' => [
      'money'            => $money,
      'exposure'         => (int) ($p['public_state']['negatives'] ?? 0),
      'exposure_penalty' => (int) ($p['public_state']['exposure_penalty'] ?? 0),
      'patronages'   => (int) ($p['public_state']['patronages'] ?? 0),
      'positives'    => (int) ($p['public_state']['positives'] ?? 0),
      'negatives'    => (int) ($p['public_state']['negatives'] ?? 0),
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
 * other seats get a COUNT. Commitments are private until the reveal —
 * other seats see only WHETHER a paper has committed, never what.
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $current = engine_is_current($game);
  $status = $game['status'];
  $endedReason = $game['ended_reason'];
  if ($status === 'active' && !$current) {
    $status = 'ended';
    $endedReason = 'rules_changed';
  }

  $state = $current ? $game['state'] : [];
  $config = $game['config'];

  $ranks = engine_exposure_ranks($players);
  $seats = [];
  foreach ($players as $seat => $p) {
    $seats[] = [
      'seat'            => (int) $seat,
      'player_name'     => $p['player_name'],
      'is_bot'          => (bool) $p['is_bot'],
      'conceded'        => (bool) $p['conceded'],
      'money'           => (int) ($p['public_state']['money'] ?? 0),
      'is_patron'       => (bool) ($p['public_state']['is_patron'] ?? false),
      'patronages'      => (int) ($p['public_state']['patronages'] ?? 0),
      'positives'       => (int) ($p['public_state']['positives'] ?? 0),
      'negatives'       => (int) ($p['public_state']['negatives'] ?? 0),
      'exposure'        => (int) ($p['public_state']['negatives'] ?? 0),
      'exposure_rank'   => $ranks[(int) $seat] ?? null,
      'exposure_penalty'=> (int) ($p['public_state']['exposure_penalty'] ?? 0),
      'hand_count'      => (int) ($p['public_state']['hand_count'] ?? 0),
      'committed'       => $current && isset($state['commits'][$seat]),
      'score'           => (int) $p['score'],
      'final_score'     => $p['final_score'],
      'score_breakdown' => ($status === 'ended') ? $p['score_breakdown'] : null,
      'is_you'          => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
    ];
  }

  $space = (int) ($state['space'] ?? 1);
  $election = $current ? vg_election_at($space) : null;

  $race = null;
  if ($election && $status === 'active') {
    $cands = [];
    foreach (['nation', 'states'] as $side) {
      $c = $election[$side];
      $cands[$side] = ['key' => $c['key'], 'name' => $c['name'], 'party' => $c['party'],
                       'note' => $c['note'], 'side' => $side];
    }
    $race = [
      'space' => $space, 'year' => $election['year'], 'note' => $election['note'],
      'nation' => $cands['nation'], 'states' => $cands['states'],
      'historical_winner' => $election['historical_winner'],
    ];
  }

  $you = null;
  if ($viewerSeat !== null && isset($players[$viewerSeat])) {
    $me = $players[$viewerSeat];
    $mult = !empty($me['public_state']['is_patron']) ? max(1, (int) ($config['patron_multiplier'] ?? 2)) : 1;
    $hand = [];
    foreach (($current ? ($me['private_state']['hand'] ?? []) : []) as $key) {
      $c = vg_card($key);
      if (!$c) continue;
      $hand[] = [
        'key' => $key, 'name' => $c['name'], 'year' => $c['year'],
        'flavor' => $c['flavor'], 'kind' => $c['kind'],
        'profit' => (int) $c['profit'],
        'profit_value' => (int) $c['profit'] * $mult,
        'positive' => (int) $c['positive'],
        'negative' => (int) $c['negative'],
        'stability' => (int) $c['stability'],
      ];
    }
    $you = [
      'seat' => (int) $viewerSeat,
      'hand' => $hand,
      'commit' => $current ? ($state['commits'][$viewerSeat] ?? null) : null,
    ];
  }

  return [
    'game_id'       => (int) $game['game_id'],
    'join_code'     => $game['join_code'],
    'status'        => $status,
    'variant'       => $game['variant'],
    'phase'         => $game['phase'],
    'space'         => $space,
    'total_spaces'  => (int) ($config['total_spaces'] ?? 14),
    'current_seat'  => null,
    'max_players'   => (int) $game['max_players'],
    'winner_seat'   => $game['winner_seat'],
    'ended_reason'  => $endedReason,
    'ended_text'    => $endedReason ? engine_ended_text($endedReason) : null,
    'state_version' => (int) $game['state_version'],
    'rules'         => [
      'draw_per_round'    => (int) ($config['draw_per_round'] ?? 2),
      'patron_multiplier' => (int) ($config['patron_multiplier'] ?? 2),
      'max_negative'      => (int) ($config['max_negative'] ?? 1),
      'hand_limit'        => (int) ($config['hand_limit'] ?? 10),
      'stability_recovery'=> intdiv((int) ($config['stability_recovery'] ?? 2) * max(1, count($players)), 2),
      'exposure_penalty'  => (int) ($config['exposure_penalty'] ?? 25),
      'history_shock'     => intdiv((int) ($config['history_shock'] ?? 0) * max(1, count($players)), 2),
    ],
    'track'         => ['min' => (int) ($config['track_min'] ?? -5), 'max' => (int) ($config['track_max'] ?? 5)],
    'stability'     => (int) ($state['stability'] ?? 0),
    'stability_max' => (int) ($config['stability_max'] ?? 0),
    'patron_seat'   => $state['patron_seat'] ?? null,
    'president'     => $state['president'] ?? null,
    'race'          => $race,
    'last_reveal'   => $state['last_reveal'] ?? null,
    'news'          => array_values(array_filter(array_map(function ($k) {
                         $c = vg_card($k);
                         return $c ? ['key' => $k, 'name' => $c['name'], 'year' => (int) $c['year'],
                                      'kind' => $c['kind']] : null;
                       }, $state['last_released'] ?? []))),
    'history'       => $state['history'] ?? [],
    'deck_count'    => count($state['deck'] ?? []),
    'players'       => $seats,
    'you'           => $you,
    'available_actions' => engine_available_actions($game, $players, $viewerSeat),
  ];
}

/** Legal actions for one seat: the advisory mirror the UI renders from. */
function engine_available_actions($game, $players, $seat) {
  if ($seat === null || !isset($players[$seat])) return [];
  if ($game['status'] !== 'active' || !engine_is_current($game)) return [];
  if (!empty($players[$seat]['conceded'])) return [];
  return ['commit', 'concede'];
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
 *
 * $viewerSeat: the seated player asking, or null for operator access.
 * While a game is still running a player gets only their OWN hand and
 * commitment, and no draw order.
 */
function engine_build_export($mysqli, $game, $players, $viewerSeat = null) {
  $hideOthers = ($viewerSeat !== null && $game['status'] !== 'ended');
  $seats = [];
  foreach ($players as $seat => $p) {
    $private = ($hideOthers && (int) $seat !== (int) $viewerSeat) ? null : $p['private_state'];
    $seats[] = [
      'seat'            => (int) $seat,
      'player_name'     => $p['player_name'],
      'is_bot'          => (bool) $p['is_bot'],
      'conceded'        => (bool) $p['conceded'],
      'final_score'     => $p['final_score'],
      'score'           => (int) $p['score'],
      'score_breakdown' => $p['score_breakdown'],
      'public_state'    => $p['public_state'],
      'private_state'   => $private,
    ];
  }

  $board = $game['state'];
  if ($hideOthers && is_array($board)) {
    $board['deck'] = count($board['deck'] ?? []);
    $mine = $board['commits'][$viewerSeat] ?? null;
    $board['commits'] = ($mine === null) ? [] : [$viewerSeat => $mine];
  }

  return [
    'export_version' => 4,
    'exported_at'    => gmdate('c'),
    'summary' => [
      'game_id'       => (int) $game['game_id'],
      'join_code'     => $game['join_code'],
      'variant'       => $game['variant'],
      'status'        => $game['status'],
      'phase'         => $game['phase'],
      'spaces_played' => count($game['state']['history'] ?? []),
      'total_spaces'  => (int) ($game['config']['total_spaces'] ?? 14),
      'winner_seat'   => $game['winner_seat'],
      'ended_reason'  => $game['ended_reason'],
      'created_at'    => $game['created_at'],
      'ended_at'      => $game['ended_at'],
      'config'        => $game['config'],
    ],
    'elections'   => $game['state']['history'] ?? [],
    'final_board' => $board,
    'players'     => $seats,
    'events'      => all_events($mysqli, (int) $game['game_id']),
  ];
}

/**
 * Write one vg_scores row per human seat at game end. Separate table so
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
