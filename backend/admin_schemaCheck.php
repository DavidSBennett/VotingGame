<?php
/**
 * admin_schemaCheck.php — GET. Does the database match what the code expects?
 *
 * Code deploys automatically; migrations do not. Every migration in
 * database/ is run by hand in phpMyAdmin, which makes "new code against an
 * old schema" the single most likely way this install breaks — and the
 * failure is usually silent rather than loud. This endpoint answers
 * "did that migration actually run?" without opening phpMyAdmin.
 *
 *   https://<subdomain>.thehistorians.org/admin_schemaCheck.php
 *
 * Read-only: every query is INFORMATION_SCHEMA, nothing is altered.
 * Deliberately NOT admin-gated — it reports only presence and absence of
 * table and column NAMES, never a byte of game data, and it has to work
 * before ADMIN_TOKEN is configured, since checking the very first
 * migration is what it is for.
 *
 * WHEN YOU ADD A MIGRATION: add its expectations to $EXPECTED below in
 * the same commit. An entry here is the migration checklist.
 */
require_once __DIR__ . '/engine.php';   // also pulls in lib.php

require_method('GET');

/** table => [ migration that creates it, [required columns] ] */
$EXPECTED = [
  'vg_games' => ['01_tables.sql', [
    'game_id', 'join_code', 'status', 'variant', 'max_players', 'host_player_id',
    'phase', 'round_number', 'current_seat', 'config', 'state',
    'ended_reason', 'winner_seat', 'state_version',
    'created_at', 'updated_at', 'ended_at',
  ]],
  'vg_game_players' => ['01_tables.sql', [
    'player_id', 'game_id', 'seat', 'player_name', 'player_token', 'is_bot',
    'public_state', 'private_state', 'score', 'final_score', 'score_breakdown',
    'conceded', 'last_seen_at', 'created_at',
  ]],
  'vg_event_log' => ['01_tables.sql', [
    'event_id', 'game_id', 'seat', 'player_name', 'round_number', 'phase',
    'event_type', 'message', 'event_data', 'created_at',
  ]],
  'vg_playtest_reports' => ['01_tables.sql', [
    'report_id', 'game_id', 'seat', 'player_name', 'variant', 'rating',
    'notes', 'snapshot', 'created_at',
  ]],
  'vg_scores' => ['01_tables.sql', [
    'score_id', 'game_id', 'seat', 'player_name', 'variant', 'score',
    'players_count', 'rounds', 'ended_reason', 'won', 'detail', 'created_at',
  ]],
];

/** Column names present in $table, or null when the table is absent. */
function sc_columns(mysqli $mysqli, string $table) {
  $stmt = $mysqli->prepare("
    SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = ?
  ");
  if (!$stmt) return null;
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $res = $stmt->get_result();
  $cols = [];
  while ($r = $res->fetch_row()) $cols[] = $r[0];
  $stmt->close();
  return count($cols) ? $cols : null;
}

$report = [];
$allOk = true;
$missingMigrations = [];

foreach ($EXPECTED as $table => $spec) {
  list($migration, $wanted) = $spec;
  $have = sc_columns($mysqli, $table);

  if ($have === null) {
    $allOk = false;
    $missingMigrations[$migration] = true;
    $report[] = [
      'table'           => $table,
      'exists'          => false,
      'missing_columns' => $wanted,
      'migration'       => $migration,
      'detail'          => 'Table not found — run database/' . $migration,
    ];
    continue;
  }

  $missing = array_values(array_diff($wanted, $have));
  $extra   = array_values(array_diff($have, $wanted));
  if ($missing) {
    $allOk = false;
    $missingMigrations[$migration] = true;
  }
  $report[] = [
    'table'           => $table,
    'exists'          => true,
    'missing_columns' => $missing,
    'extra_columns'   => $extra,   // not an error: hand-added columns are fine
    'migration'       => $migration,
    'detail'          => $missing ? 'Columns missing — run database/' . $migration : 'OK',
  ];
}

// Row counts, so "is this the right database?" is answerable at a glance.
$counts = [];
foreach (array_keys($EXPECTED) as $table) {
  $res = @$mysqli->query("SELECT COUNT(*) AS n FROM `" . $table . "`");
  if ($res) {
    $row = $res->fetch_assoc();
    $counts[$table] = (int) $row['n'];
    $res->free();
  } else {
    $counts[$table] = null;
  }
}

$dbRes = $mysqli->query("SELECT DATABASE() AS db");
$dbRow = $dbRes ? $dbRes->fetch_assoc() : null;

json([
  'ok'                 => true,
  'all_applied'        => $allOk,
  'database'           => $dbRow ? $dbRow['db'] : null,
  'php_version'        => PHP_VERSION,
  'engine_version'     => defined('ENGINE_STATE_VERSION') ? ENGINE_STATE_VERSION : null,
  'admin_token_set'    => defined('ADMIN_TOKEN') && ADMIN_TOKEN !== '',
  'run_these'          => array_keys($missingMigrations),
  'tables'             => $report,
  'row_counts'         => $counts,
]);
