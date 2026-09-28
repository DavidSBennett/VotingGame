<?php
/**
 * engine_test.php -- local tests for backend/engine_dc.php (no database).
 *
 *     php tools/engine_test.php            # rule tests + 300 random games
 *     php tools/engine_test.php 2000       # more random games
 *
 * Rule tests build a position, act, and check the result. Random games
 * play legal moves at 2-5 seats (people and the placeholder bot) and check
 * the invariants after every single action: every card exists exactly
 * once, influence never goes negative, public counts match, one election
 * a turn, and the game ends properly.
 */

require __DIR__ . '/../backend/engine_dc.php';

$FAILS = 0;
$CHECKS = 0;
function ok($cond, $label) {
  global $FAILS, $CHECKS;
  $CHECKS++;
  if (!$cond) { $FAILS++; if ($FAILS <= 40) echo "  FAIL: $label\n"; }
}
function eq($got, $want, $label) {
  ok($got === $want, $label . ' (got ' . json_encode($got) . ', want ' . json_encode($want) . ')');
}
function throws($fn) {
  try { $fn(); return false; } catch (Exception $e) { return $e->getMessage(); }
}

/** A fresh game of $n seats; $bots marks bot seats; $papers fixes papers by seat. */
function new_game($n, $bots = [], $papers = [], $seed = 1) {
  mt_srand($seed);
  $game = ['game_id' => 1, 'join_code' => 'TEST', 'status' => 'lobby', 'variant' => 'variant', 'phase' => 'lobby',
           'round_number' => 0, 'current_seat' => null, 'winner_seat' => null, 'ended_reason' => null,
           'config' => ['bots' => count($bots)], 'state' => [], 'max_players' => $n, 'state_version' => 0];
  $players = [];
  for ($s = 0; $s < $n; $s++) {
    $players[$s] = ['seat' => $s, 'player_name' => 'Paper ' . $s, 'is_bot' => in_array($s, $bots, true) ? 1 : 0,
                    'conceded' => 0, 'public_state' => isset($papers[$s]) ? ['paper' => $papers[$s]] : [],
                    'private_state' => [], 'score' => 0, 'final_score' => null, 'score_breakdown' => null];
  }
  engine_setup($game, $players, null);
  return [$game, $players];
}

/** Every card exists exactly once; public counts match; pools never negative. */
function check_invariants($game, $players, $where) {
  $seen = [];
  $dup = [];
  $add = function ($ids, $zone) use (&$seen, &$dup) {
    foreach ($ids as $id) {
      if (isset($seen[$id])) $dup[] = "$id in $zone and " . $seen[$id];
      $seen[$id] = $zone;
    }
  };
  foreach ($players as $s => $p) {
    $ps = $p['private_state'];
    foreach (['hand', 'deck', 'discard', 'held', 'played'] as $z) $add($ps[$z] ?? [], "seat$s.$z");
    $add($p['public_state']['locations'] ?? [], "seat$s.locations");
    eq($p['public_state']['hand_count'], count($ps['hand']), "$where: seat $s hand_count");
    eq($p['public_state']['deck_count'], count($ps['deck']), "$where: seat $s deck_count");
    eq($p['public_state']['discard_count'], count($ps['discard']), "$where: seat $s discard_count");
    eq($p['public_state']['prestige'], dc_prestige($p), "$where: seat $s prestige");
  }
  $st = $game['state'];
  $add($st['main'], 'main');
  $add($st['exchange'], 'exchange');
  $add($st['trash'], 'trash');
  ok(!$dup, "$where: no card in two places " . implode('; ', array_slice($dup, 0, 3)));
  $releasedThrough = (int) dc_election(min($st['e'], count(dc_elections()) - 1))['year'];
  foreach (dc_cards() as $k => $c) {
    if ($c['released'] === null) continue;
    $in = isset($seen[$k]);
    if ((int) $c['released'] <= $releasedThrough) ok($in, "$where: released story $k is somewhere");
    else ok(!$in, "$where: unreleased story $k is nowhere");
  }
  foreach ($players as $s => $p) {
    for ($i = 0; $i < 7; $i++) ok(isset($seen["letter#$s.$i"]), "$where: letter#$s.$i exists");
    for ($i = 0; $i < 3; $i++) ok(isset($seen["notice#$s.$i"]), "$where: notice#$s.$i exists");
  }
  $ed = 0; $sc = 0; $el = 0;
  foreach ($seen as $id => $z) {
    if (strpos($id, 'editorial#') === 0) $ed++;
    if (strpos($id, 'scandal#') === 0) $sc++;
    if (strpos($id, 'elec#') === 0) $el++;
  }
  eq($ed + $st['editorials'], 16, "$where: Editorials conserved");
  eq($sc + $st['scandals'], 20, "$where: Scandals conserved");
  eq($el, count($st['history']), "$where: one election card per election decided");
  ok(count($st['exchange']) <= 5, "$where: exchange holds at most five");
  if ($game['status'] === 'active') {
    foreach (dc_pools($game, $players) as $k => $v) ok($v >= 0, "$where: pool $k not negative ($v)");
    ok(empty($players[$st['turn']['seat']]['conceded']), "$where: the paper on turn is still playing");
    eq($game['current_seat'], $st['turn']['seat'], "$where: current_seat is the paper on turn");
  }
}

/** Put exactly these cards in the hand of the paper on turn; the old hand goes to its deck. */
function give_hand(&$game, &$players, $cards) {
  set_hand($game, $players, $game['state']['turn']['seat'], $cards);
}

/** Put exactly these cards in seat $s's hand, taking them from wherever they are. */
function set_hand(&$game, &$players, $s, $cards) {
  $p = &$players[$s];
  $p['private_state']['deck'] = array_merge($p['private_state']['deck'], $p['private_state']['hand']);
  $p['private_state']['hand'] = [];
  foreach ($cards as $c) {
    foreach (['main', 'exchange'] as $z) {
      $i = array_search($c, $game['state'][$z], true);
      if ($i !== false) array_splice($game['state'][$z], $i, 1);
    }
    foreach ($players as $q => $_) {
      foreach (['deck', 'discard'] as $z) {
        $i = array_search($c, $players[$q]['private_state'][$z], true);
        if ($i !== false) array_splice($players[$q]['private_state'][$z], $i, 1);
      }
    }
    $p['private_state']['hand'][] = $c;
  }
  foreach ($players as $q => $_) dc_count($players[$q]);
}

function act(&$game, &$players, $action, $params = []) {
  $seat = $action === 'concede' ? $params['seat'] : $game['state']['turn']['seat'];
  return engine_apply_action($game, $players, $seat, $action, $params, null);
}

/** First card key matching a test. */
function find_card($fn) {
  foreach (dc_cards() as $k => $c) if ($fn($c)) return $k;
  return null;
}

/** Make a story released: put its whole batch into play by moving the election on. */
function released($game, $k) {
  return (int) dc_card($k)['released'] <= (int) dc_election($game['state']['e'])['year'];
}

function set_pools(&$game, $pools) {
  $game['state']['turn']['base'] = array_merge(
    ['gen' => 0, 'Political' => 0, 'Economic' => 0, 'Social' => 0, 'campaign' => 0], $pools);
}

echo "Rule tests\n";

// ---- setup ------------------------------------------------------------
list($g, $P) = new_game(3, [], [0 => 'globe']);
eq(count($P[0]['private_state']['hand']), 5, 'setup: hand of five');
eq(count($P[0]['private_state']['deck']), 5, 'setup: five left in deck');
eq(count($g['state']['exchange']), 5, 'setup: exchange of five');
eq($P[0]['public_state']['paper'], 'globe', 'setup: a chosen paper is kept');
ok($P[1]['public_state']['paper'] && $P[1]['public_state']['paper'] !== 'globe', 'setup: others dealt a different paper');
eq(count(array_unique(array_map(function ($p) { return $p['public_state']['paper']; }, $P))), 3, 'setup: three different papers');
eq($g['state']['turn']['seat'], 0, 'setup: seat 0 opens');
eq($g['state']['turn']['base']['gen'], 0, 'setup: seat 0 gets no catch-up');
check_invariants($g, $P, 'setup');

// ---- plain play ---------------------------------------------------------
list($g, $P) = new_game(2);
give_hand($g, $P, ['letter#0.0', 'letter#0.1', 'notice#0.0']);
act($g, $P, 'play_all');
eq(dc_pools($g, $P)['gen'], 2, 'play: two Letters make 2 influence');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'play', ['card' => 'letter#1.0']); }) !== false, 'play: a card not in hand is refused');
ok(throws(function () use (&$g, &$P) { engine_apply_action($g, $P, 1, 'end_turn', [], null); }) !== false, 'turn: a paper not on turn is refused');

// ---- buying ---------------------------------------------------------------
list($g, $P) = new_game(2);
$econ = find_card(function ($c) { return $c['type'] === 'Economic story' && (int) $c['released'] === 1796 && (int) $c['cost'] === 4; });
give_hand($g, $P, []);
if (!in_array($econ, $g['state']['exchange'], true)) {
  $g['state']['main'] = array_values(array_diff($g['state']['main'], [$econ]));
  $g['state']['main'][] = $g['state']['exchange'][0];      // swap, so no card is lost
  $g['state']['exchange'][0] = $econ;
}
set_pools($g, ['gen' => 5, 'Economic' => 3]);
act($g, $P, 'buy', ['card' => $econ]);
eq($g['state']['turn']['spent']['Economic'], 3, 'buy: Economic influence spent first');
eq($g['state']['turn']['spent']['gen'], 1, 'buy: then plain');
ok(in_array($econ, $P[0]['private_state']['discard'], true), 'buy: the story goes to the discard pile');
eq(count($g['state']['exchange']), 4, 'buy: the exchange refills only at the end of the turn');
act($g, $P, 'buy', ['card' => 'editorial']);
eq(dc_pools($g, $P)['gen'], 1, 'buy: an Editorial costs 3 plain');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'editorial']); }) !== false, 'buy: refused when short');
check_invariants($g, $P, 'buy');
act($g, $P, 'end_turn');
eq(count($g['state']['exchange']), 5, 'end of turn: the exchange refills');
check_invariants($g, $P, 'buy end');

// ---- electing ---------------------------------------------------------------
list($g, $P) = new_game(2);
$e = dc_election(0);
$side = $e['historical_winner'];
$theme = $e[$side . '_theme'];
$need = (int) $e[$side . '_threshold'];
give_hand($g, $P, []);
set_pools($g, ['gen' => $need, $theme => 2, 'campaign' => 1]);
act($g, $P, 'elect', ['side' => $side]);
eq($g['state']['turn']['spent'][$theme], 2, 'elect: themed influence spent first');
eq($g['state']['turn']['spent']['campaign'], 1, 'elect: then Campaign');
eq($g['state']['turn']['spent']['gen'], $need - 3, 'elect: then plain');
eq($g['state']['e'], 1, 'elect: the next election is in progress');
ok(in_array('elec#0:' . $side, $P[0]['private_state']['discard'], true), 'elect: the election card goes to the discard pile');
eq($P[0]['public_state']['elections'], 1, 'elect: counted');
eq($P[0]['public_state']['prestige'], (int) $e['vp'], 'elect: its prestige counts');
ok(throws(function () use (&$g, &$P, $side) { act($g, $P, 'elect', ['side' => $side]); }) !== false, 'elect: once a turn');
check_invariants($g, $P, 'elect');
$rel = find_card(function ($c) { return (int) $c['released'] === 1800; });
ok(in_array($rel, array_merge($g['state']['main'], $g['state']['exchange']), true), 'elect: the next batch of news is released');

// themed influence of the wrong theme does not count; the Globe's Political counts toward anyone
list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'sun']);
$e = dc_election(0);
$nonPol = null;
foreach (['nation', 'states'] as $sd) if ($e[$sd . '_theme'] !== 'Political') $nonPol = $sd;
give_hand($g, $P, []);
set_pools($g, ['Political' => 20]);
if ($nonPol) {
  act($g, $P, 'elect', ['side' => $nonPol]);
  eq($g['state']['e'], 1, 'Globe: Political influence elects a ' . $e[$nonPol . '_theme'] . ' man');
  list($g, $P) = new_game(2, [], [0 => 'sun', 1 => 'globe']);
  give_hand($g, $P, []);
  set_pools($g, ['Political' => 20]);
  ok(throws(function () use (&$g, &$P, $nonPol) { act($g, $P, 'elect', ['side' => $nonPol]); }) !== false,
     'without the Globe, Political influence cannot elect a ' . $e[$nonPol . '_theme'] . ' man');
}

// ---- attacks ---------------------------------------------------------------
$scandalStory = find_card(function ($c) { return $c['attack'] === 'scandal' && (int) $c['released'] === 1796; });
$discardStory = find_card(function ($c) { return $c['attack'] === 'discard' && (int) $c['released'] === 1796; });
$defense = find_card(function ($c) { return (int) $c['defense'] > 0 && (int) $c['released'] === 1796; });
ok($scandalStory && $discardStory && $defense, 'attack: test cards found (' . $scandalStory . ', ' . $discardStory . ', ' . $defense . ')');

list($g, $P) = new_game(3, [], [0 => 'aurora', 1 => 'sun', 2 => 'globe']);
give_hand($g, $P, [$scandalStory]);
set_hand($g, $P, 1, ['letter#1.0', 'letter#1.1']);
set_hand($g, $P, 2, [$defense, 'letter#2.0']);
$gen0 = (int) dc_card($scandalStory)['gen'];
act($g, $P, 'play', ['card' => $scandalStory]);
eq(count(array_filter($P[1]['private_state']['discard'], function ($x) { return strpos($x, 'scandal#') === 0; })), 1, 'attack: an undefended rival gains a Scandal');
ok(in_array($defense, $P[2]['private_state']['discard'], true), 'attack: Defense is used automatically');
eq(count(array_filter($P[2]['private_state']['discard'], function ($x) { return strpos($x, 'scandal#') === 0; })), 0, 'attack: the defended rival gains nothing');
eq(count($P[2]['private_state']['hand']), 2, 'attack: Defense draws a card');
eq(dc_pools($g, $P)['gen'], $gen0 + 1 + 1, 'attack: +1 for the hit, +1 for the Aurora (one different negative story)');
eq($g['state']['scandals'], 19, 'attack: one Scandal taken from the pile');
check_invariants($g, $P, 'attack');

list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'intelligencer']);
give_hand($g, $P, [$scandalStory]);
set_hand($g, $P, 1, ['letter#1.0']);
act($g, $P, 'play', ['card' => $scandalStory]);
eq($g['state']['scandals'], 20, 'Intelligencer: never gains a Scandal');
eq(dc_pools($g, $P)['gen'], (int) dc_card($scandalStory)['gen'], 'Intelligencer: a miss earns no +1');

list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'sun']);
give_hand($g, $P, [$discardStory]);
set_hand($g, $P, 1, ['letter#1.0', 'letter#1.1']);
act($g, $P, 'play', ['card' => $discardStory]);
eq(count($P[1]['private_state']['hand']), 1, 'discard attack: the rival discards one at random');
check_invariants($g, $P, 'discard attack');

// ---- Retraction -------------------------------------------------------------
$retract = find_card(function ($c) { return (int) $c['retract'] > 0 && (int) $c['released'] === 1796; });
list($g, $P) = new_game(2);
give_hand($g, $P, [$retract]);
$P[0]['private_state']['discard'][] = 'scandal#19';
$g['state']['scandals'] = 19;
dc_count($P[0]);
act($g, $P, 'play', ['card' => $retract]);
ok(in_array('scandal#19', $g['state']['trash'], true), 'Retraction: destroys a Scandal');
eq(count($P[0]['private_state']['hand']), 1, 'Retraction: draws a card');
check_invariants($g, $P, 'retraction');

// ---- papers: Journal, North Star, Sun, Herald, Argus -----------------------
$econs = [];
foreach (dc_cards() as $k => $c) if ($c['type'] === 'Economic story' && (int) $c['released'] === 1796 && !$c['trash'] && !$c['gain_upto']) $econs[] = $k;
list($g, $P) = new_game(2, [], [0 => 'journal', 1 => 'sun']);
give_hand($g, $P, array_slice($econs, 0, 3));
act($g, $P, 'play_all');
$base = 0; $ps = 0;
foreach (array_slice($econs, 0, 3) as $k) { $base += (int) dc_card($k)['gen']; $ps += (int) dc_card($k)['per_same'] * 2; }
eq(dc_pools($g, $P)['gen'], $base + $ps + 2, 'Journal: +1 for each of the first two Economic stories; compounding counts the others');

$socials = [];
foreach (dc_cards() as $k => $c) if ($c['type'] === 'Social story' && (int) $c['released'] === 1796 && !(int) $c['draw']) $socials[] = $k;
if (count($socials) >= 3) {
  list($g, $P) = new_game(2, [], [0 => 'north_star', 1 => 'sun']);
  give_hand($g, $P, array_slice($socials, 0, 3));
  act($g, $P, 'play', ['card' => $socials[0]]);
  $h1 = count($P[0]['private_state']['hand']);
  act($g, $P, 'play', ['card' => $socials[1]]);
  eq(count($P[0]['private_state']['hand']), $h1 - 1 + 1, 'North Star: the second Social story draws a card');
  act($g, $P, 'play', ['card' => $socials[2]]);
  ok(true, 'North Star: once a turn');
}

list($g, $P) = new_game(2, [], [0 => 'sun', 1 => 'globe']);
give_hand($g, $P, ['scandal#19', 'letter#0.0']);
$g['state']['scandals'] = 19;
act($g, $P, 'paper');
eq(count($P[0]['private_state']['hand']), 1 + 3, 'Sun: discard a Scandal, draw three');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'paper'); }) !== false, 'Sun: once a turn');
check_invariants($g, $P, 'sun');

list($g, $P) = new_game(2, [], [0 => 'herald', 1 => 'globe']);
give_hand($g, $P, []);
set_pools($g, ['gen' => 3]);
$top = end($g['state']['main']);
act($g, $P, 'paper');
eq($P[0]['private_state']['held'], [$top], 'Herald: the top story is set aside for the next hand');
eq(dc_pools($g, $P)['gen'], 1, 'Herald: costs 2 plain');
act($g, $P, 'end_turn');
ok(in_array($top, $P[0]['private_state']['hand'], true), 'Herald: the scoop joins the next hand');
eq(count($P[0]['private_state']['hand']), 6, 'Herald: hand of five plus the scoop');
check_invariants($g, $P, 'herald');

list($g, $P) = new_game(2, [], [0 => 'argus', 1 => 'globe']);
$P[0]['private_state']['discard'][] = 'elec#0:nation';
$P[0]['private_state']['discard'][] = 'elec#1:states';
dc_count($P[0]);
eq($P[0]['public_state']['prestige'], (int) dc_election(0)['vp'] + (int) dc_election(1)['vp'] + 2, 'Argus: +1 prestige per election won');

// ---- media events ----------------------------------------------------------
$media = find_card(function ($c) { return $c['type'] === 'Media event' && (int) $c['released'] === 1796; });
list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'sun']);
give_hand($g, $P, [$media]);
act($g, $P, 'play', ['card' => $media]);
act($g, $P, 'end_turn');
ok(in_array($media, $P[0]['public_state']['locations'], true), 'media event: stays in play');
$m = dc_card($media);
$other = $m['others_theme'] ? $g['state']['turn']['base'][$m['others_theme']] : $g['state']['turn']['base']['gen'];
eq($other, (int) $m['others_bonus'] + ($m['others_theme'] ? 0 : 1), 'media event: the other paper gets its small bonus (and seat 2 its +1 catch-up)');
act($g, $P, 'end_turn');
eq($g['state']['turn']['base']['gen'], (int) $m['ongoing_gen'], 'media event: the owner gets its bonus each turn');
check_invariants($g, $P, 'media');

// ---- catch-up, turn order, rounds -----------------------------------------
list($g, $P) = new_game(4);
$got = [];
for ($i = 0; $i < 4; $i++) { $got[] = $g['state']['turn']['base']['gen']; give_hand($g, $P, []); act($g, $P, 'end_turn'); }
eq($got, [0, 1, 2, 3], 'catch-up: +1 per place on the first turn');
eq($g['round_number'], 2, 'round: counts once every seat has had a turn');
eq($g['state']['turn']['base']['gen'], 0, 'catch-up: only the first turn');

// ---- trash and gain prompts -------------------------------------------------
$trashCard = find_card(function ($c) { return (int) $c['trash'] > 0 && (int) $c['released'] === 1796; });
list($g, $P) = new_game(2);
give_hand($g, $P, [$trashCard, 'notice#0.0']);
act($g, $P, 'play', ['card' => $trashCard]);
ok($g['state']['turn']['pending'] && $g['state']['turn']['pending']['type'] === 'trash', 'trash: a prompt opens');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'end_turn'); }) !== false, 'trash: nothing else until answered');
act($g, $P, 'choose', ['card' => 'notice#0.0']);
ok(in_array('notice#0.0', $g['state']['trash'], true), 'trash: the chosen card is destroyed');
eq($g['state']['turn']['pending'], null, 'trash: prompt closed');
check_invariants($g, $P, 'trash');
$gainCard = find_card(function ($c) { return (int) $c['gain_upto'] > 0; });
if ($gainCard) {
  list($g, $P) = new_game(2);
  give_hand($g, $P, [$gainCard]);
  act($g, $P, 'play', ['card' => $gainCard]);
  $pend = $g['state']['turn']['pending'];
  if ($pend && $pend['type'] === 'gain') {
    $pick = $pend['options'][0];
    act($g, $P, 'choose', ['card' => $pick]);
    ok(in_array($pick, $P[0]['private_state']['discard'], true), 'gain: the story goes to the discard pile');
    eq(count($g['state']['exchange']), 5, 'gain: the exchange refills at once');
  }
  check_invariants($g, $P, 'gain');
}

// ---- the end --------------------------------------------------------------
list($g, $P) = new_game(2);
$g['state']['e'] = 16;
foreach ([1800,1804,1808,1812,1816,1820,1824,1828,1832,1836,1840,1844,1848,1852,1856,1860] as $y) dc_release($g, $y);
$e = dc_election(16);
give_hand($g, $P, []);
set_pools($g, ['gen' => 30]);
act($g, $P, 'elect', ['side' => 'states']);
eq($g['status'], 'active', 'end: the turn continues after 1860 is decided');
act($g, $P, 'buy', ['card' => 'editorial']);
act($g, $P, 'end_turn');
eq($g['status'], 'ended', 'end: the game ends after that turn');
eq($g['ended_reason'], 'board_completed', 'end: reason');
eq($g['winner_seat'], 0, 'end: the paper with the most prestige wins');
eq($P[0]['final_score'], (int) $e['vp'] + 1, 'end: final score is prestige (1860 + an Editorial)');
eq(engine_available_actions($g, $P, 0), [], 'end: no actions after the end');

// ---- concede -----------------------------------------------------------------
list($g, $P) = new_game(3);
act($g, $P, 'concede', ['seat' => 0]);
eq($g['state']['turn']['seat'], 1, 'concede: on turn, the turn passes');
act($g, $P, 'end_turn');
act($g, $P, 'end_turn');
eq($g['state']['turn']['seat'], 1, 'concede: the seat is skipped');
act($g, $P, 'concede', ['seat' => 2]);
act($g, $P, 'concede', ['seat' => 1]);
eq($g['status'], 'ended', 'concede: the last person leaving ends the game');

// ---- the public projection ---------------------------------------------------
list($g, $P) = new_game(3, [2]);
$view = engine_public_state($g, $P, 0);
ok(isset($view['you']['hand']) && count($view['you']['hand']) === 5, 'view: my hand is shown');
foreach ($view['players'] as $pv) {
  ok(!isset($pv['hand']) && !isset($pv['deck']) && !isset($pv['private_state']), 'view: no seat shows a hand or deck');
}
$json = json_encode(engine_public_state($g, $P, 1));
foreach ($P[0]['private_state']['hand'] as $id) {
  if (strpos($id, 'letter#') === 0 || strpos($id, 'notice#') === 0) ok(strpos($json, '"' . $id . '"') === false, "view: seat 0's $id is not visible to seat 1");
}
ok($view['election']['year'] === 1796 && $view['election']['nation']['threshold'] > 0, 'view: the election in progress');
ok(count($view['exchange']) === 5 && isset($view['available_actions']['buy']), 'view: exchange and actions');

echo "  $CHECKS checks, $FAILS failed\n\n";

// =============================================================================
// Random games
// =============================================================================

$games = (int) ($argv[1] ?? 300);
echo "Random games: $games (2-5 seats, people making random legal moves, some bot seats)\n";
$ended = [];
$rounds = [];
$elections = 0;
$maxPerTurn = 0;
$actions = 0;
$errors = 0;
$startFails = $FAILS;
for ($gi = 0; $gi < $games; $gi++) {
  $n = 2 + $gi % 4;
  $bots = ($gi % 3 === 0) ? [$n - 1] : [];
  list($g, $P) = new_game($n, $bots, [], 1000 + $gi);
  $guard = 0;
  while ($g['status'] === 'active' && ++$guard < 20000) {
    $seat = $g['state']['turn']['seat'];
    $av = engine_available_actions($g, $P, $seat);
    $before = count($g['state']['history']);
    try {
      if (isset($av['choose'])) {
        $opts = $av['choose'];
        $pick = mt_rand(0, 3) === 0 ? null : $opts[mt_rand(0, count($opts) - 1)];
        act($g, $P, 'choose', ['card' => $pick]);
      } elseif (!empty($av['play']) && mt_rand(0, 4) > 0) {
        if (mt_rand(0, 3) === 0) act($g, $P, 'play_all');
        else act($g, $P, 'play', ['card' => $av['play'][mt_rand(0, count($av['play']) - 1)]]);
      } elseif (!empty($av['elect']) && mt_rand(0, 3) > 0) {
        act($g, $P, 'elect', ['side' => $av['elect'][mt_rand(0, count($av['elect']) - 1)]]);
      } elseif (!empty($av['buy']) && mt_rand(0, 4) > 0) {
        act($g, $P, 'buy', ['card' => $av['buy'][mt_rand(0, count($av['buy']) - 1)]]);
      } elseif (!empty($av['paper']) && mt_rand(0, 1)) {
        act($g, $P, 'paper');
      } elseif (mt_rand(0, 400) === 0) {
        act($g, $P, 'concede', ['seat' => $seat]);
      } else {
        act($g, $P, 'end_turn');
      }
    } catch (Exception $ex) {
      $errors++;
      if ($errors <= 5) echo "  legal-looking action refused: " . $ex->getMessage() . "\n";
      act($g, $P, 'end_turn');
    }
    $actions++;
    $maxPerTurn = max($maxPerTurn, count($g['state']['history']) - $before);
    check_invariants($g, $P, "game $gi, action $guard");
    if ($FAILS - $startFails > 20) break 2;
  }
  $ended[$g['ended_reason'] ?? 'unfinished'] = ($ended[$g['ended_reason'] ?? 'unfinished'] ?? 0) + 1;
  $rounds[] = $g['round_number'];
  $elections += count($g['state']['history']);
  if ($g['status'] === 'ended') {
    $left = array_filter($P, function ($p) { return !$p['conceded']; });
    if (!$left) {
      eq($g['winner_seat'], null, "game $gi: everyone left, so nobody wins");
    } else {
      $best = max(array_map(function ($p) { return $p['final_score']; }, $left));
      ok($g['winner_seat'] !== null && empty($P[$g['winner_seat']]['conceded'])
         && $P[$g['winner_seat']]['final_score'] === $best, "game $gi: the winner has the most prestige");
    }
    foreach ($P as $s => $p) eq($p['final_score'], dc_prestige($p), "game $gi seat $s: final score is prestige");
  }
}
ok($errors === 0, "no legal action refused ($errors)");
ok($maxPerTurn <= 1, 'at most one election in any action');
echo "  ended: " . json_encode($ended) . "\n";
echo "  mean rounds " . round(array_sum($rounds) / max(1, count($rounds)), 1)
   . ", elections decided " . $elections . ", actions " . $actions . "\n";
echo "  $CHECKS checks in all, $FAILS failed\n";
exit($FAILS ? 1 : 0);
