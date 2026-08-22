<?php
/**
 * submitReport.php — POST. File a playtest report: free-form notes plus
 * an optional 1..5 rating, stored with a snapshot of the game as it
 * stood when the report was filed.
 *
 * Body: { player_token, rating?: 1..5, notes?: string }
 *
 * The snapshot is the point: a complaint about the endgame is unreadable
 * six games later without the position that produced it.
 */
require_once __DIR__ . '/engine.php';

require_method('POST');
$me = authenticate($mysqli);
$gameId = (int) $me['game_id'];
$body = read_json_body();

$rating = isset($body['rating']) ? (int) $body['rating'] : null;
if ($rating !== null && ($rating < 1 || $rating > 5)) error('Rating must be 1 to 5', 400);

$notes = trim((string) ($body['notes'] ?? ''));
if ($notes === '' && $rating === null) error('Add a note or a rating', 400);
if (mb_strlen($notes) > 8000) $notes = mb_substr($notes, 0, 8000);

$game = load_game($mysqli, $gameId);
if (!$game) error('Game not found', 404);
$players = load_players($mysqli, $gameId);

// Compact snapshot — enough to read the report against, without storing
// a second copy of the whole event log (exportGame.php has that).
$snapshot = [
  'taken_at'     => gmdate('c'),
  'status'       => $game['status'],
  'phase'        => $game['phase'],
  'round_number' => (int) $game['round_number'],
  'current_seat' => $game['current_seat'],
  'winner_seat'  => $game['winner_seat'],
  'ended_reason' => $game['ended_reason'],
  'config'       => $game['config'],
  'board'        => $game['state'],
  'scores'       => array_map(function ($p) {
    return [
      'seat'        => (int) $p['seat'],
      'player_name' => $p['player_name'],
      'score'       => (int) $p['score'],
      'final_score' => $p['final_score'],
      'conceded'    => (bool) $p['conceded'],
    ];
  }, array_values($players)),
];
$snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

$stmt = $mysqli->prepare("
  INSERT INTO vg_playtest_reports
    (game_id, seat, player_name, variant, rating, notes, snapshot)
  VALUES (?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) error('DB prepare failed: ' . $mysqli->error, 500);
$seat = (int) $me['seat'];
$stmt->bind_param('iississ', $gameId, $seat, $me['player_name'], $game['variant'], $rating, $notes, $snapshotJson);
if (!$stmt->execute()) {
  $err = $stmt->error;
  $stmt->close();
  error('Failed to save report: ' . $err, 500);
}
$reportId = (int) $mysqli->insert_id;
$stmt->close();

log_event($mysqli, $gameId, $seat, 'playtest_report',
  $me['player_name'] . ' filed a playtest report.',
  ['report_id' => $reportId, 'rating' => $rating],
  $me['player_name'], (int) $game['round_number'], $game['phase']);

json(['ok' => true, 'report_id' => $reportId]);
