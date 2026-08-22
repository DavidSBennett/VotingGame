<?php
/**
 * getState.php — GET. The poll endpoint: full public state for one seat.
 *
 * ?player_token=…            seated view (includes that seat private half)
 * ?game_id=…                 spectator view (no private half at all)
 * &since=<state_version>     cheap poll: if nothing changed, the response
 *                            is { changed: false } and no state is built
 * &events=<n>                how many recent log lines to include (default 60)
 *
 * Polled every 1.5s by every open client, so it stays deliberately cheap:
 * one indexed read to compare versions before anything else happens.
 */
require_once __DIR__ . '/engine.php';

require_method('GET');

$viewerSeat = null;
if (isset($_GET['player_token'])) {
  $me = authenticate($mysqli);
  $gameId = (int) $me['game_id'];
  $viewerSeat = (int) $me['seat'];
} else {
  $gameId = isset($_GET['game_id']) ? (int) $_GET['game_id'] : 0;
  if ($gameId <= 0) error('player_token or game_id required', 400);
}

// Version check first — the common case is "nothing has changed".
$stmt = $mysqli->prepare("SELECT state_version FROM vg_games WHERE game_id = ?");
$stmt->bind_param('i', $gameId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) error('Game not found', 404);
$version = (int) $row['state_version'];

if (isset($_GET['since']) && (int) $_GET['since'] === $version) {
  json(['ok' => true, 'changed' => false, 'state_version' => $version]);
}

$game = load_game($mysqli, $gameId);
if (!$game) error('Game not found', 404);
$players = load_players($mysqli, $gameId);

$eventLimit = isset($_GET['events']) ? (int) $_GET['events'] : 60;

json([
  'ok'      => true,
  'changed' => true,
  'state'   => engine_public_state($game, $players, $viewerSeat),
  'events'  => recent_events($mysqli, $gameId, $eventLimit),
]);
