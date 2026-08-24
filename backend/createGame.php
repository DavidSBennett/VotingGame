<?php
/**
 * createGame.php — POST. Open a new table with the caller in seat 0.
 *
 * Body: { player_name, max_players?, bots?, variant?, config? }
 * Response: { game_id, join_code, player_id, player_token, seat: 0, status }
 *
 * max_players is HUMAN seats. bots are seated on top of that, and a solo
 * game (max_players = 1) sets up and starts immediately through the same
 * engine path a multiplayer start uses — there is no separate solo code.
 *
 * The token in the response is the ONLY credential for that seat. The
 * client stores it in localStorage; it is never re-issued.
 */
require_once __DIR__ . '/engine.php';

require_method('POST');
$body = read_json_body();

$playerName = trim((string) ($body['player_name'] ?? ''));
if ($playerName === '') error('A player name is required', 400);
if (mb_strlen($playerName) > 40) $playerName = mb_substr($playerName, 0, 40);

$defaults = engine_default_config();
$humanSeats = isset($body['max_players']) ? (int) $body['max_players'] : 1;
if ($humanSeats < 1 || $humanSeats > (int) $defaults['max_players']) {
  error('max_players must be between 1 and ' . $defaults['max_players'], 400);
}

$bots = isset($body['bots']) ? (int) $body['bots'] : ($humanSeats === 1 ? 1 : 0);
if ($bots < 0 || $bots > 4) error('bots must be between 0 and 4', 400);
if ($humanSeats + $bots < 2) error('A game needs at least two seats: add a rival paper', 400);
if ($humanSeats + $bots > 5) error('At most five seats in total', 400);

$variant = (string) ($body['variant'] ?? 'v1');
if (!preg_match('/^[a-z0-9_.-]{1,40}$/i', $variant)) error('Invalid variant', 400);

// Host-chosen knobs merged over the defaults, then frozen at start.
$config = $defaults;
if (isset($body['config']) && is_array($body['config'])) {
  foreach (['total_spaces', 'turns_per_space', 'hand_size', 'start_money',
            'control_bonus', 'stability_start'] as $knob) {
    if (isset($body['config'][$knob])) $config[$knob] = (int) $body['config'][$knob];
  }
}
$config['bots'] = $bots;
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

/** Rival papers, for bot seats. Real mastheads of the period. */
function vg_bot_names() {
  return [
    'The National Intelligencer',
    'The Richmond Enquirer',
    'The New York Tribune',
    'The Columbian Centinel',
  ];
}

$totalSeats = $humanSeats + $bots;
$joinCode = generate_join_code($mysqli);
$token    = generate_token(32);

$mysqli->begin_transaction();
try {
  $stmt = $mysqli->prepare("
    INSERT INTO vg_games (join_code, status, variant, max_players, phase, config)
    VALUES (?, 'lobby', ?, ?, 'lobby', ?)
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param('ssis', $joinCode, $variant, $totalSeats, $configJson);
  if (!$stmt->execute()) throw new Exception('Failed to create game: ' . $stmt->error);
  $gameId = (int) $mysqli->insert_id;
  $stmt->close();

  $stmt = $mysqli->prepare("
    INSERT INTO vg_game_players (game_id, seat, player_name, player_token, is_bot, public_state, private_state)
    VALUES (?, 0, ?, ?, 0, '{}', '{}')
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param('iss', $gameId, $playerName, $token);
  if (!$stmt->execute()) throw new Exception('Failed to seat the host: ' . $stmt->error);
  $playerId = (int) $mysqli->insert_id;
  $stmt->close();

  $stmt = $mysqli->prepare("UPDATE vg_games SET host_player_id = ? WHERE game_id = ?");
  $stmt->bind_param('ii', $playerId, $gameId);
  $stmt->execute();
  $stmt->close();

  // Bots take the LAST seats, so human seats stay contiguous from 0 and a
  // second human joining a solo-plus-bot game does not land behind a bot.
  $botNames = vg_bot_names();
  for ($i = 0; $i < $bots; $i++) {
    $seat = $humanSeats + $i;
    $botToken = generate_token(32);      // never leaves the server
    $botName = $botNames[$i % count($botNames)];
    $stmt = $mysqli->prepare("
      INSERT INTO vg_game_players (game_id, seat, player_name, player_token, is_bot, public_state, private_state)
      VALUES (?, ?, ?, ?, 1, '{}', '{}')
    ");
    if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
    $stmt->bind_param('iiss', $gameId, $seat, $botName, $botToken);
    if (!$stmt->execute()) throw new Exception('Failed to seat a rival: ' . $stmt->error);
    $stmt->close();
  }

  $status = 'lobby';
  if ($humanSeats === 1) {
    // Solo: set up and start at once, through the same engine path.
    $game = load_game($mysqli, $gameId, true);
    $players = load_players($mysqli, $gameId);
    engine_setup($game, $players, $mysqli);
    save_game($mysqli, $game);
    foreach ($players as $p) save_player($mysqli, $p);
    $status = 'active';
  }

  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 500);
}

log_event($mysqli, $gameId, 0, 'game_created',
  $playerName . ' opened a table (' . $joinCode . ')'
  . ($bots ? ' against ' . $bots . ' rival ' . ($bots === 1 ? 'paper' : 'papers') : '') . '.',
  ['human_seats' => $humanSeats, 'bots' => $bots, 'variant' => $variant, 'config' => $config],
  $playerName, 0, 'lobby');
bump_state_version($mysqli, $gameId);

json([
  'ok'           => true,
  'game_id'      => $gameId,
  'join_code'    => $joinCode,
  'player_id'    => $playerId,
  'player_token' => $token,
  'seat'         => 0,
  'status'       => $status,
]);
