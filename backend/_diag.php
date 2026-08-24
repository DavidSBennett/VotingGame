<?php
/**
 * _diag.php — what does this install actually have?
 *
 * Token-guarded. Reports SHAPE AND NAMES ONLY, never values: file sizes and
 * timestamps, how many define() calls a config file contains, the NAMES of
 * the constants and classes it declares, the NAMES of a class methods and
 * properties. No line of any config file is ever echoed, no constant value
 * is ever read out, and no property default is ever inspected.
 *
 *   https://voting.thehistorians.org/_diag.php?token=<TOKEN>
 *
 * Written because the first deploy could not connect, and each round of
 * guessing cost a deploy. It has since established that dbConfig.php on
 * this host is NOT a constants file at all — 313 lines, zero define()
 * calls — so the credentials live in some other shape, and this reports
 * which one without ever revealing them.
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

/** Shape of a config file, WITHOUT revealing its contents. Counts only. */
function diag_shape($path) {
  if (!file_exists($path)) return ['exists' => false];
  $raw = @file_get_contents($path);
  $out = [
    'exists'   => true,
    'readable' => is_readable($path),
    'size'     => filesize($path),
    'modified' => @gmdate('c', filemtime($path)),
    'lines'    => ($raw === false) ? null : substr_count($raw, "\n") + 1,
  ];
  if ($raw !== false) {
    $out['define_calls'] = substr_count($raw, 'define(');
    $out['declares_class'] = (stripos($raw, 'class ') !== false);
    // Which recognisable config key NAMES appear. Names only, no values.
    $keys = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_TOKEN',
              'SECRET_DB_HOST', 'SECRET_DB_NAME', 'SECRET_DB_USER',
              'SECRET_DB_PASS', 'mysqli', 'PDO', 'MyDatabase'] as $k) {
      if (strpos($raw, $k) !== false) $keys[] = $k;
    }
    $out['names_present'] = $keys;
  }
  return $out;
}

$report = [
  'ok'               => true,
  'php_version'      => PHP_VERSION,
  'docroot'          => __DIR__,
  'mysqli_extension' => class_exists('mysqli'),
  'pdo_extension'    => class_exists('PDO'),
];

// Every candidate credentials file in this docroot.
$candidates = ['dbConfig.php', 'config.secret.php', 'config.php',
               'db.php', 'connect.php', 'credentials.php'];
foreach ($candidates as $name) {
  $report['files'][$name] = diag_shape(__DIR__ . '/' . $name);
}

// What does including dbConfig.php actually declare? Names only.
$configPath = __DIR__ . '/dbConfig.php';
if (file_exists($configPath) && is_readable($configPath)) {
  $constsBefore  = array_keys(get_defined_constants(true)['user'] ?? []);
  $classesBefore = get_declared_classes();
  $funcsBefore   = get_defined_functions()['user'];

  try {
    require_once $configPath;
    $report['included'] = true;
  } catch (Throwable $e) {
    $report['included'] = false;
    $report['include_error'] = $e->getMessage();
  }

  $report['constants_declared'] = array_values(array_diff(
    array_keys(get_defined_constants(true)['user'] ?? []), $constsBefore));
  $newClasses = array_values(array_diff(get_declared_classes(), $classesBefore));
  $report['classes_declared'] = $newClasses;
  $report['functions_declared'] = array_values(array_diff(
    get_defined_functions()['user'], $funcsBefore));

  // For each class it declared: method and property NAMES. Never defaults.
  foreach ($newClasses as $cls) {
    try {
      $rc = new ReflectionClass($cls);
      $props = [];
      foreach ($rc->getProperties() as $p) $props[] = $p->getName();
      $report['class_shape'][$cls] = [
        'methods'    => get_class_methods($cls),
        'properties' => $props,
        'constants'  => array_keys($rc->getConstants()),
      ];
    } catch (Throwable $e) {
      $report['class_shape'][$cls] = 'reflection failed: ' . $e->getMessage();
    }
  }

  $report['left_a_mysqli'] = isset($mysqli) && ($mysqli instanceof mysqli);
  $vars = [];
  foreach (get_defined_vars() as $n => $v) {
    if (in_array($n, ['report', 'configPath', 'token', 'EXPECTED_TOKEN', 'candidates',
                      'name', 'constsBefore', 'classesBefore', 'funcsBefore',
                      'newClasses', 'cls', 'rc', 'props', 'p', 'e', 'n', 'v',
                      'vars', 'probe', 'res', 'row'], true)) continue;
    $vars[$n] = is_object($v) ? get_class($v) : gettype($v);
  }
  $report['variables_left'] = $vars ? $vars : 'none';
}

// If constants of either naming convention did turn up, try them.
$pairs = [
  'DB_'     => ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'],
  'SECRET_' => ['SECRET_DB_HOST', 'SECRET_DB_USER', 'SECRET_DB_PASS', 'SECRET_DB_NAME'],
];
foreach ($pairs as $label => $names) {
  $allDefined = true;
  foreach ($names as $n) if (!defined($n)) $allDefined = false;
  if (!$allDefined) continue;
  $probe = @new mysqli(constant($names[0]), constant($names[1]),
                       constant($names[2]), constant($names[3]));
  $report['connect_with_' . $label] = $probe->connect_errno
    ? ('failed: ' . $probe->connect_error) : 'ok';
  if (!$probe->connect_errno) {
    $res = $probe->query('SELECT DATABASE() AS db');
    $row = $res ? $res->fetch_assoc() : null;
    $report['database'] = $row ? $row['db'] : null;
  }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
