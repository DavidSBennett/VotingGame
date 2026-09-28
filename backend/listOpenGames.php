<?php
/**
 * listOpenGames.php — GET. Tables in the lobby that still have a free
 * seat, newest first. Feeds the lobby list so a player can join without
 * being handed a code.
 *
 * ?include_active=1 also lists games already in progress (spectating and
 * "rejoin from another device" both start here).
 */
require_once __DIR__ . '/engine.php';   // for ENGINE_STATE_VERSION (the newsroom game's)
require_once __DIR__ . '/cards_dc.php'; // the newspapers

require_method('GET');

$includeActive = !empty($_GET['include_active']);
$statusClause = $includeActive ? "g.status IN ('lobby','active')" : "g.status = 'lobby'";

$sql = "
  SELECT g.game_id, g.join_code, g.status, g.variant, g.max_players,
         g.round_number, g.created_at, g.config,
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
  // A game running under an older engine can no longer be played, so it
  // is not "in progress" in any sense a player cares about. Leave it out.
  $cfg = json_col($r['config']);
  // The lobby plays the DC-style game now; the newsroom game's tables
  // are no longer listed. 9 is engine_dc.php's ENGINE_STATE_VERSION (the
  // two engines cannot be loaded in one request).
  if (vg_engine_of_config($cfg) !== 'dc') continue;
  if ($r['status'] === 'active' && (int) ($cfg['engine_version'] ?? 0) !== 9) continue;
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

// Which newspapers each listed table has taken, so a guest can choose another.
$ids = array_map(function ($g) { return (int) $g['game_id']; }, $games);
$taken = [];
if ($ids) {
  $res = $mysqli->query("SELECT game_id, public_state FROM vg_game_players WHERE game_id IN (" . implode(',', $ids) . ")");
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $paper = json_col($r['public_state'])['paper'] ?? null;
      if ($paper) $taken[(int) $r['game_id']][] = $paper;
    }
  }
}
foreach ($games as $i => $g) $games[$i]['papers_taken'] = $taken[(int) $g['game_id']] ?? [];

$papers = [];
foreach (dc_papers() as $k => $p) {
  $papers[] = ['key' => $k, 'name' => $p['name'], 'ability' => $p['ability'], 'leans' => $p['leans'],
               'flavor' => $p['flavor'], 'dc_super_hero' => $p['dc_super_hero']];
}

json(['ok' => true, 'games' => $games, 'papers' => $papers]);

