<?php
/**
 * lib.php — shared plumbing for every VotingGame endpoint.
 *
 * Loads the server-only credentials file and then provides the handful of
 * helpers that every endpoint uses. NOT an endpoint: require_once this from
 * the top of each *.php action file.
 *
 * dbConfig.php lives ONLY in the subdomain docroot on the server. It defines
 * DB_HOST / DB_NAME / DB_USER / DB_PASS and creates $mysqli (utf8mb4). It is
 * never committed and is excluded from every deploy.
 *
 * The contract every mutating endpoint follows:
 *
 *     require_once __DIR__ . '/engine.php';
 *     require_method('POST');
 *     $me = authenticate($mysqli);
 *     $mysqli->begin_transaction();
 *     try {
 *       $game    = load_game($mysqli, $gameId, true);   // SELECT ... FOR UPDATE
 *       $players = load_players($mysqli, $gameId);
 *       ... engine call, which mutates $game / $players in place ...
 *       save_game($mysqli, $game);
 *       foreach ($players as $p) save_player($mysqli, $p);
 *       $mysqli->commit();
 *     } catch (Exception $e) { $mysqli->rollback(); error($e->getMessage(), 400); }
 *     bump_state_version($mysqli, $gameId);
 *     json(['ok' => true]);
 *
 * NOTE ON QUOTING: in single-quoted PHP strings an apostrophe must be
 * escaped (\'). An unescaped one is a parse error that takes the whole
 * site down; the deploy workflow lints every backend file for exactly
 * this reason. Prefer wording without apostrophes in message strings.
 */

require_once __DIR__ . '/dbConfig.php';

/**
 * Obtain a mysqli connection from whatever shape the server-only config
 * file happens to take.
 *
 * On this host dbConfig.php declares a PDO wrapper class (MyDatabase)
 * holding the credentials as properties — it defines no constants and
 * leaves no $mysqli. Other installs on the same account use SECRET_DB_*
 * constants in a config.secret.php instead. Rather than require the
 * credentials be re-typed into a second file, where they would eventually
 * drift out of sync with the first, we accept every shape:
 *
 *   1. a $mysqli that dbConfig.php already built
 *   2. DB_HOST / DB_USER / DB_PASS / DB_NAME constants
 *   3. SECRET_DB_* constants
 *   4. the credentials inside MyDatabase, read by reflection
 *
 * The engine speaks mysqli throughout (FOR UPDATE row locks, transactional
 * single-writer turns), so we build a mysqli rather than borrow the PDO.
 *
 * $VG_DB_SOURCE records which step won, so _diag.php can report how this
 * install is actually connecting instead of leaving it to folklore.
 */

$VG_DB_SOURCE = null;

if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_errno) {
  $VG_DB_SOURCE = 'dbConfig.php $mysqli';
}

if ($VG_DB_SOURCE === null) {
  foreach ([
    ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'],
    ['SECRET_DB_HOST', 'SECRET_DB_USER', 'SECRET_DB_PASS', 'SECRET_DB_NAME'],
  ] as $names) {
    $complete = true;
    foreach ($names as $n) { if (!defined($n)) { $complete = false; break; } }
    if (!$complete) continue;
    $candidate = @new mysqli(constant($names[0]), constant($names[1]),
                             constant($names[2]), constant($names[3]));
    if (!$candidate->connect_errno) {
      $mysqli = $candidate;
      $VG_DB_SOURCE = $names[0] . ' constants';
      break;
    }
  }
}

/**
 * Pull host / user / pass / dbname out of the MyDatabase wrapper.
 *
 * Tries the declared defaults first, and only instantiates the class if the
 * credentials are assigned in its constructor instead. Returns null rather
 * than throwing if the class is not the shape we expect, so the caller can
 * fall through to a clean error.
 *
 * These values are used to open a connection and are never logged, echoed,
 * or returned in any response.
 */
function vg_credentials_from_wrapper($class = 'MyDatabase') {
  if (!class_exists($class)) return null;
  $wanted = ['host', 'user', 'pass', 'dbname'];
  try {
    $rc = new ReflectionClass($class);
    foreach ($wanted as $w) {
      if (!$rc->hasProperty($w)) return null;
    }

    $out = [];
    $defaults = $rc->getDefaultProperties();
    foreach ($wanted as $w) {
      if (isset($defaults[$w]) && $defaults[$w] !== '') $out[$w] = $defaults[$w];
    }
    if (count($out) === count($wanted)) return $out;

    // Assigned in the constructor rather than declared: build one and read.
    $instance = $rc->newInstance();
    $out = [];
    foreach ($wanted as $w) {
      $p = $rc->getProperty($w);
      $p->setAccessible(true);
      $value = $p->getValue($instance);
      if ($value === null || $value === '') return null;
      $out[$w] = $value;
    }
    return $out;
  } catch (Throwable $e) {
    return null;
  }
}

if ($VG_DB_SOURCE === null) {
  $creds = vg_credentials_from_wrapper();
  if ($creds) {
    $candidate = @new mysqli($creds['host'], $creds['user'], $creds['pass'], $creds['dbname']);
    if (!$candidate->connect_errno) {
      $mysqli = $candidate;
      $VG_DB_SOURCE = 'MyDatabase wrapper';
    } else {
      $VG_DB_CONNECT_ERROR = $candidate->connect_error;
    }
  }
}

if ($VG_DB_SOURCE === null) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode([
    'error' => 'No database connection could be established. dbConfig.php left no '
             . '$mysqli, defined no DB_* or SECRET_DB_* constants, and no usable '
             . 'MyDatabase wrapper was found'
             . (isset($VG_DB_CONNECT_ERROR) ? ' (' . $VG_DB_CONNECT_ERROR . ')' : '')
             . '. See _diag.php for what this install actually declares.',
  ]);
  exit;
}

// Card flavour and candidate names carry em dashes and accents.
@$mysqli->set_charset('utf8mb4');


// ---------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------

/**
 * Send a JSON response and exit.
 *
 * @param mixed $data   JSON-encoded as-is
 * @param int   $status HTTP status
 */
function json($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Send { "error": "..." } and exit. The frontend client turns this into
 * the message shown to the player, so write these for humans.
 */
function error($message, $status = 400) {
  json(['error' => (string) $message], $status);
}

/** Reject a misrouted request early. */
function require_method($method) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
    error('This endpoint requires ' . $method, 405);
  }
}

/**
 * Read and decode the JSON request body. Cached, because authenticate()
 * also needs to look inside it and php://input can only be read once on
 * some SAPIs.
 *
 * @return array
 */
function read_json_body() {
  static $cached = null;
  if ($cached !== null) return $cached;

  $raw = file_get_contents('php://input');
  if ($raw === false || strlen($raw) === 0) {
    $cached = [];
    return $cached;
  }
  $data = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    error('Invalid JSON: ' . json_last_error_msg(), 400);
  }
  if (!is_array($data)) {
    error('Request body must be a JSON object', 400);
  }
  $cached = $data;
  return $cached;
}

// ---------------------------------------------------------------------
// Auth — standalone per-seat tokens. No accounts, no sessions, no
// cookies shared with any other site.
// ---------------------------------------------------------------------

/**
 * Cryptographically random lowercase hex token.
 *
 * @param int $length number of hex characters (even, <= 64)
 */
function generate_token($length = 32) {
  $bytes = (int) max(8, min(32, $length / 2));
  return bin2hex(random_bytes($bytes));
}

/**
 * Short human-typable join code. Alphabet excludes 0/O/1/I/L so a code
 * read aloud or squinted at from a phone cannot be mistyped.
 */
function generate_join_code($mysqli, $length = 4) {
  $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  $max = strlen($alphabet) - 1;
  for ($attempt = 0; $attempt < 40; $attempt++) {
    $code = '';
    for ($i = 0; $i < $length; $i++) {
      $code .= $alphabet[random_int(0, $max)];
    }
    $stmt = $mysqli->prepare("SELECT 1 FROM vg_games WHERE join_code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $taken = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$taken) return $code;
  }
  // Vanishingly unlikely; widen rather than fail.
  return generate_join_code($mysqli, $length + 1);
}

/**
 * Resolve player_token (JSON body on POST, query string on GET) to the
 * seat row, joined with the columns of its game that callers always want.
 * Touches last_seen_at as a heartbeat.
 *
 * @return array the vg_game_players row plus game_id / seat / status /
 *               phase / round_number / current_seat / variant.
 */
function authenticate($mysqli, $tokenOverride = null) {
  $token = $tokenOverride;
  if ($token === null) {
    $body = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? read_json_body() : [];
    if (isset($body['player_token'])) $token = $body['player_token'];
    if (!$token && isset($_GET['player_token'])) $token = $_GET['player_token'];
  }
  if (!$token || !is_string($token)) {
    error('player_token required', 401);
  }
  if (!preg_match('/^[a-f0-9]{16,64}$/', $token)) {
    error('Invalid player_token format', 401);
  }

  $stmt = $mysqli->prepare("
    SELECT p.*,
           g.status        AS g_status,
           g.phase         AS g_phase,
           g.variant       AS g_variant,
           g.round_number  AS g_round_number,
           g.current_seat  AS g_current_seat,
           g.host_player_id AS g_host_player_id,
           g.state_version AS g_state_version
      FROM vg_game_players p
      JOIN vg_games g ON g.game_id = p.game_id
     WHERE p.player_token = ?
     LIMIT 1
  ");
  if (!$stmt) error('DB prepare failed: ' . $mysqli->error, 500);
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) error('Unknown player_token', 401);

  $touch = $mysqli->prepare("UPDATE vg_game_players SET last_seen_at = NOW() WHERE player_id = ?");
  $touch->bind_param('i', $row['player_id']);
  @$touch->execute();
  $touch->close();

  $row['game_id']     = (int) $row['game_id'];
  $row['seat']        = (int) $row['seat'];
  $row['player_id']   = (int) $row['player_id'];
  $row['is_host']     = ((int) $row['g_host_player_id'] === (int) $row['player_id']);
  return $row;
}

/**
 * Admin gate for the operator-only endpoints (export-everything, purge).
 * The shared secret lives in dbConfig.php on the server as ADMIN_TOKEN.
 * If the constant is absent, admin endpoints stay closed rather than open.
 */
function require_admin() {
  $supplied = '';
  if (isset($_GET['admin_token'])) {
    $supplied = (string) $_GET['admin_token'];
  } else {
    $body = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? read_json_body() : [];
    if (isset($body['admin_token'])) $supplied = (string) $body['admin_token'];
  }
  if (!defined('ADMIN_TOKEN') || ADMIN_TOKEN === '') {
    error('Admin endpoints are disabled: define ADMIN_TOKEN in dbConfig.php', 403);
  }
  if (!hash_equals((string) ADMIN_TOKEN, $supplied)) {
    error('Forbidden', 403);
  }
}

// ---------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------

/**
 * Atomically increment the game version. The client polls full public
 * state every 1.5s and only re-renders when this changes. Call AFTER the
 * transaction commits, so pollers never observe a half-written state.
 */
function bump_state_version($mysqli, $gameId) {
  $stmt = $mysqli->prepare("UPDATE vg_games SET state_version = state_version + 1 WHERE game_id = ?");
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $stmt->close();

  $stmt = $mysqli->prepare("SELECT state_version FROM vg_games WHERE game_id = ?");
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? (int) $row['state_version'] : 0;
}

// ---------------------------------------------------------------------
// Event log — every action, from day one.
// ---------------------------------------------------------------------

/**
 * Append one row to vg_event_log. Best-effort: a logging failure must
 * never break a turn, so errors are swallowed.
 *
 * @param int|null   $seat       NULL for engine/system events
 * @param string     $type       short machine key, e.g. vote_cast
 * @param string     $message    pre-rendered player-facing sentence
 * @param array|null $data       JSON detail for later analysis
 */
function log_event($mysqli, $gameId, $seat, $type, $message = '', $data = null, $playerName = null, $round = null, $phase = null) {
  $json = ($data === null) ? null : json_encode($data, JSON_UNESCAPED_UNICODE);
  $stmt = $mysqli->prepare("
    INSERT INTO vg_event_log
      (game_id, seat, player_name, round_number, phase, event_type, message, event_data)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) return;
  $seatVal = ($seat === null) ? null : (int) $seat;
  $stmt->bind_param('iisissss', $gameId, $seatVal, $playerName, $round, $phase, $type, $message, $json);
  @$stmt->execute();
  $stmt->close();
}

// ---------------------------------------------------------------------
// Row load / save. The engine works on plain PHP arrays; these convert
// to and from the database, decoding the JSON columns on the way in and
// re-encoding on the way out.
// ---------------------------------------------------------------------

/** Decode a JSON TEXT column to an array, tolerating NULL and junk. */
function json_col($raw, $default = []) {
  if ($raw === null || $raw === '') return $default;
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : $default;
}

/**
 * Load a game row. $forUpdate=true takes the write lock — required for
 * every mutating path, and the reason two players cannot interleave a
 * half-applied turn.
 */
function load_game($mysqli, $gameId, $forUpdate = false) {
  $sql = "SELECT * FROM vg_games WHERE game_id = ?" . ($forUpdate ? " FOR UPDATE" : "");
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) return null;

  $row['game_id']       = (int) $row['game_id'];
  $row['max_players']   = (int) $row['max_players'];
  $row['round_number']  = (int) $row['round_number'];
  $row['state_version'] = (int) $row['state_version'];
  $row['current_seat']  = ($row['current_seat'] === null) ? null : (int) $row['current_seat'];
  $row['winner_seat']   = ($row['winner_seat'] === null) ? null : (int) $row['winner_seat'];
  $row['config']        = json_col($row['config']);
  $row['state']         = json_col($row['state']);
  return $row;
}

/** Write back the mutable columns of a game array loaded by load_game(). */
function save_game($mysqli, $game) {
  $config = json_encode($game['config'], JSON_UNESCAPED_UNICODE);
  $state  = json_encode($game['state'], JSON_UNESCAPED_UNICODE);
  $stmt = $mysqli->prepare("
    UPDATE vg_games
       SET status = ?, phase = ?, round_number = ?, current_seat = ?,
           config = ?, state = ?, ended_reason = ?, winner_seat = ?,
           ended_at = CASE WHEN ? = 'ended' AND ended_at IS NULL THEN NOW() ELSE ended_at END
     WHERE game_id = ?
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param(
    'ssiisssisi',
    $game['status'], $game['phase'], $game['round_number'], $game['current_seat'],
    $config, $state, $game['ended_reason'], $game['winner_seat'],
    $game['status'], $game['game_id']
  );
  if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    throw new Exception('Failed to save game: ' . $err);
  }
  $stmt->close();
}

/** All seats of a game, ordered by seat, keyed by seat index. */
function load_players($mysqli, $gameId) {
  $stmt = $mysqli->prepare("SELECT * FROM vg_game_players WHERE game_id = ? ORDER BY seat ASC");
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $res = $stmt->get_result();
  $players = [];
  while ($row = $res->fetch_assoc()) {
    $row['player_id']       = (int) $row['player_id'];
    $row['game_id']         = (int) $row['game_id'];
    $row['seat']            = (int) $row['seat'];
    $row['score']           = (int) $row['score'];
    $row['conceded']        = (int) $row['conceded'];
    $row['is_bot']          = (int) $row['is_bot'];
    $row['final_score']     = ($row['final_score'] === null) ? null : (int) $row['final_score'];
    $row['public_state']    = json_col($row['public_state']);
    $row['private_state']   = json_col($row['private_state']);
    $row['score_breakdown'] = json_col($row['score_breakdown']);
    $players[$row['seat']] = $row;
  }
  $stmt->close();
  return $players;
}

/** Write back the mutable columns of one player array. */
function save_player($mysqli, $p) {
  $pub  = json_encode($p['public_state'], JSON_UNESCAPED_UNICODE);
  $priv = json_encode($p['private_state'], JSON_UNESCAPED_UNICODE);
  $brk  = json_encode($p['score_breakdown'], JSON_UNESCAPED_UNICODE);
  $stmt = $mysqli->prepare("
    UPDATE vg_game_players
       SET player_name = ?, public_state = ?, private_state = ?,
           score = ?, final_score = ?, score_breakdown = ?, conceded = ?
     WHERE player_id = ?
  ");
  if (!$stmt) throw new Exception('DB prepare failed: ' . $mysqli->error);
  $stmt->bind_param(
    'sssiisii',
    $p['player_name'], $pub, $priv,
    $p['score'], $p['final_score'], $brk, $p['conceded'],
    $p['player_id']
  );
  if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    throw new Exception('Failed to save player: ' . $err);
  }
  $stmt->close();
}

/**
 * The last N events of a game, oldest first — what the in-game feed shows.
 */
function recent_events($mysqli, $gameId, $limit = 60) {
  $limit = max(1, min(500, (int) $limit));
  $stmt = $mysqli->prepare("
    SELECT event_id, seat, player_name, round_number, phase, event_type, message, created_at
      FROM vg_event_log
     WHERE game_id = ?
     ORDER BY event_id DESC
     LIMIT " . $limit
  );
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $r['event_id'] = (int) $r['event_id'];
    $r['seat'] = ($r['seat'] === null) ? null : (int) $r['seat'];
    $rows[] = $r;
  }
  $stmt->close();
  return array_reverse($rows);
}

/** Every event of a game, oldest first, with the JSON detail — for exports. */
function all_events($mysqli, $gameId) {
  $stmt = $mysqli->prepare("
    SELECT event_id, seat, player_name, round_number, phase, event_type,
           message, event_data, created_at
      FROM vg_event_log
     WHERE game_id = ?
     ORDER BY event_id ASC
  ");
  $stmt->bind_param('i', $gameId);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $r['event_id']   = (int) $r['event_id'];
    $r['seat']       = ($r['seat'] === null) ? null : (int) $r['seat'];
    $r['event_data'] = json_col($r['event_data'], null);
    $rows[] = $r;
  }
  $stmt->close();
  return $rows;
}
