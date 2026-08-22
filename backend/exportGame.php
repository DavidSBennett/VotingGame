<?php
/**
 * exportGame.php — GET. One playthrough as verbatim JSON: summary, every
 * seat, the final board, and the COMPLETE event log with detail.
 *
 * ?player_token=…      any seated player, at any point
 * ?game_id=…&admin_token=…   operator access to any game
 *
 * The results screen and the lobby both offer this as "Download
 * playthrough". Everything I review between playtests comes from here,
 * so it is lossless by policy: add fields, never trim them.
 */
require_once __DIR__ . '/engine.php';

require_method('GET');

if (isset($_GET['player_token'])) {
  $me = authenticate($mysqli);
  $gameId = (int) $me['game_id'];
} else {
  require_admin();
  $gameId = isset($_GET['game_id']) ? (int) $_GET['game_id'] : 0;
  if ($gameId <= 0) error('game_id required', 400);
}

$game = load_game($mysqli, $gameId);
if (!$game) error('Game not found', 404);
$players = load_players($mysqli, $gameId);

$export = engine_build_export($mysqli, $game, $players);

// Served as a download when asked, so the browser writes a named file
// straight into the playtest folder instead of rendering JSON in a tab.
if (!empty($_GET['download'])) {
  $name = 'playthrough-' . $gameId . '-' . gmdate('Ymd-His') . '.json';
  header('Content-Disposition: attachment; filename="' . $name . '"');
}

json(['ok' => true, 'export' => $export]);
