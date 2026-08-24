<?php
/**
 * _diag.php — what does this install actually have?
 *
 * Token-guarded, and reports only SHAPE, never values: whether
 * dbConfig.php exists, how big it is, when it changed, how many define()
 * calls it contains, whether it left a $mysqli behind, and whether a
 * connection can be opened. No credential is ever printed, no line of the
 * file is ever echoed, and the token is the same one the deploy uses for
 * the OPcache flush.
 *
 *   https://voting.thehistorians.org/_diag.php?token=<TOKEN>
 *
 * The size / modified / define_calls fields exist to separate the three
 * ways this can look identical from outside:
 *   - the file is genuinely empty            -> size 0, old mtime
 *   - it was edited but is not taking effect -> defines > 0, recent mtime
 *   - the edit landed on a DIFFERENT copy    -> see other_dbconfig_files
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

/**
 * Shape of a config file, WITHOUT revealing its contents. Counts only.
 */
function diag_shape($path) {
  if (!file_exists($path)) return ['exists' => false];
  $size = filesize($path);
  $raw = @file_get_contents($path);
  $out = [
    'exists'   => true,
    'readable' => is_readable($path),
    'size'     => $size,
    'modified' => @gmdate('c', filemtime($path)),
    'lines'    => ($raw === false) ? null : substr_count($raw, "\n") + 1,
  ];
  if ($raw !== false) {
    $out['starts_with_php_tag'] = (substr(ltrim($raw), 0, 5) === '<?php');
    $out['define_calls']  = substr_count($raw, 'define(');
    $out['mentions_mysqli'] = substr_count($raw, 'mysqli');
    $out['mentions_DB_'] = substr_count($raw, 'DB_');
    // Which config KEYS appear, by name only. Never the values.
    $keys = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_TOKEN'] as $k) {
      if (strpos($raw, $k) !== false) $keys[] = $k;
    }
    $out['key_names_present'] = $keys;
  }
  return $out;
}

$configPath = __DIR__ . '/dbConfig.php';

$report = [
  'ok'               => true,
  'php_version'      => PHP_VERSION,
  'docroot'          => __DIR__,
  'mysqli_extension' => class_exists('mysqli'),
  'dbconfig'         => diag_shape($configPath),
];

// Other copies elsewhere in the account, so an edit that landed on the
// wrong one is obvious. PATHS AND SHAPE ONLY.
$others = [];
$parent = dirname(__DIR__);
foreach (array_merge([$parent . '/dbConfig.php'],
                     (array) glob($parent . '/*/dbConfig.php')) as $candidate) {
  if ($candidate === $configPath) continue;
  if (!file_exists($candidate)) continue;
  $others[$candidate] = diag_shape($candidate);
}
$report['other_dbconfig_files'] = $others ? $others : 'none found';

if (!empty($report['dbconfig']['exists']) && !empty($report['dbconfig']['readable'])) {
  try {
    require_once $configPath;
    $report['included'] = true;
  } catch (Throwable $e) {
    $report['included'] = false;
    $report['include_error'] = $e->getMessage();
  }

  foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_TOKEN'] as $c) {
    $report['defined_after_include'][$c] = defined($c);
  }

  $report['left_a_mysqli'] = isset($mysqli) && ($mysqli instanceof mysqli);

  $interesting = [];
  foreach (get_defined_vars() as $name => $value) {
    if (in_array($name, ['report', 'configPath', 'token', 'EXPECTED_TOKEN', 'others',
                         'parent', 'candidate', 'c', 'interesting', 'name', 'value',
                         'e', 'probe', 'res', 'row'], true)) continue;
    $interesting[$name] = is_object($value) ? get_class($value) : gettype($value);
  }
  $report['variables_left_by_dbconfig'] = $interesting ? $interesting : 'none';

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
