<?php
/**
 * createGame.php — POST. Open a new table with the caller in seat 0.
 *
 * Body: { player_name, max_players?, variant?, config? }
 * Response: { game_id, join_code, player_id, player_token, seat: 0, status }
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
$maxPlayers = isset($body['max_players']) ? (int) $body['max_players'] : (int) $defaults['max_players'];
if ($maxPlayers < 1 || $maxPlayers > (int) $defaults['max_players']) {
  error('max_players must be between 1 and ' . $defaults['max_players'], 400);
}

$variant = (string) ($body['variant'] ?? 'v1');
if (!preg_match('/^[a-z0-9_.-]{1,40}$/i', $variant)) error('Invalid variant', 400);

// Host-chosen knobs are merged over the defaults, then frozen at start.
$config = $defaults;
if (isset($body['config']) && is_array($body['config'])) {
  foreach (['total_rounds'] as $knob) {
    if (isset($body['config'][$knob])) $config[$knob] = (int) $body['config'][$knob];
  }
}
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

$joinCode = generate_join_code($mysqli);
$token    = generate_token(32);

$mysqli->begin_transaction();
try {
  $stmt = $mysqli->prepare("
    INSERT INTO vg_games (join_code, status, variant, max_players, phase, config)
    VALUES (?, 'lobby', ?, ?, 'lobby', ?)
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param('ssis', $joinCode, $variant, $maxPlayers, $configJson);
  if (!$stmt->execute()) throw new Exception('Failed to create game: ' . $stmt->error);
  $gameId = (int) $mysqli->insert_id;
  $stmt->close();

  $stmt = $mysqli->prepare("
    INSERT INTO vg_game_players (game_id, seat, player_name, player_token, public_state, private_state)
    VALUES (?, 0, ?, ?, '{}', '{}')
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

  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 500);
}

log_event($mysqli, $gameId, 0, 'game_created',
  $playerName . ' opened a table (' . $joinCode . ').',
  ['max_players' => $maxPlayers, 'variant' => $variant, 'config' => $config],
  $playerName, 0, 'lobby');
bump_state_version($mysqli, $gameId);

json([
  'ok'           => true,
  'game_id'      => $gameId,
  'join_code'    => $joinCode,
  'player_id'    => $playerId,
  'player_token' => $token,
  'seat'         => 0,
  'status'       => 'lobby',
]);
