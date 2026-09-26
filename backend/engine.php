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
 * You are a newspaper, 1796 to 1860. Fourteen presidential races, each a
 * Nation candidate against a States candidate. Most MONEY at the end wins.
 *
 * One track, Nation (+5) to States (-5), back to 0 at every election.
 *
 * Each turn you play one card, one of two ways, then draw back to five:
 *
 *   CASH    take the card value, +patron_bonus if you are the Patron.
 *   PRINT   move the track by the card push (its direction is fixed by
 *           history, not by you), and stake the card value on EITHER
 *           candidate.
 *
 * When every seat still playing has had turns_per_space turns:
 *
 *   1. The side the track leans toward wins. At 0, the bigger total stake
 *      wins; failing that, whoever won in history.
 *   2. Stakes on the winner pay back payout_num/payout_den times. Stakes on
 *      the loser are gone.
 *   3. The single largest stake on the winner makes that seat the Patron
 *      until the next election. A tie leaves nobody Patron.
 *
 * In 1848 the crisis cards join the deck. After 1860 the richest wins.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/game_data.php';

/**
 * Bump when the state JSON shape changes incompatibly. A game whose state
 * carries another version cannot be played by this engine; it is shown as
 * ended instead (see engine_is_current).
 */
define('ENGINE_STATE_VERSION', 3);

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
    'turns_per_space' => 2,
    'hand_size'       => 5,
    'start_money'     => 12,
    'patron_bonus'    => 2,
    // Winning stakes pay stake * payout_num / payout_den, rounded down per
    // seat. A fraction rather than 1.5 so the money stays integer.
    'payout_num'      => 3,
    'payout_den'      => 2,
    'track_min'       => -5,
    'track_max'       => 5,
    'crisis_space'    => 11,     // 1848
    'min_players'     => 1,
    'max_players'     => 5,
    'bots'            => 1,
  ];
}

/**
 * Host-adjustable knobs and their legal ranges. createGame.php accepts only
 * these, clamped, so a request cannot set turns_per_space to 0 and resolve
 * every election after a single card.
 */
function engine_config_knobs() {
  return [
    'total_spaces'    => [1, 14],
    'turns_per_space' => [1, 4],
    'hand_size'       => [2, 8],
    'start_money'     => [0, 50],
    'patron_bonus'    => [0, 6],
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
  $game['phase']        = 'campaign';
  $game['round_number'] = 1;
  $game['winner_seat']  = null;
  $game['ended_reason'] = null;

  $deck = vg_cards_in_era('early');
  shuffle($deck);

  $first = engine_first_seat($players);
  $game['current_seat'] = $first;

  $game['state'] = [
    'engine_version'         => ENGINE_STATE_VERSION,
    'space'                  => 1,
    'track'                  => 0,
    'stakes'                 => ['nation' => [], 'states' => []],
    'patron_seat'            => null,
    'crisis'                 => false,
    'deck'                   => $deck,
    'discard'                => [],
    'turns_taken_this_space' => 0,
    'start_seat'             => $first,
    'president'              => null,
    'history'                => [],
  ];

  foreach ($players as $seat => $p) {
    $players[$seat]['public_state'] = [
      'money'        => (int) $config['start_money'],
      'is_patron'    => false,
      'patronages'   => 0,
      'cards_played' => 0,
      'prints'       => 0,
      'hand_count'   => 0,
    ];
    $players[$seat]['private_state'] = ['hand' => []];
    $players[$seat]['score'] = (int) $config['start_money'];
  }

  // Deal after every seat exists, so the deck depletes in seat order.
  foreach ($players as $seat => $p) {
    engine_draw_up($game, $players[$seat], (int) $config['hand_size']);
  }

  if ($mysqli) {
    engine_log_campaign($mysqli, $game);
  }
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
// Track and race helpers
// ---------------------------------------------------------------------

/** Where the track would sit after printing this card. */
function engine_track_after($game, $cardKey) {
  $card = vg_card($cardKey);
  $push = $card ? (int) $card['push'] : 0;
  return max((int) $game['config']['track_min'],
             min((int) $game['config']['track_max'], (int) $game['state']['track'] + $push));
}

/** Total stake on one side. */
function engine_stake_total($game, $side) {
  $total = 0;
  foreach (($game['state']['stakes'][$side] ?? []) as $amount) $total += (int) $amount;
  return $total;
}

/**
 * Which side would win if the election were held now, and why.
 *
 * @return array [side, decided_by]
 */
function engine_leading_side($game, $track = null) {
  $t = ($track === null) ? (int) $game['state']['track'] : (int) $track;
  if ($t > 0) return ['nation', 'track'];
  if ($t < 0) return ['states', 'track'];
  $n = engine_stake_total($game, 'nation');
  $s = engine_stake_total($game, 'states');
  if ($n !== $s) return [($n > $s) ? 'nation' : 'states', 'stakes'];
  $e = vg_election_at((int) $game['state']['space']);
  return [$e ? $e['historical_winner'] : 'nation', 'history'];
}

function engine_other_side($side) {
  return $side === 'nation' ? 'states' : 'nation';
}

// ---------------------------------------------------------------------
// Seats and turn order
// ---------------------------------------------------------------------

/**
 * Seat numbers need not be contiguous: bots take the last seats when a
 * table is opened, and the host may start before every human seat fills.
 * Turn order therefore walks the seats that EXIST, in order, never
 * `(seat + 1) % count` — which silently skipped the bot in seat 3 of a
 * table seated 0, 1, 3.
 */
function engine_seat_list($players) {
  $seats = array_map('intval', array_keys($players));
  sort($seats);
  return $seats;
}

/** The seat after $seat in table order, optionally skipping conceded seats. */
function engine_seat_after($players, $seat, $skipConceded = true) {
  $seats = engine_seat_list($players);
  $n = count($seats);
  if ($n === 0) return null;
  $at = array_search((int) $seat, $seats, true);
  if ($at === false) $at = -1;
  for ($step = 1; $step <= $n; $step++) {
    $next = $seats[($at + $step) % $n];
    if (!$skipConceded || empty($players[$next]['conceded'])) return $next;
  }
  return null;
}

/** The lowest seat still playing. */
function engine_first_seat($players) {
  foreach (engine_seat_list($players) as $s) {
    if (empty($players[$s]['conceded'])) return $s;
  }
  return null;
}

/** Seats still playing, bots included. */
function engine_active_seats($players) {
  $n = 0;
  foreach ($players as $p) if (empty($p['conceded'])) $n++;
  return $n;
}

/**
 * Seats still playing that are actually people. A game whose only human
 * has conceded is over, however many rival papers would print on.
 */
function engine_human_seats($players) {
  $n = 0;
  foreach ($players as $p) {
    if (empty($p['conceded']) && empty($p['is_bot'])) $n++;
  }
  return $n;
}

/** Turns this election needs before it resolves. */
function engine_turns_needed($game, $players) {
  return engine_active_seats($players) * (int) $game['config']['turns_per_space'];
}

/** Reject an out-of-turn action. */
function engine_require_turn($game, $seat) {
  if ($game['current_seat'] === null) return;
  if ((int) $game['current_seat'] !== (int) $seat) {
    throw new Exception('It is not your turn.');
  }
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

  engine_require_turn($game, $seat);
  $msg = engine_play_card($game, $players, $seat, $action, $params, $mysqli);
  engine_end_turn($game, $players, $mysqli);
  return $msg;
}

/**
 * Leave the table. Conceding on your own turn passes it on; conceding on
 * someone else's turn leaves that turn alone. (The first engine ended the
 * current turn either way, so a player leaving could cost a rival a move.)
 */
function engine_concede(&$game, &$players, $seat, $mysqli) {
  $wasOnTurn = ($game['current_seat'] !== null && (int) $game['current_seat'] === (int) $seat);
  $players[$seat]['conceded'] = 1;
  $msg = $players[$seat]['player_name'] . ' shut down the presses.';
  engine_log($mysqli, $game, $seat, 'concede', $msg, null, $players[$seat]['player_name']);

  if (engine_human_seats($players) < 1) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return $msg;
  }
  if ($wasOnTurn) {
    engine_end_turn($game, $players, $mysqli);
    return $msg;
  }
  // One seat fewer means fewer turns this election; it may already be due.
  if ((int) $game['state']['turns_taken_this_space'] >= engine_turns_needed($game, $players)) {
    engine_resolve_election($game, $players, $mysqli);
  }
  return $msg;
}

/**
 * Play one card, CASH or PRINT. Shared by human turns and the bot, so a bot
 * can never do something a player could not.
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
  $election = vg_election_at((int) $game['state']['space']);
  $value = (int) $card['value'];

  switch ($action) {

    case 'cash': {
      $bonus = (!empty($player['public_state']['is_patron'])) ? (int) $config['patron_bonus'] : 0;
      $player['public_state']['money'] = (int) $player['public_state']['money'] + $value + $bonus;
      $msg = $name . ' ran ' . $card['name'] . ' for ' . ($value + $bonus) . ' money'
           . ($bonus ? ' (including ' . $bonus . ' as Patron).' : '.');
      engine_log($mysqli, $game, $seat, 'cash', $msg,
        ['card' => $cardKey, 'value' => $value, 'patron_bonus' => $bonus,
         'money' => $player['public_state']['money']], $name);
      break;
    }

    case 'print': {
      $side = isset($params['side']) ? (string) $params['side'] : '';
      if ($side !== 'nation' && $side !== 'states') {
        throw new Exception('Choose which candidate to back.');
      }
      $candidate = $election[$side];

      $before = (int) $game['state']['track'];
      $game['state']['track'] = engine_track_after($game, $cardKey);

      if (!isset($game['state']['stakes'][$side]) || !is_array($game['state']['stakes'][$side])) {
        $game['state']['stakes'][$side] = [];
      }
      $current = (int) ($game['state']['stakes'][$side][$seat] ?? 0);
      $game['state']['stakes'][$side][$seat] = $current + $value;
      $player['public_state']['prints'] = 1 + (int) ($player['public_state']['prints'] ?? 0);

      $msg = $name . ' printed ' . $card['name'] . ' and staked ' . $value . ' on '
           . $candidate['name'] . '.';
      engine_log($mysqli, $game, $seat, 'print', $msg,
        ['card' => $cardKey, 'side' => $side, 'candidate' => $candidate['key'],
         'stake' => $value, 'push' => (int) $card['push'],
         'track_from' => $before, 'track_to' => (int) $game['state']['track']], $name);
      break;
    }

    default:
      throw new Exception('Unknown action: ' . $action);
  }

  array_splice($hand, $at, 1);
  $player['private_state']['hand'] = $hand;
  $player['public_state']['cards_played'] = 1 + (int) $player['public_state']['cards_played'];
  $game['state']['discard'][] = $cardKey;

  engine_draw_up($game, $player, (int) $config['hand_size']);
  $player['score'] = (int) $player['public_state']['money'];
  return $msg;
}

// ---------------------------------------------------------------------
// Turn order and the election
// ---------------------------------------------------------------------

/**
 * End the current turn: either pass to the next seat, or — when every
 * seat has had its turns for this election — resolve it.
 */
function engine_end_turn(&$game, &$players, $mysqli) {
  if ($game['status'] !== 'active') return;

  if (engine_human_seats($players) < 1) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return;
  }

  $game['state']['turns_taken_this_space'] = 1 + (int) $game['state']['turns_taken_this_space'];
  if ($game['state']['turns_taken_this_space'] >= engine_turns_needed($game, $players)) {
    engine_resolve_election($game, $players, $mysqli);
    return;
  }
  $game['current_seat'] = engine_seat_after($players, $game['current_seat']);
}

/**
 * Resolve the election on the current space, pay the stakes, name the
 * Patron, and advance the board.
 */
function engine_resolve_election(&$game, &$players, $mysqli) {
  $space = (int) $game['state']['space'];
  $election = vg_election_at($space);
  if (!$election) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }

  list($side, $decidedBy) = engine_leading_side($game);
  $winner = $election[$side];
  $loser = $election[engine_other_side($side)];
  $track = (int) $game['state']['track'];
  $stakes = $game['state']['stakes'];
  $num = max(1, (int) $game['config']['payout_num']);
  $den = max(1, (int) $game['config']['payout_den']);

  // Winning stakes pay back. Losing stakes are simply gone.
  $payouts = [];
  foreach (($stakes[$side] ?? []) as $s => $stake) {
    if (!isset($players[$s])) continue;
    $paid = intdiv((int) $stake * $num, $den);
    if ($paid <= 0) continue;
    $players[$s]['public_state']['money'] = (int) $players[$s]['public_state']['money'] + $paid;
    $players[$s]['score'] = (int) $players[$s]['public_state']['money'];
    $payouts[(int) $s] = $paid;
  }

  // The Patron: the single largest stake on the winner.
  $patron = null;
  $best = 0;
  $tied = false;
  foreach (($stakes[$side] ?? []) as $s => $stake) {
    $stake = (int) $stake;
    if ($stake > $best) { $best = $stake; $patron = (int) $s; $tied = false; }
    elseif ($stake === $best && $stake > 0) { $tied = true; }
  }
  if ($tied || $best <= 0) $patron = null;

  foreach ($players as $s => $p) {
    $is = ($patron !== null && (int) $s === $patron);
    $players[$s]['public_state']['is_patron'] = $is;
    if ($is) {
      $players[$s]['public_state']['patronages'] =
        1 + (int) ($players[$s]['public_state']['patronages'] ?? 0);
    }
  }
  $game['state']['patron_seat'] = $patron;
  $patronName = ($patron !== null && isset($players[$patron])) ? $players[$patron]['player_name'] : null;

  $msg = $election['year'] . ': ' . $winner['name'] . ' wins'
       . ($patronName ? ', and ' . $patronName . ' is his Patron.' : ', and no paper can claim him.');

  engine_log($mysqli, $game, null, 'election', $msg, [
    'space' => $space, 'year' => $election['year'],
    'winner_side' => $side, 'winner' => $winner['key'], 'decided_by' => $decidedBy,
    'track' => $track, 'stakes' => $stakes, 'payouts' => $payouts,
    'patron_seat' => $patron,
    'historical_winner' => $election['historical_winner'],
  ]);
  foreach ($payouts as $s => $paid) {
    engine_log($mysqli, $game, $s, 'payout',
      $players[$s]['player_name'] . ' collected ' . $paid . ' on ' . $winner['name'] . '.',
      ['paid' => $paid, 'stake' => (int) $stakes[$side][$s]], $players[$s]['player_name']);
  }

  $game['state']['history'][] = [
    'space' => $space, 'year' => $election['year'],
    'winner_side' => $side, 'winner' => $winner['key'], 'winner_name' => $winner['name'],
    'loser_name' => $loser['name'],
    'decided_by' => $decidedBy, 'track' => $track,
    'patron_seat' => $patron, 'patron_name' => $patronName,
    'matched_history' => ($side === $election['historical_winner']),
  ];
  $game['state']['president'] = [
    'name' => $winner['name'], 'year' => $election['year'], 'side' => $side,
    'patron_seat' => $patron,
  ];

  // Clear the slate. The country starts every campaign undecided.
  $game['state']['stakes'] = ['nation' => [], 'states' => []];
  $game['state']['track'] = 0;
  $game['state']['turns_taken_this_space'] = 0;
  $game['state']['space'] = $space + 1;
  $game['round_number'] = $space + 1;

  if ($game['state']['space'] > (int) $game['config']['total_spaces']) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }

  if ($game['state']['space'] === (int) $game['config']['crisis_space']) {
    foreach (vg_cards_in_era('crisis') as $k) $game['state']['deck'][] = $k;
    shuffle($game['state']['deck']);
    $game['state']['crisis'] = true;
    engine_log($mysqli, $game, null, 'crisis',
      'The sectional crisis: Texas, Kansas and the Fugitive Slave Act join the argument.',
      ['space' => $game['state']['space']]);
  }

  engine_log_campaign($mysqli, $game);

  // Rotate who opens the campaign, walking the seats that exist.
  $start = engine_seat_after($players, (int) ($game['state']['start_seat'] ?? 0), false);
  $game['state']['start_seat'] = $start;
  $game['current_seat'] = empty($players[$start]['conceded'])
    ? $start : engine_seat_after($players, $start);
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
// The bot — solo play
// ---------------------------------------------------------------------

/**
 * Run every consecutive bot seat until a human is on turn or the game
 * ends. Called after each human action, inside the same transaction, so
 * a solo player sees the whole round resolve in one response.
 */
function engine_run_bots(&$game, &$players, $mysqli, $limit = null) {
  // One full election plus a margin: a bound on NORMAL play, so a table
  // of bots can never play the whole board inside one request.
  if ($limit === null) {
    $limit = count($players) * (int) $game['config']['turns_per_space'] + 4;
  }
  $steps = 0;
  while ($game['status'] === 'active' && $steps < $limit) {
    if (engine_human_seats($players) < 1) break;
    $seat = $game['current_seat'];
    if ($seat === null || !isset($players[$seat])) break;
    if (empty($players[$seat]['is_bot']) || !empty($players[$seat]['conceded'])) break;

    $hand = $players[$seat]['private_state']['hand'] ?? [];
    if (empty($hand)) { $players[$seat]['conceded'] = 1; engine_end_turn($game, $players, $mysqli); $steps++; continue; }

    list($action, $params) = engine_bot_choice($game, $players, $seat);
    try {
      engine_play_card($game, $players, $seat, $action, $params, $mysqli);
    } catch (Exception $e) {
      // A bot must never wedge the game: fall back to the always-legal move.
      engine_play_card($game, $players, $seat, 'cash', ['card' => $hand[0]], $mysqli);
    }
    engine_end_turn($game, $players, $mysqli);
    $steps++;
  }
}

/**
 * The bot decision. Kept in step with strat_bot in tools/simulate.py — the
 * tuning was validated against it, so change them together.
 *
 *   1. Patron? Cash the best card; the bonus is the point of the office.
 *   2. Otherwise print the most valuable card that leaves the track off
 *      zero, staking it on whichever side then leads.
 *   3. No such card? Cash the best card.
 */
function engine_bot_choice($game, $players, $seat) {
  $player = $players[$seat];
  $hand = $player['private_state']['hand'] ?? [];

  $bestCash = $hand[0];
  foreach ($hand as $key) {
    if ((int) vg_card($key)['value'] > (int) vg_card($bestCash)['value']) $bestCash = $key;
  }
  if (!empty($player['public_state']['is_patron'])) {
    return ['cash', ['card' => $bestCash]];
  }

  $bestPrint = null;
  $bestSide = null;
  foreach ($hand as $key) {
    $track = engine_track_after($game, $key);
    if ($track === 0) continue;
    if ($bestPrint === null || (int) vg_card($key)['value'] > (int) vg_card($bestPrint)['value']) {
      $bestPrint = $key;
      $bestSide = ($track > 0) ? 'nation' : 'states';
    }
  }
  if ($bestPrint !== null) {
    return ['print', ['card' => $bestPrint, 'side' => $bestSide]];
  }
  return ['cash', ['card' => $bestCash]];
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

/**
 * Final score. Money IS the score; nothing else is added. Patronages are
 * reported because they explain the money, not because they score.
 */
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

/** A side's stakes as a list, so JSON never has to guess list-or-object. */
function engine_stake_list($game, $side) {
  $out = [];
  foreach (($game['state']['stakes'][$side] ?? []) as $s => $amount) {
    $out[] = ['seat' => (int) $s, 'amount' => (int) $amount];
  }
  return $out;
}

/**
 * Build the state blob the client polls. Every seat sees the same public
 * payload; exactly one private block is included, for the asking seat.
 *
 * This function is the hidden-information boundary. Hands are private —
 * other seats get a COUNT, never the contents.
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $current = engine_is_current($game);
  $status = $game['status'];
  $endedReason = $game['ended_reason'];
  // An active game from an older engine cannot be played: show it as over
  // rather than feed its state to a UI that expects this shape.
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
      'score'           => (int) $p['score'],
      'final_score'     => $p['final_score'],
      'score_breakdown' => ($status === 'ended') ? $p['score_breakdown'] : null,
      'is_you'          => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
    ];
  }

  $space = (int) ($state['space'] ?? 1);
  $election = $current ? vg_election_at($space) : null;
  $track = (int) ($state['track'] ?? 0);

  $race = null;
  if ($election && $status === 'active') {
    list($leading, $decidedBy) = engine_leading_side($game);
    $cands = [];
    foreach (['nation', 'states'] as $side) {
      $c = $election[$side];
      $cands[$side] = [
        'key' => $c['key'], 'name' => $c['name'], 'party' => $c['party'],
        'note' => $c['note'], 'side' => $side,
        'stakes' => engine_stake_list($game, $side),
        'total' => engine_stake_total($game, $side),
      ];
    }
    $race = [
      'space' => $space, 'year' => $election['year'], 'note' => $election['note'],
      'nation' => $cands['nation'], 'states' => $cands['states'],
      'leading' => $leading, 'decided_by' => $decidedBy,
      'historical_winner' => $election['historical_winner'],
      'turns_taken' => (int) ($state['turns_taken_this_space'] ?? 0),
      'turns_needed' => engine_turns_needed($game, $players),
    ];
  }

  // The viewer hand, with what each card would do worked out server-side
  // so the UI never reimplements a rule.
  $you = null;
  if ($viewerSeat !== null && isset($players[$viewerSeat])) {
    $me = $players[$viewerSeat];
    $bonus = !empty($me['public_state']['is_patron']) ? (int) ($config['patron_bonus'] ?? 0) : 0;
    $hand = [];
    foreach (($current ? ($me['private_state']['hand'] ?? []) : []) as $key) {
      $c = vg_card($key);
      if (!$c) continue;
      $after = engine_track_after($game, $key);
      $hand[] = [
        'key' => $key, 'name' => $c['name'], 'year' => $c['year'],
        'flavor' => $c['flavor'], 'era' => $c['era'],
        'value' => (int) $c['value'],
        'cash_value' => (int) $c['value'] + $bonus,
        'push' => (int) $c['push'],
        'track_after' => $after,
        'leads_after' => engine_leading_side($game, $after)[0],
      ];
    }
    $you = ['seat' => (int) $viewerSeat, 'hand' => $hand];
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
    'current_seat'  => ($status === 'active') ? $game['current_seat'] : null,
    'max_players'   => (int) $game['max_players'],
    'winner_seat'   => $game['winner_seat'],
    'ended_reason'  => $endedReason,
    'ended_text'    => $endedReason ? engine_ended_text($endedReason) : null,
    'state_version' => (int) $game['state_version'],
    'rules'         => [
      'turns_per_space' => (int) ($config['turns_per_space'] ?? 2),
      'patron_bonus'    => (int) ($config['patron_bonus'] ?? 2),
      'payout'          => $num / $den,
      'crisis_year'     => ($e = vg_election_at((int) ($config['crisis_space'] ?? 11))) ? $e['year'] : null,
    ],
    'track'         => [
      'value' => $track,
      'min' => (int) ($config['track_min'] ?? -5),
      'max' => (int) ($config['track_max'] ?? 5),
    ],
    'crisis'        => (bool) ($state['crisis'] ?? false),
    'patron_seat'   => $state['patron_seat'] ?? null,
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
  if ($game['status'] !== 'active' || !engine_is_current($game)) return [];
  if (!empty($players[$seat]['conceded'])) return [];

  $actions = ['concede'];
  if ($game['current_seat'] === null || (int) $game['current_seat'] === (int) $seat) {
    $actions[] = 'cash';
    $actions[] = 'print';
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
 *
 * $viewerSeat: the seated player asking, or null for operator access.
 * While a game is still running a player gets only their OWN private
 * state; otherwise a mid-game download would show them every rival hand.
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
    // The draw order is hidden information too.
    $board['deck'] = count($board['deck'] ?? []);
  }

  return [
    'export_version' => 3,
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
