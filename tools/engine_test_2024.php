<?php
/**
 * engine_test_2024.php -- local tests for backend/engine_2024.php (no database).
 *
 *     php tools/engine_test_2024.php            # rule tests + 300 random games
 *     php tools/engine_test_2024.php 2000       # more random games
 *
 * Rule tests build a position, act, and check the result. Random games
 * play legal moves at 2-5 seats (people making random moves, and bots) and
 * check the invariants after every action: every card exists exactly once
 * (staked cards included), every claimed state's card exists and the tally
 * is their votes, currency never goes negative, public counts match, one
 * big state a turn, stakes never leak, and the game ends properly.
 */

require __DIR__ . '/../backend/engine_2024.php';

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

function new_game($n, $bots = [], $papers = null, $seed = 1) {
  // Rule tests fix the papers (the Globe changes what pays for what); random games pass [].
  if ($papers === null) $papers = [0 => 'journal', 1 => 'sun', 2 => 'argus', 3 => 'aurora', 4 => 'north_star'];
  mt_srand($seed);
  $game = ['game_id' => 1, 'join_code' => 'TEST', 'status' => 'lobby', 'variant' => '2024', 'phase' => 'lobby',
           'round_number' => 0, 'current_seat' => null, 'winner_seat' => null, 'ended_reason' => null,
           'config' => ['bots' => count($bots)], 'state' => [], 'max_players' => $n, 'state_version' => 0];
  $players = [];
  for ($s = 0; $s < $n; $s++) {
    $players[$s] = ['seat' => $s, 'player_name' => 'Outlet ' . $s, 'is_bot' => in_array($s, $bots, true) ? 1 : 0,
                    'conceded' => 0, 'public_state' => isset($papers[$s]) ? ['paper' => $papers[$s]] : [],
                    'private_state' => [], 'score' => 0, 'final_score' => null, 'score_breakdown' => null];
  }
  engine_setup($game, $players, null);
  return [$game, $players];
}

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
    $add(array_map(function ($st) { return $st['card']; }, $ps['staked'] ?? []), "seat$s.staked");
    $add($p['public_state']['locations'] ?? [], "seat$s.locations");
    eq($p['public_state']['hand_count'], count($ps['hand']), "$where: seat $s hand_count");
    eq($p['public_state']['deck_count'], count($ps['deck']), "$where: seat $s deck_count");
    eq($p['public_state']['discard_count'], count($ps['discard']), "$where: seat $s discard_count");
    eq($p['public_state']['stakes'], count($ps['staked'] ?? []), "$where: seat $s stake count");
  }
  $st = $game['state'];
  $add($st['main'], 'main');
  $add($st['exchange'], 'exchange');
  $add($st['trash'], 'trash');
  ok(!$dup, "$where: no card in two places " . implode('; ', array_slice($dup, 0, 3)));
  // Stories: released through the current step, and nowhere before.
  foreach (e24_cards() as $k => $c) {
    if ($c['step'] === null) continue;
    $in = isset($seen[$k]);
    if ((int) $c['step'] <= (int) $st['step']) ok($in, "$where: released story $k is somewhere");
    else ok(!$in, "$where: unreleased story $k is nowhere");
  }
  // States: an unclaimed main-deck state is in the main deck or on the
  // exchange; a claimed one exists exactly once, for its side; the big
  // states not yet called exist nowhere.
  $tally = ['trump' => 0, 'harris' => 0];
  foreach (e24_states() as $k => $s) {
    $cl = $st['claims'][$k] ?? null;
    if ($cl) {
      $tally[$cl['side']] += (int) $s['ev'];
      ok(isset($seen['st#' . $k . ':' . $cl['side']]), "$where: claimed $k exists for its side");
      ok(!isset($seen['st#' . $k]) && !isset($seen['st#' . $k . ':' . e24_other($cl['side'])]), "$where: claimed $k exists once");
    } elseif ($s['deck'] === 'main') {
      ok(isset($seen['st#' . $k]) && in_array($seen['st#' . $k], ['main', 'exchange'], true), "$where: unclaimed $k is for sale");
    } else {
      foreach (['st#' . $k, 'st#' . $k . ':trump', 'st#' . $k . ':harris'] as $id) ok(!isset($seen[$id]), "$where: uncalled big $k is nowhere");
    }
  }
  eq(e24_tally($game), $tally, "$where: the tally is the claimed votes");
  foreach ($players as $s => $p) {
    for ($i = 0; $i < 7; $i++) ok(isset($seen["letter#$s.$i"]), "$where: letter#$s.$i exists");
    for ($i = 0; $i < 3; $i++) ok(isset($seen["notice#$s.$i"]) || in_array("notice#$s.$i", $st['trash'], true), "$where: notice#$s.$i exists");
  }
  $ed = 0; $sc = 0;
  foreach ($seen as $id => $z) {
    if (strpos($id, 'editorial#') === 0) $ed++;
    if (strpos($id, 'scandal#') === 0) $sc++;
  }
  eq($ed + $st['editorials'], 16, "$where: Editorials conserved");
  eq($sc + $st['scandals'], 20, "$where: Scandals conserved");
  eq(count($st['history']), (int) $st['e'], "$where: one history line per big state called");
  ok(count($st['exchange']) <= 5, "$where: exchange holds at most five");
  if ($game['status'] === 'active') {
    foreach (e24_pools($game, $players) as $k => $v) ok($v >= 0, "$where: pool $k not negative ($v)");
    ok(empty($players[$st['turn']['seat']]['conceded']), "$where: the outlet on turn is still playing");
    eq($game['current_seat'], $st['turn']['seat'], "$where: current_seat is the outlet on turn");
    ok(!$st['final'] || $st['winner_side'] !== null, "$where: a final round has a winner side");
  }
}

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
  unset($p);
  foreach ($players as $q => $_) e24_count($players[$q]);
}

function give_hand(&$game, &$players, $cards) {
  set_hand($game, $players, $game['state']['turn']['seat'], $cards);
}

function act(&$game, &$players, $action, $params = []) {
  $seat = $action === 'concede' ? $params['seat'] : $game['state']['turn']['seat'];
  return engine_apply_action($game, $players, $seat, $action, $params, null);
}

function set_pools(&$game, $pools) {
  $game['state']['turn']['base'] = array_merge(['gen' => 0, 'rep' => 0, 'dem' => 0, 'campaign' => 0], $pools);
}

/** Put a main-deck card on the exchange (swapping one back, so nothing is lost). */
function to_exchange(&$game, $id) {
  if (in_array($id, $game['state']['exchange'], true)) return;
  $i = array_search($id, $game['state']['main'], true);
  array_splice($game['state']['main'], $i, 1);
  $game['state']['main'][] = $game['state']['exchange'][0];
  $game['state']['exchange'][0] = $id;
}

function find_card($fn) {
  foreach (e24_cards() as $k => $c) if ($fn($c)) return $k;
  return null;
}

echo "Rule tests\n";

// ---- content ---------------------------------------------------------------
$ev = 0; $big = 0;
foreach (e24_states() as $s) { $ev += (int) $s['ev']; if ($s['deck'] === 'elections') $big++; }
eq([count(e24_states()), $ev, $big], [51, 538, 10], 'content: 51 contests, 538 votes, ten big states');
eq((int) e24_state('ca')['vp'], 12, 'content: California is worth 12 prestige');
eq([(int) e24_state('pa')['trump_threshold'], (int) e24_state('pa')['harris_threshold']], [9, 9], 'content: Pennsylvania costs the same either way');
ok((int) e24_state('dc')['trump_threshold'] > (int) e24_state('dc')['harris_threshold'] + 10, 'content: flipping D.C. is dear');

// ---- setup ----------------------------------------------------------------
list($g, $P) = new_game(3, [], [0 => 'globe']);
eq(count($P[0]['private_state']['hand']), 5, 'setup: hand of five');
eq(count($g['state']['exchange']), 5, 'setup: exchange of five');
eq(count($g['state']['big']), 10, 'setup: ten big states in the elections deck');
eq($P[0]['public_state']['paper'], 'globe', 'setup: a chosen paper is kept');
eq(e24_tally($g), ['trump' => 0, 'harris' => 0], 'setup: no votes claimed');
check_invariants($g, $P, 'setup');

// ---- currency from the hand; playing uses abilities -------------------------
/** A fresh turn's hand: these cards only, and their currency counted. */
function fresh_hand(&$game, &$players, $cards) {
  give_hand($game, $players, $cards);
  $game['state']['turn']['base'] = ['gen' => 0, 'rep' => 0, 'dem' => 0, 'campaign' => 0];
  $game['state']['turn']['counted'] = [];
  e24_count_hand($game, $players);
}
list($g, $P) = new_game(2);
$want = 0;
foreach ($P[0]['private_state']['hand'] as $id) $want += (int) e24_view($id)['gen'];
eq(e24_pools($g, $P)['gen'], $want, 'hand: the opening hand counts at once');
fresh_hand($g, $P, ['letter#0.0', 'letter#0.1', 'notice#0.0']);
eq(e24_pools($g, $P)['gen'], 2, 'hand: two Letters in hand make 2 neutral, unplayed');
eq(engine_available_actions($g, $P, 0)['play'], [], 'hand: Letters have no ability to use');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'play', ['card' => 'letter#0.0']); }) !== false, 'hand: playing a Letter is refused');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'play', ['card' => 'letter#1.0']); }) !== false, 'play: a card not in hand is refused');
ok(throws(function () use (&$g, &$P) { engine_apply_action($g, $P, 1, 'end_turn', [], null); }) !== false, 'turn: an outlet not on turn is refused');

$repStory = find_card(function ($c) { return $c['lean'] === 'rep' && (int) $c['themed'] > 0 && (int) $c['step'] === 0 && !(int) $c['per_office'] && $c['type'] !== 'Media event'; });
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$repStory]);
eq(e24_pools($g, $P)['rep'], (int) e24_card($repStory)['themed'], 'hand: a Republican story pays Republican from the hand');
eq(e24_pools($g, $P)['dem'], 0, '... and no Democratic');

$drawer = find_card(function ($c) { return (int) $c['draw'] > 0 && (int) $c['step'] === 0 && !(int) $c['trash'] && !$c['attack'] && !(int) $c['chain']; });
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$drawer]);
$before = e24_pools($g, $P);
foreach (['deck', 'discard'] as $z) {                          // the next draw is a Letter (+1)
  $P[0]['private_state'][$z] = array_values(array_diff($P[0]['private_state'][$z], ['letter#0.0']));
}
$P[0]['private_state']['deck'][] = 'letter#0.0';
e24_count($P[0]);
act($g, $P, 'play', ['card' => $drawer]);
eq(e24_pools($g, $P)['gen'], $before['gen'] + 1, 'ability: a drawn Letter counts at once; the played card is not counted twice');
check_invariants($g, $P, 'draw ability');

// ---- buying a story ---------------------------------------------------------
$demStory = find_card(function ($c) { return $c['lean'] === 'dem' && (int) $c['step'] === 0 && (int) $c['cost'] >= 3; });
list($g, $P) = new_game(2);
give_hand($g, $P, []);
to_exchange($g, $demStory);
$cost = (int) e24_card($demStory)['cost'];
set_pools($g, ['gen' => 5, 'dem' => 2, 'rep' => 9]);
act($g, $P, 'buy', ['card' => $demStory]);
eq($g['state']['turn']['spent']['dem'], 2, 'buy story: its party currency first');
eq($g['state']['turn']['spent']['gen'], $cost - 2, 'buy story: then neutral');
eq($g['state']['turn']['spent']['rep'], 0, 'buy story: never the other party');
ok(in_array($demStory, $P[0]['private_state']['discard'], true), 'buy story: to the discard pile');
check_invariants($g, $P, 'buy story');

// ---- buying a state for a side ---------------------------------------------
list($g, $P) = new_game(2);
ok(!in_array('st#tx', array_merge($g['state']['main'], $g['state']['exchange']), true), 'state: a big state is never in the main deck');
give_hand($g, $P, []);
to_exchange($g, 'st#wi');
$wi = e24_state('wi');
set_pools($g, ['gen' => 4, 'dem' => 2, 'rep' => 7]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#wi']); }) !== false, 'buy state: needs a side');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#wi', 'side' => 'harris']); }) !== false, 'buy state: Republican cannot pay for Harris');
act($g, $P, 'buy', ['card' => 'st#wi', 'side' => 'trump']);
eq($g['state']['turn']['spent']['rep'], (int) $wi['trump_threshold'], 'buy state: paid in Republican');
ok(in_array('st#wi:trump', $P[0]['private_state']['discard'], true), 'buy state: its Trump card to the discard pile');
eq(e24_tally($g), ['trump' => 10, 'harris' => 0], 'buy state: 10 votes for Trump');
eq($g['state']['claims']['wi']['seat'], 0, 'buy state: claimed by the buyer');
check_invariants($g, $P, 'buy state');

// Flipping costs by margin.
list($g, $P) = new_game(2);
give_hand($g, $P, []);
to_exchange($g, 'st#wy');
$wy = e24_state('wy');
set_pools($g, ['gen' => (int) $wy['trump_threshold']]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#wy', 'side' => 'harris']); }) !== false, 'flip: Wyoming for Harris costs more');
act($g, $P, 'buy', ['card' => 'st#wy', 'side' => 'trump']);
eq(e24_tally($g)['trump'], 3, 'flip: Wyoming for Trump at history\'s price');

// ---- calling the big state --------------------------------------------------
list($g, $P) = new_game(2);
$key = $g['state']['big'][0];
$s = e24_state($key);
give_hand($g, $P, []);
set_pools($g, ['gen' => (int) $s['harris_threshold'], 'dem' => 1, 'campaign' => 1, 'rep' => 30]);
act($g, $P, 'call', ['side' => 'harris']);
eq($g['state']['turn']['spent']['dem'], 1, 'call: party currency first');
eq($g['state']['turn']['spent']['campaign'], 1, 'call: then Campaign');
eq($g['state']['turn']['spent']['gen'], (int) $s['harris_threshold'] - 2, 'call: then neutral');
eq($g['state']['turn']['spent']['rep'], 0, 'call: never the other party');
eq($g['state']['e'], 1, 'call: the next big state is up');
eq($g['state']['step'], 1, 'call: the calendar moves on');
eq(e24_tally($g)['harris'], (int) $s['ev'], 'call: its votes for Harris');
ok(in_array('st#' . $key . ':harris', $P[0]['private_state']['discard'], true), 'call: the card to the discard pile');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'call', ['side' => 'trump']); }) !== false, 'call: once a turn');
$rel = find_card(function ($c) { return (int) $c['step'] === 1; });
ok(in_array($rel, array_merge($g['state']['main'], $g['state']['exchange']), true), 'call: the next stories are released');
check_invariants($g, $P, 'call');

// The Globe: either party's currency counts for either side.
list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'sun']);
give_hand($g, $P, []);
to_exchange($g, 'st#mi');
set_pools($g, ['dem' => 20]);
act($g, $P, 'buy', ['card' => 'st#mi', 'side' => 'trump']);
eq(e24_tally($g)['trump'], 15, 'Globe: Democratic currency buys Michigan for Trump');
list($g, $P) = new_game(2, [], [0 => 'sun', 1 => 'globe']);
give_hand($g, $P, []);
to_exchange($g, 'st#mi');
set_pools($g, ['dem' => 20]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#mi', 'side' => 'trump']); }) !== false, 'without the Globe it cannot');

// ---- stake --------------------------------------------------------------------
list($g, $P) = new_game(3);
$hand = $P[0]['private_state']['hand'];
eq(engine_available_actions($g, $P, 0)['stake'], $hand, 'stake: offered at the start of a turn');
act($g, $P, 'stake', ['card' => $hand[0], 'side' => 'harris']);
eq($P[0]['private_state']['staked'][0]['card'], $hand[0], 'stake: the card is staked');
eq($P[0]['private_state']['staked'][0]['side'], 'harris', 'stake: on its side');
eq(count($P[0]['private_state']['hand']), 5, 'stake: a fresh hand of five');
eq($g['state']['turn']['seat'], 1, 'stake: the turn passes');
eq($P[0]['public_state']['stakes'], 1, 'stake: the count is public');
$view = json_encode(engine_public_state($g, $P, 1));
ok(strpos($view, '"' . $hand[0] . '"') === false, 'stake: the staked card is not shown to a rival');
ok(!isset(engine_public_state($g, $P, 1)['players'][0]['score_breakdown']), 'stake: no breakdown before the end');
$mine = engine_public_state($g, $P, 0)['you']['staked'];
eq([count($mine), $mine[0]['side']], [1, 'harris'], 'stake: the staker sees it');
check_invariants($g, $P, 'stake');
list($g, $P) = new_game(2);
give_hand($g, $P, [$drawer, 'letter#0.1']);
act($g, $P, 'play', ['card' => $drawer]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'stake', ['card' => 'letter#0.1', 'side' => 'trump']); }) !== false, 'stake: not after playing');
eq(engine_available_actions($g, $P, 0)['stake'], [], 'stake: not offered after playing');

// ---- the race to 270 and the score -------------------------------------------
list($g, $P) = new_game(3);
// Seat 0 stakes California-sized prestige on Trump, seat 1 on Harris.
$P[0]['private_state']['staked'][] = ['card' => 'editorial#99', 'side' => 'trump', 'round' => 1];
$g['state']['editorials'] = 15;
$P[1]['private_state']['staked'][] = ['card' => 'letter#1.6', 'side' => 'harris', 'round' => 1];
$P[1]['private_state']['hand'] = array_values(array_diff($P[1]['private_state']['hand'], ['letter#1.6']));
$P[1]['private_state']['deck'] = array_values(array_diff($P[1]['private_state']['deck'], ['letter#1.6']));
foreach ($P as $s => $_) e24_count($P[$s]);
// Trump 265 claimed (by hand), then Nevada (6) takes him to 271.
$claimed = 0;
foreach (e24_states() as $k => $st) {
  if ($st['deck'] !== 'main' || $k === 'nv' || $claimed + (int) $st['ev'] > 265) continue;
  $i = array_search('st#' . $k, $g['state']['main'], true);
  if ($i !== false) array_splice($g['state']['main'], $i, 1);
  else { $i = array_search('st#' . $k, $g['state']['exchange'], true); if ($i === false) continue; array_splice($g['state']['exchange'], $i, 1); }
  $P[2]['private_state']['discard'][] = 'st#' . $k . ':trump';
  $g['state']['claims'][$k] = ['side' => 'trump', 'seat' => 2, 'how' => 'buy', 'round' => 1];
  $claimed += (int) $st['ev'];
}
e24_refill($g);
foreach ($P as $s => $_) e24_count($P[$s]);
check_invariants($g, $P, 'race setup');
$need = 270 - $claimed;
give_hand($g, $P, []);
to_exchange($g, 'st#nv');
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#nv', 'side' => 'trump']);
ok(e24_tally($g)['trump'] >= 270 || 6 < $need, 'race: Nevada takes Trump to 270');
if (e24_tally($g)['trump'] >= 270) {
  ok($g['state']['final'], 'race: the final round begins');
  eq($g['state']['winner_side'], 'trump', 'race: Trump wins');
  eq($g['status'], 'active', 'race: the round plays out');
  act($g, $P, 'end_turn');
  eq($g['status'], 'active', 'race: seat 1 still plays');
  act($g, $P, 'end_turn');
  eq($g['status'], 'active', 'race: seat 2 still plays');
  $last = $P[2]['private_state']['hand'][0];
  act($g, $P, 'stake', ['card' => $last, 'side' => 'trump']);
  eq($g['status'], 'ended', 'race: the game ends when the round is out');
  eq($g['ended_reason'], 'race_called', 'race: reason');
  eq($P[0]['final_score'], 1, 'score: the Editorial staked on Trump scores 1');
  eq($P[1]['final_score'], 0, 'score: the stake on Harris scores nothing');
  eq($P[2]['final_score'], (int) e24_view($last)['vp'], 'score: a last stake on the winner counts');
  $pub = engine_public_state($g, $P, 1);
  ok(count($pub['players'][0]['score_breakdown']['stakes']) === 1, 'end: the stakes are revealed');
  eq(engine_available_actions($g, $P, 0), [], 'end: no actions after the end');
}

// ---- trash spares states; the Herald skips states; the news cycle -------------
$trashCard = find_card(function ($c) { return (int) $c['trash'] > 0 && (int) $c['step'] === 0; });
list($g, $P) = new_game(2);
$P[0]['private_state']['discard'][] = 'st#' . $g['state']['big'][0] . ':trump';
$g['state']['claims'][$g['state']['big'][0]] = ['side' => 'trump', 'seat' => 0, 'how' => 'call', 'round' => 1];
$g['state']['history'][] = ['index' => 0];
$g['state']['e'] = 1;
$g['state']['step'] = 1;
e24_release($g, 1);
give_hand($g, $P, [$trashCard, 'notice#0.0']);
act($g, $P, 'play', ['card' => $trashCard]);
ok($g['state']['turn']['pending'] && !in_array('st#' . $g['state']['big'][0] . ':trump', $g['state']['turn']['pending']['options'], true), 'trash: a claimed state cannot be destroyed');
act($g, $P, 'choose', ['card' => 'notice#0.0']);
ok(in_array('notice#0.0', $g['state']['trash'], true), 'trash: the chosen card is destroyed');
check_invariants($g, $P, 'trash');

list($g, $P) = new_game(2, [], [0 => 'herald', 1 => 'globe']);
give_hand($g, $P, []);
set_pools($g, ['gen' => 3]);
foreach (['main', 'exchange'] as $z) {                 // Wyoming to the top of the main deck
  $i = array_search('st#wy', $g['state'][$z], true);
  if ($i !== false) array_splice($g['state'][$z], $i, 1);
}
$g['state']['main'][] = 'st#wy';
e24_refill($g);
ok(end($g['state']['main']) === 'st#wy' || in_array('st#wy', $g['state']['exchange'], true), 'Herald: (a state near the top)');
$top = $g['state']['main'][e24_top_story($g)];
act($g, $P, 'paper');
eq($P[0]['private_state']['held'], [$top], 'Herald: the topmost story is scooped, states passed over');
ok(!e24_is_state($top), 'Herald: never a state');
check_invariants($g, $P, 'herald');

list($g, $P) = new_game(2);
$oldest = $g['state']['exchange'][0];
give_hand($g, $P, []);
act($g, $P, 'end_turn');
ok(!in_array($oldest, $g['state']['exchange'], true) && $g['state']['main'][0] === $oldest, 'news cycle: the oldest card goes to the bottom of the main deck');
eq(count($g['state']['exchange']), 5, 'news cycle: the exchange is full again');

// ---- concede ------------------------------------------------------------------
list($g, $P) = new_game(3);
act($g, $P, 'concede', ['seat' => 0]);
eq($g['state']['turn']['seat'], 1, 'concede: on turn, the turn passes');
act($g, $P, 'end_turn');
act($g, $P, 'end_turn');
eq($g['state']['turn']['seat'], 1, 'concede: the seat is skipped');
act($g, $P, 'concede', ['seat' => 2]);
act($g, $P, 'concede', ['seat' => 1]);
eq($g['status'], 'ended', 'concede: the last person leaving ends the game');

// ---- the public projection ----------------------------------------------------
list($g, $P) = new_game(3, [2]);
$view = engine_public_state($g, $P, 0);
ok(count($view['you']['hand']) === 5, 'view: my hand is shown');
foreach ($view['players'] as $pv) ok(!isset($pv['hand']) && !isset($pv['deck']) && !isset($pv['private_state']), 'view: no seat shows a hand or deck');
ok($view['big']['ev'] > 0 && $view['big']['trump']['threshold'] > 0, 'view: the big state up');
eq(count($view['map']), 51, 'view: the map has every contest');
eq($view['race']['win_at'], 270, 'view: the race to 270');

echo "  $CHECKS checks, $FAILS failed\n\n";

// =============================================================================
// Random games
// =============================================================================

$games = (int) ($argv[1] ?? 300);
echo "Random games: $games (2-5 seats, people making random legal moves, some bot seats)\n";
$ended = [];
$rounds = [];
$calls = 0;
$maxPerTurn = 0;
$actions = 0;
$errors = 0;
$startFails = $FAILS;
for ($gi = 0; $gi < $games; $gi++) {
  $n = 2 + $gi % 4;
  $bots = ($gi % 3 === 0) ? [$n - 1] : (($gi % 3 === 1) ? range(1, $n - 1) : []);
  list($g, $P) = new_game($n, $bots, [], 1000 + $gi);
  $guard = 0;
  while ($g['status'] === 'active' && ++$guard < 20000) {
    $seat = $g['state']['turn']['seat'];
    $av = engine_available_actions($g, $P, $seat);
    $before = count($g['state']['history']);
    $turnsBefore = (int) $g['state']['turns'];
    try {
      if (isset($av['choose'])) {
        $opts = $av['choose'];
        $pick = mt_rand(0, 3) === 0 ? null : $opts[mt_rand(0, count($opts) - 1)];
        act($g, $P, 'choose', ['card' => $pick]);
      } elseif (!empty($av['stake']) && mt_rand(0, 9) === 0) {
        act($g, $P, 'stake', ['card' => $av['stake'][mt_rand(0, count($av['stake']) - 1)], 'side' => mt_rand(0, 1) ? 'trump' : 'harris']);
      } elseif (!empty($av['play']) && mt_rand(0, 4) > 0) {
        if (mt_rand(0, 3) === 0) act($g, $P, 'play_all');
        else act($g, $P, 'play', ['card' => $av['play'][mt_rand(0, count($av['play']) - 1)]]);
      } elseif (!empty($av['call']) && mt_rand(0, 3) > 0) {
        act($g, $P, 'call', ['side' => $av['call'][mt_rand(0, count($av['call']) - 1)]]);
      } elseif (!empty($av['buy']) && mt_rand(0, 4) > 0) {
        $b = $av['buy'][mt_rand(0, count($av['buy']) - 1)];
        if (e24_is_state($b)) act($g, $P, 'buy', ['card' => 'st#' . e24_state_key($b), 'side' => e24_card_side($b)]);
        else act($g, $P, 'buy', ['card' => $b]);
      } elseif (!empty($av['paper']) && mt_rand(0, 1)) {
        act($g, $P, 'paper');
      } elseif (mt_rand(0, 600) === 0) {
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
    // At most one big state per turn (an action can run several bot turns).
    $maxPerTurn = max($maxPerTurn, (count($g['state']['history']) - $before) - max(1, (int) $g['state']['turns'] - $turnsBefore + 1) + 1);
    check_invariants($g, $P, "game $gi, action $guard");
    if ($FAILS - $startFails > 20) break 2;
  }
  $ended[$g['ended_reason'] ?? 'unfinished'] = ($ended[$g['ended_reason'] ?? 'unfinished'] ?? 0) + 1;
  $rounds[] = $g['round_number'];
  $calls += count($g['state']['history']);
  if ($g['status'] === 'ended') {
    $left = array_filter($P, function ($p) { return !$p['conceded']; });
    if (!$left) {
      eq($g['winner_seat'], null, "game $gi: everyone left, so nobody wins");
    } else {
      $best = max(array_map(function ($p) { return $p['final_score']; }, $left));
      ok($g['winner_seat'] !== null && empty($P[$g['winner_seat']]['conceded'])
         && $P[$g['winner_seat']]['final_score'] === $best, "game $gi: the winner has the most prestige");
    }
    $w = $g['state']['winner_side'];
    if ($g['ended_reason'] === 'race_called') ok(e24_tally($g)[$w] >= 270, "game $gi: the winner side has 270");
    foreach ($P as $s => $p) {
      $want = 0;
      foreach ($p['private_state']['staked'] as $st) if ($st['side'] === $w) $want += (int) e24_view($st['card'])['vp'];
      if (($p['public_state']['paper'] ?? null) === 'argus') $want += (int) $p['public_state']['called'];
      eq($p['final_score'], $want, "game $gi seat $s: final score is the stakes on the winner");
    }
  }
}
ok($errors === 0, "no legal action refused ($errors)");
ok($maxPerTurn <= 1, 'at most one big state called in any action');
echo "  ended: " . json_encode($ended) . "\n";
echo "  mean rounds " . round(array_sum($rounds) / max(1, count($rounds)), 1)
   . ", big states called " . $calls . ", actions " . $actions . "\n";
echo "  $CHECKS checks in all, $FAILS failed\n";
exit($FAILS ? 1 : 0);
