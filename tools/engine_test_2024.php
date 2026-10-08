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
 * (staked cards and planks in play included), every state is face down,
 * face up or claimed exactly once, the tally is the claimed votes, currency
 * never goes negative, public counts match, stakes never leak, and the game
 * ends properly.
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
    foreach ($p['public_state']['locations'] ?? [] as $loc) ok(in_array(e24_view($loc)['type'], E24_ONGOING, true), "$where: only planks stay in play");
  }
  $st = $game['state'];
  $add($st['main'], 'main');
  $add($st['exchange'], 'exchange');
  $add($st['trash'], 'trash');
  ok(!$dup, "$where: no card in two places " . implode('; ', array_slice($dup, 0, 3)));
  // Stories and planks: each exists exactly once; the switch is in the main deck until it comes up.
  foreach (e24_cards() as $k => $c) {
    if ($c['era'] === 'switch') {
      ok(($st['switched'] === null) === isset($seen[$k]), "$where: the switch is in the main deck until it comes up");
      ok(!in_array($k, $st['exchange'], true), "$where: the switch is never on the exchange");
    } elseif ($c['era']) {
      ok(isset($seen[$k]), "$where: $k is somewhere");
    }
  }
  // States: each face down, face up, or claimed for its side -- exactly once.
  $tally = ['trump' => 0, 'harris' => 0];
  foreach (e24_states() as $k => $s) {
    $cl = $st['claims'][$k] ?? null;
    $down = in_array($k, $st['decks'][$s['deck']], true);
    $up = ($st['up'][$s['deck']] ?? null) === $k;
    if ($cl) {
      $tally[$cl['side']] += (int) $s['ev'];
      ok(isset($seen['st#' . $k . ':' . $cl['side']]), "$where: claimed $k exists for its side");
      ok(!isset($seen['st#' . $k . ':' . e24_other($cl['side'])]) && !$down && !$up, "$where: claimed $k exists once");
    } else {
      ok($down xor $up, "$where: unclaimed $k is face down or face up");
      foreach (['st#' . $k, 'st#' . $k . ':trump', 'st#' . $k . ':harris'] as $id) ok(!isset($seen[$id]), "$where: unclaimed $k is in no zone");
    }
  }
  foreach (E24_DECKS as $d) ok($st['up'][$d] !== null || empty($st['decks'][$d]), "$where: deck $d has a state up while any are left");
  eq(e24_tally($game), $tally, "$where: the tally is the claimed votes");
  foreach ($players as $s => $p) {
    for ($i = 0; $i < 7; $i++) ok(isset($seen["letter#$s.$i"]) || in_array("letter#$s.$i", $st['trash'], true), "$where: letter#$s.$i exists");
    for ($i = 0; $i < 3; $i++) ok(isset($seen["notice#$s.$i"]) || in_array("notice#$s.$i", $st['trash'], true), "$where: notice#$s.$i exists");
  }
  $ed = 0; $sc = 0;
  foreach ($seen as $id => $z) {
    if (strpos($id, 'editorial#') === 0) $ed++;
    if (strpos($id, 'scandal#') === 0) $sc++;
  }
  eq($ed + $st['editorials'], 16, "$where: Editorials conserved");
  eq($sc + $st['scandals'], 20, "$where: Scandals conserved");
  ok(count($st['exchange']) <= 5, "$where: exchange holds at most five");
  foreach ($st['exchange'] as $x) ok(!e24_is_state($x), "$where: no state on the exchange");
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
  e24_refill($game);
  foreach ($players as $q => $_) e24_count($players[$q]);
}

function give_hand(&$game, &$players, $cards) {
  set_hand($game, $players, $game['state']['turn']['seat'], $cards);
}

/** A fresh turn's hand: these cards only, and their currency counted. */
function fresh_hand(&$game, &$players, $cards) {
  give_hand($game, $players, $cards);
  $game['state']['turn']['base'] = ['gen' => 0, 'rep' => 0, 'dem' => 0, 'campaign' => 0];
  $game['state']['turn']['counted'] = [];
  e24_count_hand($game, $players);
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

/** Put a state face up on its deck (the one up goes back face down), and make $next the one under it. */
function face_up(&$game, $key, $next = null) {
  $d = e24_state($key)['deck'];
  $deck = &$game['state']['decks'][$d];
  foreach ([$key, $next] as $k) {
    if ($k === null) continue;
    $i = array_search($k, $deck, true);
    if ($i !== false) array_splice($deck, $i, 1);
  }
  if ($game['state']['up'][$d] !== $key) $deck[] = $game['state']['up'][$d];
  if ($next !== null) $deck[] = $next;
  $game['state']['up'][$d] = $key;
  unset($deck);
}

/** Put a plank in play for a seat. */
function in_play(&$game, &$players, $seat, $plank) {
  foreach (['main', 'exchange'] as $z) {
    $i = array_search($plank, $game['state'][$z], true);
    if ($i !== false) array_splice($game['state'][$z], $i, 1);
  }
  e24_refill($game);
  $players[$seat]['public_state']['locations'][] = $plank;
}

function find_card($fn) {
  foreach (e24_cards() as $k => $c) if ($fn($c)) return $k;
  return null;
}

echo "Rule tests\n";

// ---- content ---------------------------------------------------------------
$ev = 0; $decks = ['large' => 0, 'medium' => 0, 'small' => 0]; $swing = 0;
foreach (e24_states() as $s) {
  $ev += (int) $s['ev'];
  $decks[$s['deck']]++;
  if ((int) $s['trump_strike'] && (int) $s['harris_strike']) $swing++;
}
eq([count(e24_states()), $ev], [51, 538], 'content: 51 contests, 538 votes');
eq($decks, ['large' => 11, 'medium' => 18, 'small' => 22], 'content: three state decks');
eq($swing, 7, 'content: seven swing states knock out planks');
eq((int) e24_state('ca')['vp'], 12, 'content: California is worth 12 wealth');
eq([(int) e24_state('pa')['trump_threshold'], (int) e24_state('pa')['harris_threshold']], [9, 9], 'content: Pennsylvania costs the same either way');
ok((int) e24_state('dc')['trump_threshold'] > (int) e24_state('dc')['harris_threshold'] + 10, 'content: flipping D.C. is dear');
$count = ['story' => 0, 'plank' => 0, 'switch' => 0, 'strike' => 0];
$planks = ['biden' => ['rep' => 0, 'dem' => 0], 'harris' => ['rep' => 0, 'dem' => 0]];
foreach (e24_cards() as $c) {
  if ($c['type'] === 'Plank') { $count['plank']++; $planks[$c['era']][$c['lean']]++; }
  elseif ($c['type'] === 'Switch') $count['switch']++;
  elseif ($c['era']) {
    $count['story']++;
    ok((int) $c['bottom_party'] > 0, "content: {$c['key']} has an oppositional side");
    if ($c['bottom_attack'] === 'plank') $count['strike']++;
  }
}
eq($count, ['story' => 59, 'plank' => 20, 'switch' => 1, 'strike' => 20], 'content: 59 stories, 20 planks, the switch, 20 plank knockouts');
eq($planks, ['biden' => ['rep' => 5, 'dem' => 5], 'harris' => ['rep' => 5, 'dem' => 5]], 'content: five planks of each party in each set');

// ---- setup ----------------------------------------------------------------
list($g, $P) = new_game(3, [], [0 => 'globe']);
eq(count($P[0]['private_state']['hand']), 5, 'setup: hand of five');
eq(count($g['state']['exchange']), 5, 'setup: exchange of five');
foreach (E24_DECKS as $d) ok($g['state']['up'][$d] !== null && e24_state($g['state']['up'][$d])['deck'] === $d, "setup: a $d state face up");
foreach ($g['state']['exchange'] as $x) eq(e24_card($x)['era'], 'biden', 'setup: the exchange comes from the Biden set');
eq($g['state']['switched'], null, 'setup: the switch has not come up');
eq($P[0]['public_state']['paper'], 'globe', 'setup: a chosen paper is kept');
eq(e24_tally($g), ['trump' => 0, 'harris' => 0], 'setup: no votes claimed');
check_invariants($g, $P, 'setup');

// ---- currency from the hand; playing uses abilities -------------------------
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

$story = function ($fn) { return find_card(function ($c) use ($fn) { return $c['era'] && $c['type'] !== 'Plank' && $c['type'] !== 'Switch' && $fn($c); }); };
$repStory = $story(function ($c) { return $c['lean'] === 'rep' && (int) $c['themed'] > 0 && !(int) $c['per_office']; });
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$repStory]);
eq(e24_pools($g, $P)['rep'], (int) e24_card($repStory)['themed'], 'hand: a Republican story pays Republican from the hand');
eq(e24_pools($g, $P)['dem'], 0, '... and no Democratic');

$drawer = $story(function ($c) { return (int) $c['draw'] > 0 && !(int) $c['trash'] && !(int) $c['chain'] && $c['bottom_attack'] !== 'plank'; });
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$drawer]);
$before = e24_pools($g, $P);
foreach (['deck', 'discard'] as $z) {                          // the next draw is a Letter (+1)
  $P[0]['private_state'][$z] = array_values(array_diff($P[0]['private_state'][$z], ['letter#0.0']));
}
$P[0]['private_state']['deck'][] = 'letter#0.0';
e24_count($P[0]);
act($g, $P, 'play', ['card' => $drawer]);
eq(e24_pools($g, $P)['gen'], $before['gen'] + 1, 'top: a drawn Letter counts at once; the played card is not counted twice');
eq($g['state']['turn']['frames'][$drawer], 'top', 'top: recorded as used on its top');
check_invariants($g, $P, 'draw ability');

// ---- the bottom: the other party's currency, no ability ----------------------
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$drawer]);
$before = e24_pools($g, $P);
$c = e24_card($drawer);
$other = e24_other_party($c['lean']);
eq(engine_available_actions($g, $P, 0)['framings'][$drawer], ['top', 'bottom'], 'bottom: a story offers both framings');
act($g, $P, 'play', ['card' => $drawer, 'framing' => 'bottom']);
eq(e24_pools($g, $P)[$other], $before[$other] + (int) $c['bottom_party'], 'bottom: pays the other party\'s currency');
eq(count($P[0]['private_state']['hand']), 0, 'bottom: no card drawn (the top\'s ability is not used)');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'play', ['card' => 'letter#0.2', 'framing' => 'sideways']); }) !== false, 'bottom: an unknown framing is refused');
check_invariants($g, $P, 'bottom');

// ---- buying a story ---------------------------------------------------------
$demStory = $story(function ($c) { return $c['lean'] === 'dem' && $c['era'] === 'biden' && (int) $c['cost'] >= 3; });
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

// ---- buying a face-up state for a side; the next turns up --------------------
list($g, $P) = new_game(2);
give_hand($g, $P, []);
face_up($g, 'wi', 'mn');
$wi = e24_state('wi');
set_pools($g, ['gen' => 0, 'dem' => 2, 'rep' => 6, 'campaign' => 1]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#wi']); }) !== false, 'buy state: needs a side');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#mn', 'side' => 'trump']); }) !== false, 'buy state: only the face-up state');
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#wi', 'side' => 'harris']); }) !== false, 'buy state: Republican cannot pay for Harris');
act($g, $P, 'buy', ['card' => 'st#wi', 'side' => 'trump']);
eq($g['state']['turn']['spent']['rep'], 6, 'buy state: Republican first');
eq($g['state']['turn']['spent']['campaign'], 1, 'buy state: then Campaign');
eq($g['state']['turn']['spent']['gen'], (int) $wi['trump_threshold'] - 7, 'buy state: then neutral');
ok(in_array('st#wi:trump', $P[0]['private_state']['discard'], true), 'buy state: its Trump card to the discard pile');
eq(e24_tally($g), ['trump' => 10, 'harris' => 0], 'buy state: 10 votes for Trump');
eq($g['state']['claims']['wi']['seat'], 0, 'buy state: claimed by the buyer');
eq($g['state']['up']['medium'], 'mn', 'buy state: the next state turns up');
eq($g['state']['last_reveal']['key'], 'mn', 'buy state: and is revealed');
check_invariants($g, $P, 'buy state');

// A large state's reveal: every outlet takes a Scandal (the New York Times never does).
list($g, $P) = new_game(3, [], [0 => 'sun', 1 => 'intelligencer', 2 => 'journal']);
give_hand($g, $P, []);
eq(e24_state('mi')['reveal_kind'], 'scandal', '(Michigan reveals a Scandal)');
face_up($g, 'pa', 'mi');
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#pa', 'side' => 'harris']);
$sc = function ($p) { $n = 0; foreach (array_merge($p['private_state']['discard'], $p['private_state']['hand']) as $id) if (strpos($id, 'scandal#') === 0) $n++; return $n; };
eq([$sc($P[0]), $sc($P[1]), $sc($P[2])], [1, 0, 1], 'reveal: a Scandal for every outlet but the Times');
eq((int) $P[0]['public_state']['called'], 1, 'reveal: a large state bought counts for the AP');
check_invariants($g, $P, 'reveal scandal');

// A small state's reveal sweeps the exchange.
list($g, $P) = new_game(2);
give_hand($g, $P, []);
$small = null;
foreach (e24_states() as $k => $s) if ($s['deck'] === 'small' && $s['reveal_kind'] === 'sweep' && $k !== 'wy') { $small = $k; break; }
face_up($g, 'wy', $small);
$oldEx = $g['state']['exchange'];
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#wy', 'side' => 'trump']);
ok(!array_intersect($oldEx, $g['state']['exchange']), 'reveal: a sweep deals a fresh exchange');
eq(count($g['state']['exchange']), 5, 'reveal: ... of five');
check_invariants($g, $P, 'reveal sweep');

// The Globe: either party's currency counts for either side.
list($g, $P) = new_game(2, [], [0 => 'globe', 1 => 'sun']);
give_hand($g, $P, []);
face_up($g, 'mi');
set_pools($g, ['dem' => 20]);
act($g, $P, 'buy', ['card' => 'st#mi', 'side' => 'trump']);
eq(e24_tally($g)['trump'], 15, 'Globe: Democratic currency buys Michigan for Trump');
list($g, $P) = new_game(2, [], [0 => 'sun', 1 => 'globe']);
give_hand($g, $P, []);
face_up($g, 'mi');
set_pools($g, ['dem' => 20]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#mi', 'side' => 'trump']); }) !== false, 'without the Globe it cannot');

// ---- the House delegation, bought with the state -------------------------------
eq([(int) e24_state('ca')['house_seats'], (int) e24_state('ca')['house_cost']], [52, 13], 'House: California 52 seats, 13 to buy');
eq([(int) e24_state('dc')['house_seats'], (int) e24_state('dc')['house_cost']], [0, 0], 'House: D.C. has no delegation');
$seats = 0;
foreach (e24_states() as $s) $seats += (int) $s['house_seats'];
eq($seats, 435, 'House: 435 seats in all');
list($g, $P) = new_game(2);
give_hand($g, $P, []);
face_up($g, 'pa');
set_pools($g, ['gen' => 13]);
ok(throws(function () use (&$g, &$P) { act($g, $P, 'buy', ['card' => 'st#pa', 'side' => 'trump', 'house' => true]); }) !== false, 'House: refused when the state and its delegation cost more than you have');
set_pools($g, ['gen' => 14]);
ok(in_array('st#pa:trump', engine_available_actions($g, $P, 0)['buy_house'], true), 'House: offered when affordable');
act($g, $P, 'buy', ['card' => 'st#pa', 'side' => 'trump', 'house' => true]);
eq($g['state']['turn']['spent']['gen'], 9 + 5, 'House: Pennsylvania 9 + its delegation 5');
ok(!empty($g['state']['claims']['pa']['house']), 'House: the delegation is claimed with the state');
eq(e24_house_tally($g), ['trump' => 17, 'harris' => 0], 'House: 17 seats for Trump');
eq(engine_public_state($g, $P, 0)['race']['house'], ['trump' => 17, 'harris' => 0], 'House: in the view');
check_invariants($g, $P, 'house buy');

/** Claim every state (by hand) for the given sides, leaving one face up; return the game ready to buy it. */
function nearly_all(&$g, &$P, $last, $lastSide, $trumpKeys, $house = []) {
  foreach (E24_DECKS as $d) {
    foreach (array_merge($g['state']['decks'][$d], [$g['state']['up'][$d]]) as $k) {
      if ($k === null || $k === $last) continue;
      $side = in_array($k, $trumpKeys, true) ? 'trump' : 'harris';
      $P[1]['private_state']['discard'][] = 'st#' . $k . ':' . $side;
      $g['state']['claims'][$k] = ['side' => $side, 'seat' => 1, 'how' => 'buy', 'round' => 1];
      if (in_array($k, $house, true)) $g['state']['claims'][$k]['house'] = true;
    }
    $g['state']['decks'][$d] = [];
    $g['state']['up'][$d] = null;
  }
  $g['state']['up'][e24_state($last)['deck']] = $last;
  foreach ($P as $s => $_) e24_count($P[$s]);
}
// A 269-269 split: Trump holds every Republican state of 2024 but Nevada (306 - 6 = 300) ... build exactly 269 instead.
// Trump states adding to exactly 266 (Wyoming, 3, is bought last for him): a subset sum.
$reach = [0 => []];
foreach (array_keys(e24_states()) as $k) {
  if ($k === 'wy') continue;
  $e = (int) e24_state($k)['ev'];
  foreach (array_keys($reach) as $sum) {
    if ($sum + $e <= 266 && !isset($reach[$sum + $e])) $reach[$sum + $e] = array_merge($reach[$sum], [$k]);
  }
}
$trump = $reach[266] ?? [];
$ev = 0;
foreach ($trump as $k) $ev += (int) e24_state($k)['ev'];
ok($ev === 266, 'House setup: Trump 266 before Wyoming (' . $ev . ')');
list($g, $P) = new_game(2);
nearly_all($g, $P, 'wy', 'trump', $trump, ['ca']);     // Harris holds California's delegation (52)
give_hand($g, $P, []);
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#wy', 'side' => 'trump', 'house' => true]);
eq(e24_tally($g), ['trump' => 269, 'harris' => 269], 'House: 269-269');
act($g, $P, 'end_turn');
eq($g['status'], 'ended', 'House: the game ends when every state is claimed');
eq($g['ended_reason'], 'house', 'House: the House decides');
eq($g['state']['winner_side'], in_array('ca', $trump, true) ? 'trump' : 'harris', 'House: the side with more seats wins');
list($g, $P) = new_game(2);
nearly_all($g, $P, 'wy', 'trump', $trump, []);
give_hand($g, $P, []);
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#wy', 'side' => 'trump']);
act($g, $P, 'end_turn');
eq($g['ended_reason'], 'deadlock', 'House: no delegations bought, a tied House: deadlock');
eq($g['state']['winner_side'], null, 'House: deadlock has no winner');

// ---- planks: into play, paying each turn, knocked out ----------------------------
$repPlank = find_card(function ($c) { return $c['type'] === 'Plank' && $c['lean'] === 'rep' && (int) $c['ongoing_gen'] === 2; });
$demPlank = find_card(function ($c) { return $c['type'] === 'Plank' && $c['lean'] === 'dem' && (int) $c['ongoing_gen'] === 1 && !(int) $c['ongoing_draw']; });
list($g, $P) = new_game(2);
fresh_hand($g, $P, [$repPlank]);
eq(engine_available_actions($g, $P, 0)['framings'][$repPlank], ['top'], 'plank: one side only');
act($g, $P, 'play', ['card' => $repPlank]);
act($g, $P, 'end_turn');
eq($P[0]['public_state']['locations'], [$repPlank], 'plank: stays in play');
ok(e24_pools($g, $P)['rep'] >= 1, 'plank: a rival gets +1 Republican at the start of its turn');
$rivalRep = e24_pools($g, $P)['rep'];
act($g, $P, 'end_turn');
$gen0 = 0;
foreach ($P[0]['private_state']['hand'] as $id) $gen0 += (int) e24_view($id)['gen'];
eq(e24_pools($g, $P)['gen'], $gen0 + 2, 'plank: its owner gets +2 neutral at the start of each turn');
check_invariants($g, $P, 'plank in play');

// A story's bottom knocks out a rival's plank of its party.
$striker = $story(function ($c) { return $c['bottom_attack'] === 'plank' && $c['strike'] === 'rep'; });
list($g, $P) = new_game(2);
in_play($g, $P, 1, $repPlank);
fresh_hand($g, $P, [$striker]);
act($g, $P, 'play', ['card' => $striker, 'framing' => 'bottom']);
$pend = $g['state']['turn']['pending'];
ok($pend && $pend['type'] === 'knock' && $pend['options'] === [$repPlank], 'knock: the rival\'s Republican plank is the target');
eq(engine_public_state($g, $P, 0)['you']['pending']['options'][0]['owner']['seat'], 1, 'knock: the view names its owner');
act($g, $P, 'choose', ['card' => $repPlank]);
eq($P[1]['public_state']['locations'], [], 'knock: the plank leaves play');
ok(in_array($repPlank, $P[1]['private_state']['discard'], true), 'knock: to its owner\'s discard pile');
eq((int) $P[0]['public_state']['knocks'], 1, 'knock: counted');
check_invariants($g, $P, 'knock');
// No plank of that party in play: no prompt; a plank of the other party is safe.
list($g, $P) = new_game(2);
in_play($g, $P, 1, $demPlank);
fresh_hand($g, $P, [$striker]);
act($g, $P, 'play', ['card' => $striker, 'framing' => 'bottom']);
eq($g['state']['turn']['pending'], null, 'knock: nothing to hit, no prompt');
eq($P[1]['public_state']['locations'], [$demPlank], 'knock: the other party\'s plank is safe');
// A swing state played for Trump knocks out a Democratic plank.
list($g, $P) = new_game(2);
in_play($g, $P, 1, $demPlank);
$P[0]['private_state']['discard'][] = 'st#pa:trump';
$g['state']['claims']['pa'] = ['side' => 'trump', 'seat' => 0, 'how' => 'buy', 'round' => 1];
$i = array_search('pa', $g['state']['decks']['large'], true);
if ($i !== false) array_splice($g['state']['decks']['large'], $i, 1);
if ($g['state']['up']['large'] === 'pa') $g['state']['up']['large'] = array_pop($g['state']['decks']['large']);
fresh_hand($g, $P, ['st#pa:trump']);
act($g, $P, 'play', ['card' => 'st#pa:trump']);
ok($g['state']['turn']['pending'] && $g['state']['turn']['pending']['options'] === [$demPlank], 'swing state: Pennsylvania for Trump targets a Democratic plank');
act($g, $P, 'choose', ['card' => $demPlank]);
eq($P[1]['public_state']['locations'], [], 'swing state: knocked out');
check_invariants($g, $P, 'swing knock');

// ---- the switch -------------------------------------------------------------------
list($g, $P) = new_game(2);
$switch = find_card(function ($c) { return $c['era'] === 'switch'; });
$at = array_search($switch, $g['state']['main'], true);
$biden = array_slice($g['state']['main'], $at + 1);
foreach ($biden as $b) eq(e24_card($b)['era'], 'biden', 'switch: everything above it is the Biden set');
foreach (array_slice($g['state']['main'], 0, $at) as $h) eq(e24_card($h)['era'], 'harris', 'switch: everything below it is the Harris set');
$g['state']['main'] = array_slice($g['state']['main'], 0, $at + 1);   // the Biden set used up (set aside)
$g['state']['trash'] = array_merge($g['state']['trash'], $biden);
give_hand($g, $P, []);
$g['state']['exchange'] = [];
e24_refill($g);
ok($g['state']['switched'] !== null, 'switch: it comes up');
foreach ($g['state']['exchange'] as $x) eq(e24_card($x)['era'], 'harris', 'switch: the Harris set follows');
eq(engine_public_state($g, $P, 0)['set'], 'harris', 'switch: the view says the Harris set');

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
$P[0]['private_state']['staked'][] = ['card' => 'editorial#99', 'side' => 'trump', 'round' => 1];
$g['state']['editorials'] -= 1;
$P[1]['private_state']['staked'][] = ['card' => 'letter#1.6', 'side' => 'harris', 'round' => 1];
$P[1]['private_state']['hand'] = array_values(array_diff($P[1]['private_state']['hand'], ['letter#1.6']));
$P[1]['private_state']['deck'] = array_values(array_diff($P[1]['private_state']['deck'], ['letter#1.6']));
foreach ($P as $s => $_) e24_count($P[$s]);
// Trump 264-269 claimed by hand (none of them face up), then Nevada (6) takes him past 270.
face_up($g, 'nv');
$claimed = 0;
foreach (e24_states() as $k => $st) {
  if ($k === 'nv' || in_array($k, $g['state']['up'], true) || $claimed + (int) $st['ev'] > 269) continue;
  $i = array_search($k, $g['state']['decks'][$st['deck']], true);
  array_splice($g['state']['decks'][$st['deck']], $i, 1);
  $P[2]['private_state']['discard'][] = 'st#' . $k . ':trump';
  $g['state']['claims'][$k] = ['side' => 'trump', 'seat' => 2, 'how' => 'buy', 'round' => 1];
  $claimed += (int) $st['ev'];
}
foreach ($P as $s => $_) e24_count($P[$s]);
check_invariants($g, $P, 'race setup');
give_hand($g, $P, []);
set_pools($g, ['gen' => 20]);
act($g, $P, 'buy', ['card' => 'st#nv', 'side' => 'trump']);
ok(e24_tally($g)['trump'] >= 270, 'race: Nevada takes Trump to 270 (' . e24_tally($g)['trump'] . ')');
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

// ---- trash spares states; Politico skips the switch; the news cycle -------------
$trashCard = $story(function ($c) { return (int) $c['trash'] > 0; });
list($g, $P) = new_game(2);
$P[0]['private_state']['discard'][] = 'st#tx:trump';
$g['state']['claims']['tx'] = ['side' => 'trump', 'seat' => 0, 'how' => 'buy', 'round' => 1];
$i = array_search('tx', $g['state']['decks']['large'], true);
if ($i !== false) array_splice($g['state']['decks']['large'], $i, 1);
if ($g['state']['up']['large'] === 'tx') $g['state']['up']['large'] = array_pop($g['state']['decks']['large']);
give_hand($g, $P, [$trashCard, 'notice#0.0']);
act($g, $P, 'play', ['card' => $trashCard]);
ok($g['state']['turn']['pending'] && !in_array('st#tx:trump', $g['state']['turn']['pending']['options'], true), 'trash: a claimed state cannot be destroyed');
act($g, $P, 'choose', ['card' => 'notice#0.0']);
ok(in_array('notice#0.0', $g['state']['trash'], true), 'trash: the chosen card is destroyed');
check_invariants($g, $P, 'trash');

list($g, $P) = new_game(2, [], [0 => 'herald', 1 => 'globe']);
give_hand($g, $P, []);
set_pools($g, ['gen' => 3]);
$i = array_search($switch, $g['state']['main'], true);           // the switch to the top of the main deck
array_splice($g['state']['main'], $i, 1);
$g['state']['main'][] = $switch;
$top = $g['state']['main'][e24_top_story($g)];
act($g, $P, 'paper');
eq($P[0]['private_state']['held'], [$top], 'Politico: the topmost card is scooped');
ok($top !== $switch, 'Politico: never the switch');
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
foreach (E24_DECKS as $d) ok($view['state_decks'][$d]['up']['ev'] > 0 && $view['state_decks'][$d]['left'] > 0, "view: the $d state up, and how many are left");
ok(isset($view['state_decks']['large']['up']['reveal']['kind']), 'view: the face-up state carries its reveal');
eq($view['set'], 'biden', 'view: the Biden set');
eq(count($view['map']), 51, 'view: the map has every contest');
eq($view['race']['win_at'], 270, 'view: the race to 270');
ok(!isset($view['state_decks']['large']['cards']), 'view: the face-down states are not listed');

echo "  $CHECKS checks, $FAILS failed\n\n";

// =============================================================================
// Random games
// =============================================================================

$games = (int) ($argv[1] ?? 300);
echo "Random games: $games (2-5 seats, people making random legal moves, some bot seats)\n";
$ended = [];
$rounds = [];
$bought = 0;
$knocks = 0;
$switched = 0;
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
    try {
      if (isset($av['choose'])) {
        $opts = $av['choose'];
        $pick = mt_rand(0, 3) === 0 ? null : $opts[mt_rand(0, count($opts) - 1)];
        act($g, $P, 'choose', ['card' => $pick]);
      } elseif (!empty($av['stake']) && mt_rand(0, 9) === 0) {
        act($g, $P, 'stake', ['card' => $av['stake'][mt_rand(0, count($av['stake']) - 1)], 'side' => mt_rand(0, 1) ? 'trump' : 'harris']);
      } elseif (!empty($av['play']) && mt_rand(0, 4) > 0) {
        if (mt_rand(0, 3) === 0) act($g, $P, 'play_all');
        else {
          $card = $av['play'][mt_rand(0, count($av['play']) - 1)];
          $fr = $av['framings'][$card];
          act($g, $P, 'play', ['card' => $card, 'framing' => $fr[mt_rand(0, count($fr) - 1)]]);
        }
      } elseif (!empty($av['buy']) && mt_rand(0, 4) > 0) {
        $b = $av['buy'][mt_rand(0, count($av['buy']) - 1)];
        if (e24_is_state($b)) act($g, $P, 'buy', ['card' => 'st#' . e24_state_key($b), 'side' => e24_card_side($b),
                                                  'house' => in_array($b, $av['buy_house'], true) && mt_rand(0, 1)]);
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
    check_invariants($g, $P, "game $gi, action $guard");
    if ($FAILS - $startFails > 20) break 2;
  }
  $ended[$g['ended_reason'] ?? 'unfinished'] = ($ended[$g['ended_reason'] ?? 'unfinished'] ?? 0) + 1;
  $rounds[] = $g['round_number'];
  $bought += count($g['state']['claims']);
  $switched += $g['state']['switched'] !== null ? 1 : 0;
  foreach ($P as $p) $knocks += (int) ($p['public_state']['knocks'] ?? 0);
  if ($g['status'] === 'ended') {
    $left = array_filter($P, function ($p) { return !$p['conceded']; });
    if (!$left) {
      eq($g['winner_seat'], null, "game $gi: everyone left, so nobody wins");
    } else {
      $best = max(array_map(function ($p) { return $p['final_score']; }, $left));
      ok($g['winner_seat'] !== null && empty($P[$g['winner_seat']]['conceded'])
         && $P[$g['winner_seat']]['final_score'] === $best, "game $gi: the winner has the most wealth");
    }
    $w = $g['state']['winner_side'];
    if ($g['ended_reason'] === 'race_called') ok(e24_tally($g)[$w] >= 270, "game $gi: the winner side has 270");
    if ($g['ended_reason'] === 'house') {
      $h = e24_house_tally($g);
      ok($h[$w] > $h[e24_other($w)] && max(e24_tally($g)) < 270, "game $gi: the House winner has more seats, at 269-269");
    }
    if ($g['ended_reason'] === 'deadlock') ok($w === null, "game $gi: a deadlock has no winner");
    foreach ($P as $s => $p) {
      $want = 0;
      foreach ($p['private_state']['staked'] as $st) if ($st['side'] === $w) $want += (int) e24_view($st['card'])['vp'];
      if (($p['public_state']['paper'] ?? null) === 'argus') $want += count(array_filter($p['public_state']['large_sides'] ?? [], function ($sd) use ($w) { return $sd === $w; }));
      eq($p['final_score'], $want, "game $gi seat $s: final score is the stakes on the winner");
    }
  }
}
ok($errors === 0, "no legal action refused ($errors)");
echo "  ended: " . json_encode($ended) . "\n";
echo "  mean rounds " . round(array_sum($rounds) / max(1, count($rounds)), 1)
   . ", states claimed " . $bought . ", planks knocked out " . $knocks . ", switch came up in " . $switched . " of " . $games
   . ", actions " . $actions . "\n";
echo "  $CHECKS checks in all, $FAILS failed\n";
exit($FAILS ? 1 : 0);
