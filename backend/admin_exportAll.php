<?php
/**
 * admin_exportAll.php — GET. Download the ENTIRE playthrough database as
 * one JSON file: every game, every seat, every event, every playtest
 * report, and the score board.
 *
 *   https://<subdomain>.thehistorians.org/admin_exportAll.php?admin_token=…&download=1
 *
 * Optional filters:
 *   ?status=ended        only finished games
 *   ?since_game_id=12    only games from that id up
 *
 * This is the "give me everything so far" button for between-session
 * analysis. Admin-gated because it contains every seat private state and
 * every player token.
 */
require_once __DIR__ . '/engine.php';

require_method('GET');
require_admin();

$where = [];
if (isset($_GET['status']) && in_array($_GET['status'], ['lobby', 'active', 'ended'], true)) {
  $where[] = "status = '" . $mysqli->real_escape_string($_GET['status']) . "'";
}
if (isset($_GET['since_game_id'])) {
  $where[] = "game_id >= " . (int) $_GET['since_game_id'];
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$res = $mysqli->query("SELECT game_id FROM vg_games $whereSql ORDER BY game_id ASC");
if (!$res) error('Query failed: ' . $mysqli->error, 500);
$ids = [];
while ($r = $res->fetch_row()) $ids[] = (int) $r[0];
$res->free();

$games = [];
foreach ($ids as $gameId) {
  $game = load_game($mysqli, $gameId);
  if (!$game) continue;
  $players = load_players($mysqli, $gameId);
  $games[] = engine_build_export($mysqli, $game, $players);
}

// Playtest reports, including any whose game has since been cleared.
$reports = [];
$res = $mysqli->query("SELECT * FROM vg_playtest_reports ORDER BY report_id ASC");
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $r['report_id'] = (int) $r['report_id'];
    $r['snapshot']  = json_col($r['snapshot'], null);
    $reports[] = $r;
  }
  $res->free();
}

$scores = [];
$res = $mysqli->query("SELECT * FROM vg_scores ORDER BY score_id ASC");
if ($res) {
  while ($r = $res->fetch_assoc()) {
    $r['detail'] = json_col($r['detail'], null);
    $scores[] = $r;
  }
  $res->free();
}

if (!empty($_GET['download'])) {
  $name = 'votinggame-all-' . gmdate('Ymd-His') . '.json';
  header('Content-Disposition: attachment; filename="' . $name . '"');
}

json([
  'ok'          => true,
  'exported_at' => gmdate('c'),
  'game_count'  => count($games),
  'games'       => $games,
  'reports'     => $reports,
  'scores'      => $scores,
]);
