<?php
/**
 * _diag.php — what does this install actually have?
 *
 * Token-guarded, and reports only PRESENCE, never values: whether
 * dbConfig.php exists, which DB_* constants it defines, whether it left a
 * $mysqli behind, and whether a connection can be opened at all. No
 * credential is ever printed, and the token is the same one the deploy
 * uses for the OPcache flush.
 *
 *   https://voting.thehistorians.org/_diag.php?token=<TOKEN>
 *
 * Written after the first deploy came back "Database connection
 * unavailable" and the only way to tell WHY was to guess. One curl now
 * answers it instead.
 */

$EXPECTED_TOKEN = '7c4f1a9e2b6d43f0a8e5c1d7b93042fe';
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (!hash_equals($EXPECTED_TOKEN, $token)) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

header('Content-Type: application/json');

$configPath = __DIR__ . '/dbConfig.php';
$report = [
  'ok'               => true,
  'php_version'      => PHP_VERSION,
  'docroot'          => __DIR__,
  'dbconfig_exists'  => file_exists($configPath),
  'dbconfig_readable'=> is_readable($configPath),
  'mysqli_extension' => class_exists('mysqli'),
];

if ($report['dbconfig_exists'] && $report['dbconfig_readable']) {
  // Include inside a try so a fatal in the config file becomes a report
  // rather than a blank 500.
  try {
    require_once $configPath;
    $report['included'] = true;
  } catch (Throwable $e) {
    $report['included'] = false;
    $report['include_error'] = $e->getMessage();
  }

  // PRESENCE ONLY — never the values.
  foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_TOKEN'] as $c) {
    $report['defines'][$c] = defined($c);
  }

  $report['left_a_mysqli'] = isset($mysqli) && ($mysqli instanceof mysqli);

  // Which other variables did it leave behind? Names only, and the type,
  // so a differently-named handle is obvious at a glance.
  $interesting = [];
  foreach (get_defined_vars() as $name => $value) {
    if (in_array($name, ['report', 'configPath', 'token', 'EXPECTED_TOKEN',
                         'c', 'interesting', 'name', 'value', 'e'], true)) continue;
    $interesting[$name] = is_object($value) ? get_class($value) : gettype($value);
  }
  $report['variables_left_by_dbconfig'] = $interesting;

  if (!$report['left_a_mysqli'] && defined('DB_HOST') && defined('DB_NAME')
      && defined('DB_USER') && defined('DB_PASS')) {
    $probe = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $report['constants_connect'] = $probe->connect_errno
      ? ('failed: ' . $probe->connect_error)
      : 'ok';
    if (!$probe->connect_errno) {
      $res = $probe->query('SELECT DATABASE() AS db');
      $row = $res ? $res->fetch_assoc() : null;
      $report['database'] = $row ? $row['db'] : null;
    }
  }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
