<?php
/**
 * joinGame.php — POST. Take the next free seat at a table in the lobby.
 *
 * Body: { join_code | game_id, player_name }
 * Response: { game_id, join_code, player_id, player_token, seat, status }
 *
 * Seat assignment happens under FOR UPDATE on the game row, so two
 * players hitting Join in the same instant cannot land on the same seat.
 */
require_once __DIR__ . '/engine.php';

require_method('POST');
$body = read_json_body();

$playerName = trim((string) ($body['player_name'] ?? ''));
if ($playerName === '') error('A player name is required', 400);
if (mb_strlen($playerName) > 40) $playerName = mb_substr($playerName, 0, 40);

$joinCode = strtoupper(trim((string) ($body['join_code'] ?? '')));
$gameId   = isset($body['game_id']) ? (int) $body['game_id'] : 0;
if ($joinCode === '' && $gameId <= 0) error('A join code is required', 400);

if ($gameId <= 0) {
  $stmt = $mysqli->prepare("SELECT game_id FROM vg_games WHERE join_code = ? LIMIT 1");
  $stmt->bind_param('s', $joinCode);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) error('No table with that code', 404);
  $gameId = (int) $row['game_id'];
}

$token = generate_token(32);

$mysqli->begin_transaction();
try {
  $game = load_game($mysqli, $gameId, true);
  if (!$game) throw new Exception('Game not found');
  if ($game['status'] !== 'lobby') throw new Exception('That game has already started');

  $players = load_players($mysqli, $gameId);
  if (count($players) >= (int) $game['max_players']) {
    throw new Exception('That table is full');
  }

  // Lowest free seat index, so a seat vacated before start gets reused.
  $seat = 0;
  while (isset($players[$seat])) $seat++;

  $stmt = $mysqli->prepare("
    INSERT INTO vg_game_players (game_id, seat, player_name, player_token, public_state, private_state)
    VALUES (?, ?, ?, ?, '{}', '{}')
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param('iiss', $gameId, $seat, $playerName, $token);
  if (!$stmt->execute()) throw new Exception('Failed to take a seat: ' . $stmt->error);
  $playerId = (int) $mysqli->insert_id;
  $stmt->close();

  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 400);
}

log_event($mysqli, $gameId, $seat, 'player_joined',
  $playerName . ' took seat ' . ($seat + 1) . '.', null, $playerName, 0, 'lobby');
bump_state_version($mysqli, $gameId);

json([
  'ok'           => true,
  'game_id'      => $gameId,
  'join_code'    => $game['join_code'],
  'player_id'    => $playerId,
  'player_token' => $token,
  'seat'         => $seat,
  'status'       => 'lobby',
]);
