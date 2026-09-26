<?php
/**
 * _opcache_reset.php
 *
 * Flushes the PHP opcode cache so freshly-deployed .php files take effect
 * immediately instead of running stale compiled bytecode. LiteSpeed serves
 * new static assets right away but can keep running the old compiled PHP —
 * this clears it. The deploy workflow curls this after every upload.
 *
 * Token-guarded so passers-by cannot churn the cache. Also hittable by hand:
 *   https://<subdomain>.thehistorians.org/_opcache_reset.php?token=<TOKEN>
 *
 * The JSON response says whether OPcache was even available and enabled,
 * which is how you tell "the deploy did not take" apart from "OPcache was
 * never the problem".
 *
 * THE TOKEN IS NOT IN THIS REPOSITORY, which is public. The deploy writes
 * _opcache_token.php from the OPCACHE_TOKEN Actions secret; that file only
 * ever exists in the published docroot. Without it this endpoint stays
 * closed rather than open. (The token that used to be hard-coded here is
 * in git history and must never be reused.)
 */

$tokenFile = __DIR__ . '/_opcache_token.php';
$EXPECTED_TOKEN = is_file($tokenFile) ? (string) (include $tokenFile) : '';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($EXPECTED_TOKEN === '' || !hash_equals($EXPECTED_TOKEN, $token)) {
  http_response_code(403);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$available  = function_exists('opcache_reset');
$status     = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$wasEnabled = is_array($status) ? ($status['opcache_enabled'] ?? null) : null;
$reset      = $available ? @opcache_reset() : false;

header('Content-Type: application/json');
echo json_encode([
  'ok'                => true,
  'opcache_available' => $available,
  'opcache_enabled'   => $wasEnabled,
  'reset'             => $reset,
  'php_version'       => PHP_VERSION,
]);
