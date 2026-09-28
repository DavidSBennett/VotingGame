<?php
/**
 * engine_botgames.php -- play all-bot games on backend/engine_dc.php and
 * print one JSON line per game. Used by tools/parity_dc.py to compare the
 * engine with tools/simulate_dc.py.
 *
 *     php tools/engine_botgames.php <games> <seed> <style,style,...>
 *
 * Styles are simulator bot names (balanced, attacker, ...), one per seat;
 * a style may carry a paper: "balanced@sun".
 */

require __DIR__ . '/../backend/engine_dc.php';

$games = (int) ($argv[1] ?? 100);
$seed = (int) ($argv[2] ?? 1);
$styles = explode(',', $argv[3] ?? 'balanced,balanced,balanced');

for ($gi = 0; $gi < $games; $gi++) {
  mt_srand($seed * 100003 + $gi);
  $n = count($styles);
  $game = ['game_id' => 1, 'join_code' => 'BOTS', 'status' => 'lobby', 'variant' => 'variant', 'phase' => 'lobby',
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
  // engine_setup resets public_state; keep each seat's style.
  $keep = array_map(function ($p) { return $p['public_state']['bot_style']; }, $players);
  engine_setup($game, $players, null);
  foreach ($players as $s => $_) $players[$s]['public_state']['bot_style'] = $keep[$s];
  // engine_run_bots waits for a person; with none, drive the bots directly.
  $guard = 0;
  while ($game['status'] === 'active' && ++$guard < 2000) dc_bot_turn($game, $players, null);
  $out = ['rounds' => (int) $game['round_number'], 'ended' => $game['ended_reason'], 'seats' => []];
  foreach ($players as $s => $p) {
    $out['seats'][] = [
      'prestige' => dc_prestige($p), 'elections' => (int) $p['public_state']['elections'],
      'bought' => (int) $p['public_state']['bought'], 'attacks' => (int) $p['public_state']['attacks'],
      'scandals' => (int) $p['public_state']['scandals_taken'], 'paper' => $p['public_state']['paper'],
    ];
  }
  $out['unhistorical'] = count(array_filter($game['state']['history'], function ($h) { return !$h['matched_history']; }));
  $out['decided'] = count($game['state']['history']);
  echo json_encode($out), "\n";
}
