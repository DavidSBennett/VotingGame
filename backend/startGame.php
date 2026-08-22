<?php
/**
 * startGame.php — POST. Host closes the table and deals the opening
 * position through engine_setup(). One-way: no un-starting.
 *
 * Body: { player_token }
 * Response: { ok, status: 'active' }
 */
require_once __DIR__ . '/engine.php';

require_method('POST');
$me = authenticate($mysqli);
$gameId = (int) $me['game_id'];
if (empty($me['is_host'])) error('Only the host can start the game', 403);

$mysqli->begin_transaction();
try {
  $game = load_game($mysqli, $gameId, true);
  if (!$game) throw new Exception('Game not found');
  if ($game['status'] !== 'lobby') throw new Exception('That game has already started');

  $players = load_players($mysqli, $gameId);
  $minPlayers = (int) ($game['config']['min_players'] ?? engine_default_config()['min_players']);
  if (count($players) < $minPlayers) {
    throw new Exception('Need at least ' . $minPlayers . ' players to start');
  }

  engine_setup($game, $players);

  save_game($mysqli, $game);
  foreach ($players as $p) save_player($mysqli, $p);
  $mysqli->commit();
} catch (Exception $e) {
  $mysqli->rollback();
  error($e->getMessage(), 400);
}

log_event($mysqli, $gameId, $me['seat'], 'game_started',
  'The game began with ' . count($players) . ' players.',
  ['players' => count($players), 'config' => $game['config']],
  $me['player_name'], 1, 'main');
bump_state_version($mysqli, $gameId);

json(['ok' => true, 'status' => 'active']);
