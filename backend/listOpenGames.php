<?php
/**
 * listOpenGames.php — GET. Tables in the lobby that still have a free
 * seat, newest first. Feeds the lobby list so a player can join without
 * being handed a code.
 *
 * ?include_active=1 also lists games already in progress (spectating and
 * "rejoin from another device" both start here).
 */
require_once __DIR__ . '/lib.php';

require_method('GET');

$includeActive = !empty($_GET['include_active']);
$statusClause = $includeActive ? "g.status IN ('lobby','active')" : "g.status = 'lobby'";

$sql = "
  SELECT g.game_id, g.join_code, g.status, g.variant, g.max_players,
         g.round_number, g.created_at,
         COUNT(p.player_id) AS seated,
         GROUP_CONCAT(p.player_name ORDER BY p.seat SEPARATOR ', ') AS names
    FROM vg_games g
    LEFT JOIN vg_game_players p ON p.game_id = g.game_id
   WHERE $statusClause
   GROUP BY g.game_id
   ORDER BY g.created_at DESC
   LIMIT 50
";
$res = $mysqli->query($sql);
if (!$res) error('Query failed: ' . $mysqli->error, 500);

$games = [];
while ($r = $res->fetch_assoc()) {
  $games[] = [
    'game_id'     => (int) $r['game_id'],
    'join_code'   => $r['join_code'],
    'status'      => $r['status'],
    'variant'     => $r['variant'],
    'max_players' => (int) $r['max_players'],
    'seated'      => (int) $r['seated'],
    'round_number' => (int) $r['round_number'],
    'players'     => $r['names'] === null ? [] : explode(', ', $r['names']),
    'created_at'  => $r['created_at'],
    'joinable'    => ($r['status'] === 'lobby' && (int) $r['seated'] < (int) $r['max_players']),
  ];
}
$res->free();

json(['ok' => true, 'games' => $games]);
