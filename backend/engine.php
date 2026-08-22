<?php
/**
 * engine.php — the server-authoritative rules engine.
 *
 * Pure functions over the $game and $players arrays that lib.php loads.
 * No echo, no HTTP, no direct SQL except the event log: an endpoint opens
 * the transaction, hands the arrays here, and writes back whatever the
 * engine mutated. That separation is what lets a rules change be a
 * one-file diff with no migration.
 *
 * THE CLIENT IS PRESENTATION-ONLY. Anything the browser computes is an
 * advisory mirror; if the two disagree, the server is right and the
 * client is stale. Never trust a value that arrived in a request body
 * when the same value can be recomputed from stored state.
 *
 * ---------------------------------------------------------------------
 * STATUS: SCAFFOLD. The lifecycle below (setup, turn order, action
 * dispatch, end check, scoring, public projection) is real and wired
 * end to end, but the RULES are placeholders pending the design brief.
 * Every spot that needs game-specific rules is marked  RULES:  so the
 * first design pass is a search for that token.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/lib.php';

/** Bump when the state JSON shape changes incompatibly. */
define('ENGINE_STATE_VERSION', 1);

// ---------------------------------------------------------------------
// Configuration defaults
// ---------------------------------------------------------------------

/**
 * The knobs a host can set at create time. Frozen into vg_games.config
 * when the game starts, so a rules change mid-playtest-series never
 * rewrites a game already in progress.
 *
 * RULES: extend as the design settles.
 */
function engine_default_config() {
  return [
    'engine_version' => ENGINE_STATE_VERSION,
    'total_rounds'   => 8,
    'min_players'    => 2,
    'max_players'    => 6,
  ];
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------

/**
 * Deal the opening position. Called once, inside the start transaction,
 * with the final seat list. Mutates $game and $players in place.
 *
 * RULES: everything below the config freeze is placeholder.
 */
function engine_setup(&$game, &$players) {
  $config = array_merge(engine_default_config(), is_array($game['config']) ? $game['config'] : []);
  $game['config'] = $config;

  $game['status']       = 'active';
  $game['phase']        = 'main';
  $game['round_number'] = 1;
  $game['current_seat'] = 0;
  $game['winner_seat']  = null;
  $game['ended_reason'] = null;

  // RULES: the shared board. Placeholder shape — replace wholesale.
  $game['state'] = [
    'engine_version' => ENGINE_STATE_VERSION,
    'seats'          => count($players),
    'log_seq'        => 0,
  ];

  foreach ($players as $seat => $p) {
    // RULES: per-seat starting position.
    $players[$seat]['public_state']  = ['ready' => false];
    $players[$seat]['private_state'] = [];
    $players[$seat]['score']         = 0;
  }
}

// ---------------------------------------------------------------------
// Action dispatch
// ---------------------------------------------------------------------

/**
 * The single mutating entry point. playAction.php validates nothing about
 * the rules itself; it authenticates the seat, takes the row lock, and
 * calls this. Throw Exception with a player-facing message to reject an
 * illegal action — the endpoint rolls back and returns it as { error }.
 *
 * @param array  $game     by reference, mutated
 * @param array  $players  by reference, keyed by seat, mutated
 * @param int    $seat     the acting seat (already authenticated)
 * @param string $action   machine key from the client
 * @param array  $params   action arguments, UNTRUSTED
 * @param mysqli $mysqli   for log_event only
 * @return string          player-facing confirmation message
 */
function engine_apply_action(&$game, &$players, $seat, $action, $params, $mysqli) {
  if ($game['status'] !== 'active') {
    throw new Exception('This game is not in progress.');
  }
  if (!isset($players[$seat])) {
    throw new Exception('You are not seated in this game.');
  }
  if (!empty($players[$seat]['conceded'])) {
    throw new Exception('You have already left this game.');
  }

  switch ($action) {

    // RULES: the real actions go here, one case each.

    case 'ping':
      // Scaffold action: proves the whole authenticate -> lock -> engine
      // -> save -> bump -> poll loop works before any rules exist.
      // Delete once a real action lands.
      engine_require_turn($game, $seat);
      $players[$seat]['public_state']['pings'] =
        1 + (int) ($players[$seat]['public_state']['pings'] ?? 0);
      $msg = $players[$seat]['player_name'] . ' acted (scaffold ping).';
      engine_log($mysqli, $game, $seat, 'ping', $msg,
        ['pings' => $players[$seat]['public_state']['pings']],
        $players[$seat]['player_name']);
      engine_advance_turn($game, $players, $mysqli);
      return $msg;

    case 'concede':
      $players[$seat]['conceded'] = 1;
      $msg = $players[$seat]['player_name'] . ' left the game.';
      engine_log($mysqli, $game, $seat, 'concede', $msg, null, $players[$seat]['player_name']);
      if (engine_active_seats($players) <= 1) {
        engine_end_game($game, $players, 'all_conceded', $mysqli);
      } elseif ((int) $game['current_seat'] === $seat) {
        engine_advance_turn($game, $players, $mysqli);
      }
      return $msg;

    default:
      throw new Exception('Unknown action: ' . $action);
  }
}

/** Reject an out-of-turn action during a sequential phase. */
function engine_require_turn($game, $seat) {
  if ($game['current_seat'] === null) return;          // simultaneous phase
  if ((int) $game['current_seat'] !== (int) $seat) {
    throw new Exception('It is not your turn.');
  }
}

/** Seats still playing (not conceded). */
function engine_active_seats($players) {
  $n = 0;
  foreach ($players as $p) if (empty($p['conceded'])) $n++;
  return $n;
}

// ---------------------------------------------------------------------
// Turn order
// ---------------------------------------------------------------------

/**
 * Pass to the next non-conceded seat, rolling the round over when play
 * wraps back past the last seat, and ending the game when the configured
 * round count is exhausted.
 *
 * RULES: replace if the design is phase-based or simultaneous rather
 * than a simple seat rotation.
 */
function engine_advance_turn(&$game, &$players, $mysqli) {
  $seatCount = count($players);
  if ($seatCount === 0) return;

  $from = (int) $game['current_seat'];
  for ($step = 1; $step <= $seatCount; $step++) {
    $next = ($from + $step) % $seatCount;
    if (!isset($players[$next]) || !empty($players[$next]['conceded'])) continue;

    if ($next <= $from) {
      // Wrapped past the last seat: a full round has been played.
      $game['round_number'] = (int) $game['round_number'] + 1;
      $total = (int) ($game['config']['total_rounds'] ?? 0);
      if ($total > 0 && (int) $game['round_number'] > $total) {
        engine_end_game($game, $players, 'rounds_exhausted', $mysqli);
        return;
      }
      engine_log($mysqli, $game, null, 'round_start',
        'Round ' . $game['round_number'] . ' begins.');
    }
    $game['current_seat'] = $next;
    return;
  }
  // No seat left to act.
  engine_end_game($game, $players, 'no_active_players', $mysqli);
}

// ---------------------------------------------------------------------
// Ending and scoring
// ---------------------------------------------------------------------

/**
 * Finish the game: final scores, winner, status. Idempotent — calling it
 * twice must not double-score, because several paths can reach it.
 */
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
    if (empty($p['conceded'])) {
      if ($best === null || $result['total'] > $players[$best]['final_score']) {
        $best = $seat;
      }
    }
  }
  $game['winner_seat'] = $best;

  engine_log($mysqli, $game, null, 'game_ended',
    'The game ended (' . $reason . ').',
    ['reason' => $reason, 'winner_seat' => $best]);
}

/**
 * Final score for one seat.
 *
 * RULES: placeholder. Return total plus a breakdown keyed by category —
 * the results screen and every export read the breakdown, so name the
 * categories the way the rules name them.
 */
function engine_score_player($game, $players, $seat) {
  $p = $players[$seat];
  $breakdown = [
    'base' => (int) ($p['public_state']['pings'] ?? 0),
  ];
  if (!empty($p['conceded'])) $breakdown['conceded'] = 0;
  return ['total' => array_sum($breakdown), 'breakdown' => $breakdown];
}

// ---------------------------------------------------------------------
// Public projection — the ONLY thing getState.php serialises
// ---------------------------------------------------------------------

/**
 * Build the state blob the client polls. Every seat sees the same public
 * payload; exactly one private block is included, for the asking seat.
 *
 * This function is the hidden-information boundary. If a value must stay
 * secret, it belongs in private_state and must never be copied into the
 * public half — not even "temporarily for the UI".
 *
 * @param int|null $viewerSeat  seat asking, or null for a spectator view
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $seats = [];
  foreach ($players as $seat => $p) {
    $seats[] = [
      'seat'            => (int) $seat,
      'player_name'     => $p['player_name'],
      'is_bot'          => (bool) $p['is_bot'],
      'conceded'        => (bool) $p['conceded'],
      'score'           => (int) $p['score'],
      'final_score'     => $p['final_score'],
      'score_breakdown' => ($game['status'] === 'ended') ? $p['score_breakdown'] : null,
      'public'          => $p['public_state'],
      'last_seen_at'    => $p['last_seen_at'],
      // RULES: publish COUNTS of hidden things here (hand size, sealed
      // votes cast) — never their contents.
      'is_you'          => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
    ];
  }

  return [
    'game_id'       => (int) $game['game_id'],
    'join_code'     => $game['join_code'],
    'status'        => $game['status'],
    'variant'       => $game['variant'],
    'phase'         => $game['phase'],
    'round_number'  => (int) $game['round_number'],
    'total_rounds'  => (int) ($game['config']['total_rounds'] ?? 0),
    'current_seat'  => $game['current_seat'],
    'max_players'   => (int) $game['max_players'],
    'winner_seat'   => $game['winner_seat'],
    'ended_reason'  => $game['ended_reason'],
    'state_version' => (int) $game['state_version'],
    'config'        => $game['config'],
    'board'         => $game['state'],
    'players'       => $seats,
    'you'           => ($viewerSeat !== null && isset($players[$viewerSeat]))
      ? [
          'seat'    => (int) $viewerSeat,
          'private' => $players[$viewerSeat]['private_state'],
        ]
      : null,
    // What the acting seat may legally do right now. The client greys out
    // everything not listed; the server re-checks anyway.
    'available_actions' => engine_available_actions($game, $players, $viewerSeat),
  ];
}

/**
 * Legal actions for one seat, as machine keys.
 *
 * RULES: this is the advisory mirror the UI renders from. Keep it in
 * exact step with engine_apply_action, and never let it be the only
 * place a rule is enforced.
 */
function engine_available_actions($game, $players, $seat) {
  if ($seat === null || !isset($players[$seat])) return [];
  if ($game['status'] !== 'active') return [];
  if (!empty($players[$seat]['conceded'])) return [];

  $actions = ['concede'];
  if ($game['current_seat'] === null || (int) $game['current_seat'] === (int) $seat) {
    $actions[] = 'ping';   // RULES: replace with the real action list
  }
  return $actions;
}

// ---------------------------------------------------------------------
// Logging + export
// ---------------------------------------------------------------------

/** log_event with the round and phase filled in from the game. */
function engine_log($mysqli, $game, $seat, $type, $message = '', $data = null, $playerName = null) {
  log_event($mysqli, (int) $game['game_id'], $seat, $type, $message, $data,
    $playerName, (int) $game['round_number'], $game['phase']);
}

/**
 * The verbatim playthrough export: summary, every seat, and the COMPLETE
 * event log with JSON detail. This is the artefact playtest analysis
 * runs on, so it must stay lossless — add fields, never trim them.
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
      // Private state IS included: an export is only ever produced for a
      // finished or self-inspected game, and hidden information is the
      // most interesting part of a post-mortem.
      'private_state'   => $p['private_state'],
    ];
  }

  return [
    'export_version' => 1,
    'exported_at'    => gmdate('c'),
    'summary' => [
      'game_id'       => (int) $game['game_id'],
      'join_code'     => $game['join_code'],
      'variant'       => $game['variant'],
      'status'        => $game['status'],
      'phase'         => $game['phase'],
      'rounds_played' => (int) $game['round_number'],
      'total_rounds'  => (int) ($game['config']['total_rounds'] ?? 0),
      'winner_seat'   => $game['winner_seat'],
      'ended_reason'  => $game['ended_reason'],
      'created_at'    => $game['created_at'],
      'ended_at'      => $game['ended_at'],
      'config'        => $game['config'],
    ],
    'final_board' => $game['state'],
    'players'     => $seats,
    'events'      => all_events($mysqli, (int) $game['game_id']),
  ];
}

/**
 * Write one vg_scores row per seat at game end. Separate table so
 * clearing finished games never wipes the board. INSERT IGNORE on the
 * (game_id, seat) unique key makes this safe to call more than once.
 */
function engine_record_scores($mysqli, $game, $players) {
  if ($game['status'] !== 'ended') return;
  $playersCount = count($players);
  foreach ($players as $seat => $p) {
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
    $rounds  = (int) $game['round_number'];
    $stmt->bind_param(
      'iissiiisis',
      $game['game_id'], $seatVal, $p['player_name'], $game['variant'], $score,
      $playersCount, $rounds, $game['ended_reason'], $won, $detail
    );
    @$stmt->execute();
    $stmt->close();
  }
}
