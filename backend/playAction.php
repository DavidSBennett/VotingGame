<?php
/**
 * playAction.php — POST. THE action endpoint. Every rules-affecting move
 * a player makes arrives here and is dispatched by the engine.
 *
 * Body: { player_token, action, params: { … } }
 *   finance     params { card }
 *   sway        params { card, candidate }
 *   transition  params { card }            (key cards only)
 *   concede     params {}
 *
 * Response: { ok, message, state_version }
 *
 * One endpoint rather than one per move, because the engine already has
 * to validate turn ownership and legality centrally: a second place to
 * enforce a rule is a second place for it to drift.
 *
 * Rival papers play inside the SAME transaction, so a solo player gets
 * the whole round back in one response rather than watching seats tick
 * over on the poll.
 */
require_once __DIR__ . '/engine.php';

require_method('POST');
$me = authenticate($mysqli);
$gameId = (int) $me['game_id'];
$seat   = (int) $me['seat'];
$body   = read_json_body();

$action = (string) ($body['action'] ?? '');
if ($action === '') error('An action is required', 400);
if (!preg_match('/^[a-z0-9_]{1,40}$/', $action)) error('Invalid action key', 400);
$params = is_array($body['params'] ?? null) ? $body['params'] : [];

$mysqli->begin_transaction();
try {
  $game = load_game($mysqli, $gameId, true);   // write lock: single writer
  if (!$game) throw new Exception('Game not found');
  $players = load_players($mysqli, $gameId);

  $message = engine_apply_action($game, $players, $seat, $action, $params, $mysqli);
  engine_run_bots($game, $players, $mysqli);

  save_game($mysqli, $game);
  foreach ($players as $p) save_player($mysqli, $p);
  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 400);
}

// After the commit: the board is written outside the game transaction so
// a scoreboard failure can never roll back a legal turn.
if ($game['status'] === 'ended') {
  engine_record_scores($mysqli, $game, $players);
}

$version = bump_state_version($mysqli, $gameId);
json(['ok' => true, 'message' => $message, 'state_version' => $version]);
