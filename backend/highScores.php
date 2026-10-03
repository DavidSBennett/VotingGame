<?php
/**
 * highScores.php — GET. The lobby board.
 *
 * ?variant=v1     restrict to one rules edition (default: all)
 * ?engine=dc      only the DC-style game's scores (prestige); its rows carry
 *                 the paper's newspaper and the elections it won
 * ?engine=2024    only the 2024 game's scores (the prestige staked on the winner)
 * ?limit=25       rows to return (default 25, max 200)
 *
 * Reads vg_scores, which is written once per seat at game end and is
 * deliberately NOT cleared when finished games are purged — the board
 * survives a database tidy-up.
 */
require_once __DIR__ . '/lib.php';

require_method('GET');

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
$limit = max(1, min(200, $limit));

$variant = isset($_GET['variant']) ? (string) $_GET['variant'] : '';
// The DC game's score breakdown has a 'prestige' field; the newsroom game's does not.
$dcOnly = (($_GET['engine'] ?? '') === 'dc') ? " AND detail LIKE '%\"prestige\"%'" : '';
// The 2024 game's breakdown has 'stakes_won'.
if (($_GET['engine'] ?? '') === '2024') $dcOnly = " AND detail LIKE '%\"stakes_won\"%'";
// Games played by the test scripts (tools/smoke_*.py, the UI preview) are not
// on the board.
$dcOnly .= " AND player_name NOT LIKE 'smoke%' AND player_name <> 'UI preview'";
if ($variant !== '' && !preg_match('/^[a-z0-9_.-]{1,40}$/i', $variant)) {
  error('Invalid variant', 400);
}

if ($variant !== '') {
  $stmt = $mysqli->prepare("
    SELECT score_id, game_id, player_name, variant, score, players_count,
           rounds, ended_reason, won, created_at, detail
      FROM vg_scores
     WHERE variant = ?" . $dcOnly . "
     ORDER BY score DESC, created_at ASC
     LIMIT " . $limit);
  $stmt->bind_param('s', $variant);
} else {
  $stmt = $mysqli->prepare("
    SELECT score_id, game_id, player_name, variant, score, players_count,
           rounds, ended_reason, won, created_at, detail
      FROM vg_scores
     WHERE 1 = 1" . $dcOnly . "
     ORDER BY score DESC, created_at ASC
     LIMIT " . $limit);
}
if (!$stmt) error('DB prepare failed: ' . $mysqli->error, 500);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$rank = 0;
while ($r = $res->fetch_assoc()) {
  $rank++;
  $detail = json_col($r['detail']);
  $rows[] = [
    'rank'          => $rank,
    'score_id'      => (int) $r['score_id'],
    'game_id'       => (int) $r['game_id'],
    'player_name'   => $r['player_name'],
    'variant'       => $r['variant'],
    'score'         => (int) $r['score'],
    'players_count' => (int) $r['players_count'],
    'rounds'        => ($r['rounds'] === null) ? null : (int) $r['rounds'],
    'ended_reason'  => $r['ended_reason'],
    'won'           => (bool) $r['won'],
    'created_at'    => $r['created_at'],
    'paper'         => $detail['paper'] ?? null,
    'elections_won' => isset($detail['elections_won']) ? (int) $detail['elections_won'] : null,
  ];
}
$stmt->close();

json(['ok' => true, 'scores' => $rows]);
