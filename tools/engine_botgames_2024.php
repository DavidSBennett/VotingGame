<?php
/**
 * engine_botgames_2024.php -- play all-bot games on backend/engine_2024.php
 * and print one JSON line per game. Used by tools/parity_2024.py to compare
 * the engine with tools/simulate_2024.py.
 *
 *     php tools/engine_botgames_2024.php <games> <seed> <style,style,...>
 *
 * Styles are simulator bot names (balanced, attacker, ...), one per seat;
 * a style may carry a paper: "balanced@sun".
 */

require __DIR__ . '/../backend/engine_2024.php';

$games = (int) ($argv[1] ?? 100);
$seed = (int) ($argv[2] ?? 1);
$styles = explode(',', $argv[3] ?? 'balanced,balanced,balanced');

for ($gi = 0; $gi < $games; $gi++) {
  mt_srand($seed * 100003 + $gi);
  $n = count($styles);
  $game = ['game_id' => 1, 'join_code' => 'BOTS', 'status' => 'lobby', 'variant' => '2024', 'phase' => 'lobby',
           'round_number' => 0, 'current_seat' => null, 'winner_seat' => null, 'ended_reason' => null,
           'config' => [], 'state' => [], 'max_players' => $n, 'state_version' => 0];
  $players = [];
  foreach ($styles as $s => $spec) {
    $parts = explode('@', $spec);
    $pub = ['bot_style' => $parts[0]];
    if (!empty($parts[1])) $pub['paper'] = $parts[1];
    $players[$s] = ['seat' => $s, 'player_name' => 'Bot ' . $s, 'is_bot' => 1, 'conceded' => 0,
                    'public_state' => $pub, 'private_state' => [], 'score' => 0, 'final_score' => null,
                    'score_breakdown' => null];
  }
  engine_setup($game, $players, null);
  // engine_run_bots waits for a person; with none, drive the bots directly.
  $guard = 0;
  while ($game['status'] === 'active' && ++$guard < 5000) e24_bot_turn($game, $players, null);
  $w = $game['state']['winner_side'];
  $out = ['rounds' => (int) $game['round_number'], 'ended' => $game['ended_reason'], 'winner_side' => $w, 'seats' => []];
  foreach ($players as $s => $p) {
    $won = 0;
    foreach ($p['private_state']['staked'] as $st) if ($st['side'] === $w) $won++;
    $out['seats'][] = [
      'score' => (int) $p['final_score'], 'called' => (int) $p['public_state']['called'],
      'states_bought' => (int) $p['public_state']['states_bought'], 'bought' => (int) $p['public_state']['bought'],
      'stakes' => count($p['private_state']['staked']), 'stakes_won' => $won,
      'attacks' => (int) $p['public_state']['attacks'], 'paper' => $p['public_state']['paper'],
    ];
  }
  $claims = $game['state']['claims'];
  $out['claims'] = count($claims);
  $out['unhistorical'] = count(array_filter(array_keys($claims), function ($k) use ($claims) {
    return $claims[$k]['side'] !== e24_state($k)['winner'];
  }));
  echo json_encode($out), "\n";
}
