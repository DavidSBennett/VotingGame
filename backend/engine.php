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
 * the end wins.
 *
 * Each round, every paper commits BLIND, all at once:
 *
 *   Choose any number of cards from your hand (at least one). For each,
 *   choose CASH (take its value, +patron_bonus if you are the Patron) or
 *   PRINT (its push goes on the track, and its value is staked as
 *   influence on the candidate you name). Mark one committed card to
 *   reserve.
 *
 * When every paper has committed, all reveal:
 *
 *   1. Every printed push is added up on a track from States -5 to Nation
 *      +5. The side it leans toward wins. At 0, the bigger total stake
 *      wins; failing that, whoever won in history.
 *   2. Stakes on the winner pay back payout_num/payout_den times. Stakes on
 *      the loser are gone.
 *   3. The largest stake on the winner makes that paper the PATRON until
 *      the next election. A tie leaves nobody Patron.
 *   4. Every paper except the new Patron takes its reserved card back into
 *      hand. Every other committed card is spent.
 *   5. Everyone draws draw_per_round cards.
 *
 * Cards are dated: each round shuffles in the events of the years since
 * the last one, so the deck opens on the Revolution and reaches Kansas in
 * the 1850s. After 1860 the richest paper wins.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/game_data.php';

/**
 * Bump when the state JSON shape changes incompatibly. A game whose state
 * carries another version cannot be played by this engine; it is shown as
 * ended instead (see engine_is_current).
 */
define('ENGINE_STATE_VERSION', 5);

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
    'engine_version'  => ENGINE_STATE_VERSION,
    'total_spaces'    => 14,
    'start_hand'      => 5,
    'draw_per_round'  => 2,
    'hand_limit'      => 10,
    'start_money'     => 12,
    // Paid on EACH card the Patron cashes, the round after winning it. The
    // lever on cash against print: at 0 a pure casher beat the bot 89%, at
    // 3 it won 15%; at 2 it wins ~40%.
    'patron_bonus'    => 2,
    // Winning stakes pay stake * payout_num / payout_den, rounded down per
    // paper. A fraction rather than 1.5 so the money stays integer.
    'payout_num'      => 3,
    'payout_den'      => 2,
    'track_min'       => -5,
    'track_max'       => 5,
    'min_commit'      => 1,
    'min_players'     => 1,
    'max_players'     => 5,
    'bots'            => 1,
  ];
}

/**
 * Host-adjustable knobs and their legal ranges. createGame.php accepts only
 * these, clamped.
 */
function engine_config_knobs() {
  return [
    'total_spaces'   => [1, 14],
    'start_hand'     => [3, 8],
    'draw_per_round' => [1, 4],
    'start_money'    => [0, 50],
    'patron_bonus'   => [0, 6],
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

  $game['state'] = [
    'engine_version' => ENGINE_STATE_VERSION,
    'space'          => 1,
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
      'prints'       => 0,
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
 * Check one commitment against the hand and normalise it.
 *
 * params: { plays: [{card, action: 'cash'|'print', side?: 'nation'|'states'}],
 *           reserve?: card }
 */
function engine_validate_commit($game, $player, $params) {
  $hand = $player['private_state']['hand'] ?? [];
  $plays = $params['plays'] ?? null;
  if (!is_array($plays)) throw new Exception('Choose the cards you are committing.');

  $min = min((int) ($game['config']['min_commit'] ?? 1), count($hand));
  if (count($plays) < $min) throw new Exception('Commit at least one card.');

  $out = [];
  $seen = [];
  foreach ($plays as $pl) {
    if (!is_array($pl)) throw new Exception('Malformed commitment.');
    $card = (string) ($pl['card'] ?? '');
    if (!in_array($card, $hand, true)) throw new Exception('A committed card is not in your hand.');
    if (isset($seen[$card])) throw new Exception('Each card can be committed once.');
    $seen[$card] = true;
    $mode = (string) ($pl['action'] ?? '');
    if ($mode === 'cash') {
      $out[] = ['card' => $card, 'action' => 'cash', 'side' => null];
    } elseif ($mode === 'print') {
      $side = (string) ($pl['side'] ?? '');
      if ($side !== 'nation' && $side !== 'states') {
        throw new Exception('Choose which candidate each printed card backs.');
      }
      $out[] = ['card' => $card, 'action' => 'print', 'side' => $side];
    } else {
      throw new Exception('Each committed card must be cashed or printed.');
    }
  }

  $reserve = isset($params['reserve']) ? (string) $params['reserve'] : null;
  if ($reserve !== null && $reserve !== '' && !isset($seen[$reserve])) {
    throw new Exception('The reserved card must be one you committed.');
  }
  if ($reserve === null || $reserve === '') {
    // Unmarked: keep back the most valuable committed card.
    foreach ($out as $pl) {
      if ($reserve === null || (int) vg_card($pl['card'])['value'] > (int) vg_card($reserve)['value']) {
        $reserve = $pl['card'];
      }
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
 * The bot. Kept in step with strat_bot in tools/simulate.py — the tuning
 * was validated against it, so change them together.
 *
 *   1. Keep four cards in hand; commit the rest (at least one).
 *   2. Patron? Cash them all: the bonus pays on every card cashed.
 *   3. Otherwise print the cards that push the way the hand leans (history
 *      breaks a tie) and cash the ones that push nobody. Cards pushing the
 *      other way stay in hand.
 *   4. Reserve the most valuable card printed.
 */
function engine_bot_commit($game, $player) {
  $hand = $player['private_state']['hand'] ?? [];
  $byValue = $hand;
  usort($byValue, function ($a, $b) { return (int) vg_card($b)['value'] - (int) vg_card($a)['value']; });
  $n = max(1, count($hand) - 4 + (int) ($game['config']['draw_per_round'] ?? 2));

  if (!empty($player['public_state']['is_patron'])) {
    $plays = [];
    foreach (array_slice($byValue, 0, $n) as $k) $plays[] = ['card' => $k, 'action' => 'cash', 'side' => null];
    return ['plays' => $plays, 'reserve' => $plays[0]['card']];
  }

  $net = 0;
  foreach ($hand as $k) $net += (int) vg_card($k)['push'];
  if ($net > 0) $side = 'nation';
  elseif ($net < 0) $side = 'states';
  else {
    $e = vg_election_at((int) $game['state']['space']);
    $side = $e ? $e['historical_winner'] : 'nation';
  }
  $want = ($side === 'nation') ? 1 : -1;

  $plays = [];
  foreach ($byValue as $k) {
    if (count($plays) >= $n) break;
    $push = (int) vg_card($k)['push'];
    if ($push * $want > 0) $plays[] = ['card' => $k, 'action' => 'print', 'side' => $side];
  }
  foreach ($byValue as $k) {
    if (count($plays) >= $n) break;
    if ((int) vg_card($k)['push'] === 0) $plays[] = ['card' => $k, 'action' => 'cash', 'side' => null];
  }
  if (!$plays) $plays[] = ['card' => $byValue[0], 'action' => 'cash', 'side' => null];

  $reserve = null;
  foreach ($plays as $pl) {
    if ($pl['action'] === 'print') { $reserve = $pl['card']; break; }   // byValue order
  }
  return ['plays' => $plays, 'reserve' => $reserve ?? $plays[0]['card']];
}

/**
 * Reveal every commitment, hold the election, and open the next round.
 */
function engine_resolve_round(&$game, &$players, $mysqli) {
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);
  if (!$election) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }
  $config = $game['config'];
  $bonus = (int) $config['patron_bonus'];
  $commits = $game['state']['commits'];

  // 1. Reveal. Cash pays now; prints push and stake.
  $track = 0;
  $stakes = ['nation' => [], 'states' => []];
  $reveal = [];
  foreach (engine_seat_list($players) as $seat) {
    if (!isset($commits[$seat])) continue;
    $p = &$players[$seat];
    $hand = $p['private_state']['hand'] ?? [];
    $wasPatron = !empty($p['public_state']['is_patron']);
    $cashed = 0;
    $shown = [];
    foreach ($commits[$seat]['plays'] as $pl) {
      $card = vg_card($pl['card']);
      $at = array_search($pl['card'], $hand, true);
      if (!$card || $at === false) continue;          // defensive: never double-spend
      array_splice($hand, $at, 1);
      $value = (int) $card['value'];
      if ($pl['action'] === 'cash') {
        $gain = $value + ($wasPatron ? $bonus : 0);
        $p['public_state']['money'] = (int) $p['public_state']['money'] + $gain;
        $cashed += $gain;
        $shown[] = ['card' => $pl['card'], 'name' => $card['name'], 'action' => 'cash',
                    'side' => null, 'value' => $gain, 'push' => 0];
      } else {
        $track += (int) $card['push'];
        $stakes[$pl['side']][$seat] = (int) ($stakes[$pl['side']][$seat] ?? 0) + $value;
        $p['public_state']['prints'] = 1 + (int) ($p['public_state']['prints'] ?? 0);
        $shown[] = ['card' => $pl['card'], 'name' => $card['name'], 'action' => 'print',
                    'side' => $pl['side'], 'value' => $value, 'push' => (int) $card['push']];
      }
      $p['public_state']['cards_played'] = 1 + (int) $p['public_state']['cards_played'];
    }
    $p['private_state']['hand'] = $hand;
    $reveal[$seat] = ['seat' => (int) $seat, 'plays' => $shown, 'cashed' => $cashed,
                      'reserve' => $commits[$seat]['reserve'], 'paid' => 0, 'kept' => null];
    unset($p);
  }
  $track = max((int) $config['track_min'], min((int) $config['track_max'], $track));

  // 2. The election.
  if ($track > 0)      { $side = 'nation'; $decidedBy = 'track'; }
  elseif ($track < 0)  { $side = 'states'; $decidedBy = 'track'; }
  else {
    $n = array_sum($stakes['nation']);
    $s = array_sum($stakes['states']);
    if ($n !== $s) { $side = ($n > $s) ? 'nation' : 'states'; $decidedBy = 'stakes'; }
    else { $side = $election['historical_winner']; $decidedBy = 'history'; }
  }
  $winner = $election[$side];
  $loser = $election[$side === 'nation' ? 'states' : 'nation'];

  $num = max(1, (int) $config['payout_num']);
  $den = max(1, (int) $config['payout_den']);
  foreach ($stakes[$side] as $s => $stake) {
    $paid = intdiv((int) $stake * $num, $den);
    $players[$s]['public_state']['money'] = (int) $players[$s]['public_state']['money'] + $paid;
    if (isset($reveal[$s])) $reveal[$s]['paid'] = $paid;
  }

  // 3. The Patron: the single largest stake on the winner.
  $patron = null;
  $best = 0;
  $tied = false;
  foreach ($stakes[$side] as $s => $stake) {
    $stake = (int) $stake;
    if ($stake > $best) { $best = $stake; $patron = (int) $s; $tied = false; }
    elseif ($stake === $best && $stake > 0) { $tied = true; }
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

  // 4. Everyone but the Patron keeps its reserved card; the rest is spent.
  foreach ($commits as $s => $c) {
    if (!isset($reveal[$s])) continue;
    foreach ($c['plays'] as $pl) {
      if ($pl['card'] === $c['reserve'] && (int) $s !== $patron && empty($players[$s]['conceded'])) {
        $players[$s]['private_state']['hand'][] = $pl['card'];
        $reveal[$s]['kept'] = vg_card($pl['card'])['name'];
      } else {
        $game['state']['discard'][] = $pl['card'];
      }
    }
  }

  // 5. Everyone draws.
  foreach ($players as $s => $p) {
    if (!empty($p['conceded'])) continue;
    engine_draw($game, $players[$s], (int) $config['draw_per_round']);
    $players[$s]['score'] = (int) $players[$s]['public_state']['money'];
    $players[$s]['public_state']['committed'] = false;
  }

  // The log: one line per paper, then the result.
  foreach ($reveal as $s => $r) {
    engine_log($mysqli, $game, $s, 'reveal',
      engine_reveal_text($players[$s]['player_name'], $r, $election), $r, $players[$s]['player_name']);
  }
  $msg = $election['year'] . ': ' . $winner['name'] . ' wins'
       . ($patronName ? ', and ' . $patronName . ' is his Patron.' : ', and no paper can claim him.');
  engine_log($mysqli, $game, null, 'election', $msg, [
    'space' => $space, 'year' => $election['year'], 'winner_side' => $side,
    'winner' => $winner['key'], 'decided_by' => $decidedBy, 'track' => $track,
    'stakes' => $stakes, 'patron_seat' => $patron,
    'historical_winner' => $election['historical_winner'],
  ]);

  $game['state']['last_reveal'] = [
    'space' => $space, 'year' => $election['year'],
    'winner_side' => $side, 'winner_name' => $winner['name'], 'loser_name' => $loser['name'],
    'decided_by' => $decidedBy, 'track' => $track,
    'stake_totals' => ['nation' => array_sum($stakes['nation']), 'states' => array_sum($stakes['states'])],
    'patron_seat' => $patron, 'patron_name' => $patronName,
    'seats' => array_values($reveal),
  ];
  $game['state']['history'][] = [
    'space' => $space, 'year' => $election['year'],
    'winner_side' => $side, 'winner' => $winner['key'], 'winner_name' => $winner['name'],
    'loser_name' => $loser['name'], 'decided_by' => $decidedBy, 'track' => $track,
    'patron_seat' => $patron, 'patron_name' => $patronName,
    'matched_history' => ($side === $election['historical_winner']),
  ];
  $game['state']['president'] = [
    'name' => $winner['name'], 'year' => $election['year'], 'side' => $side,
    'patron_seat' => $patron,
  ];

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

/** "Name cashed X (+5); printed Y and Z for Jefferson; kept Y." */
function engine_reveal_text($name, $r, $election) {
  $cash = [];
  $print = ['nation' => [], 'states' => []];
  foreach ($r['plays'] as $pl) {
    if ($pl['action'] === 'cash') $cash[] = $pl['name'];
    else $print[$pl['side']][] = $pl['name'];
  }
  $parts = [];
  if ($cash) $parts[] = 'cashed ' . implode(', ', $cash) . ' (+' . $r['cashed'] . ')';
  foreach (['nation', 'states'] as $side) {
    if ($print[$side]) $parts[] = 'printed ' . implode(', ', $print[$side]) . ' for ' . $election[$side]['name'];
  }
  $msg = $name . ' ' . implode('; ', $parts) . '.';
  if ($r['paid'] > 0) $msg .= ' Collected ' . $r['paid'] . '.';
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
    'board_completed' => 'The board is played out. It is 1860.',
    'all_humans_left' => 'The last editor walked away.',
    'rules_changed'   => 'This game was started under the old rules.',
  ];
  return $text[$reason] ?? 'The game ended.';
}

/** Final score. Money IS the score; nothing else is added. */
function engine_score_player($players, $seat) {
  $p = $players[$seat];
  $money = (int) ($p['public_state']['money'] ?? 0);
  return [
    'total' => $money,
    'breakdown' => [
      'money'        => $money,
      'patronages'   => (int) ($p['public_state']['patronages'] ?? 0),
      'prints'       => (int) ($p['public_state']['prints'] ?? 0),
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
      'prints'          => (int) ($p['public_state']['prints'] ?? 0),
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
    $bonus = !empty($me['public_state']['is_patron']) ? (int) ($config['patron_bonus'] ?? 0) : 0;
    $hand = [];
    foreach (($current ? ($me['private_state']['hand'] ?? []) : []) as $key) {
      $c = vg_card($key);
      if (!$c) continue;
      $hand[] = [
        'key' => $key, 'name' => $c['name'], 'year' => $c['year'],
        'flavor' => $c['flavor'], 'kind' => $c['kind'],
        'value' => (int) $c['value'],
        'cash_value' => (int) $c['value'] + $bonus,
        'push' => (int) $c['push'],
      ];
    }
    $you = [
      'seat' => (int) $viewerSeat,
      'hand' => $hand,
      'commit' => $current ? ($state['commits'][$viewerSeat] ?? null) : null,
    ];
  }

  $num = max(1, (int) ($config['payout_num'] ?? 3));
  $den = max(1, (int) ($config['payout_den'] ?? 2));

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
      'draw_per_round' => (int) ($config['draw_per_round'] ?? 2),
      'patron_bonus'   => (int) ($config['patron_bonus'] ?? 2),
      'payout'         => $num / $den,
      'hand_limit'     => (int) ($config['hand_limit'] ?? 10),
    ],
    'track'         => ['min' => (int) ($config['track_min'] ?? -5), 'max' => (int) ($config['track_max'] ?? 5)],
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
