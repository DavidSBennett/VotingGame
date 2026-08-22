<?php
/**
 * admin_clearFinished.php — POST. Delete finished games so the lobby
 * stops filling up with dead tables.
 *
 * Body: { admin_token, confirm: 'DELETE', older_than_hours?: 0, dry_run?: true }
 *
 * DESTRUCTIVE, so it is deliberately awkward:
 *   - admin_token required
 *   - confirm must be the literal string DELETE
 *   - dry_run:true reports exactly what WOULD go, and deletes nothing
 *
 * What survives: vg_scores (the high-score board) and vg_playtest_reports
 * (the notes and their snapshots). Both keep their game_id even after the
 * game row is gone, so a purge never costs you playtest evidence — only
 * the raw event log of games you have already exported.
 */
require_once __DIR__ . '/lib.php';

require_method('POST');
require_admin();
$body = read_json_body();

$dryRun = !empty($body['dry_run']);
if (!$dryRun && (string) ($body['confirm'] ?? '') !== 'DELETE') {
  error('Refusing to delete: send confirm = DELETE (or dry_run = true)', 400);
}

$olderThanHours = isset($body['older_than_hours']) ? max(0, (int) $body['older_than_hours']) : 0;

$sql = "SELECT game_id FROM vg_games WHERE status = 'ended'";
if ($olderThanHours > 0) {
  $sql .= " AND (ended_at IS NULL OR ended_at < DATE_SUB(NOW(), INTERVAL " . $olderThanHours . " HOUR))";
}
$res = $mysqli->query($sql);
if (!$res) error('Query failed: ' . $mysqli->error, 500);
$ids = [];
while ($r = $res->fetch_row()) $ids[] = (int) $r[0];
$res->free();

if ($dryRun) {
  json(['ok' => true, 'dry_run' => true, 'would_delete' => $ids, 'count' => count($ids)]);
}
if (!$ids) {
  json(['ok' => true, 'deleted' => [], 'count' => 0, 'note' => 'Nothing finished to clear']);
}

$idList = implode(',', $ids);
$mysqli->begin_transaction();
try {
  // Order matters only for readability; there are no FK constraints.
  if (!$mysqli->query("DELETE FROM vg_event_log     WHERE game_id IN ($idList)")) {
    throw new Exception('event log delete failed: ' . $mysqli->error);
  }
  if (!$mysqli->query("DELETE FROM vg_game_players  WHERE game_id IN ($idList)")) {
    throw new Exception('player delete failed: ' . $mysqli->error);
  }
  if (!$mysqli->query("DELETE FROM vg_games         WHERE game_id IN ($idList)")) {
    throw new Exception('game delete failed: ' . $mysqli->error);
  }
  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 500);
}

json([
  'ok'      => true,
  'deleted' => $ids,
  'count'   => count($ids),
  'kept'    => 'vg_scores and vg_playtest_reports were not touched',
]);
