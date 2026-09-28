<?php
/**
 * engine_dc.php -- the rules engine for the DC-style Fourth Estate
 * (VARIANT.md revision 4; docs/BUILD-PLAN-DC.md).
 *
 * NOT YET LIVE. The endpoints still load engine.php (the newsroom game).
 * This file offers the same entry points (engine_setup, engine_apply_action,
 * engine_run_bots, engine_public_state, engine_build_export,
 * engine_record_scores, ...) so switching is a one-line change once the UI
 * is ready (milestone 5). It needs no lib.php: tools/engine_test.php loads
 * it on its own and plays games with no database.
 *
 * Pure functions over the $game and $players arrays the endpoints load.
 * The client is presentation only; the server is always right.
 *
 * ---------------------------------------------------------------------
 * THE RULES (tools/simulate_dc.py plays the same rules; keep them in step)
 *
 * Papers take TURNS. Each starts with 7 Letters to the Editor (+1) and 3
 * Local Notices (nothing), shuffled; draws 5. On its turn a paper:
 *   - PLAYS stories from its hand, one at a time, making influence:
 *     plain (spends on anything), Political / Economic / Social (spend
 *     only on stories of that theme or electing a man of that theme), and
 *     Campaign (elections only);
 *   - may ELECT once: reach either candidate's threshold for the election
 *     in progress and take the election card as his Patron (its bonus pays
 *     whenever it is played later; worth prestige);
 *   - BUYS stories off the exchange (5 face up) or Editorials;
 *   - ENDS the turn: everything played and left in hand is discarded
 *     (media events stay in play for good), and it draws 5.
 * Negative stories attack every rival (discard at random, or a Scandal:
 * -1 prestige); a Defense card in hand is used automatically; an attack
 * that hits anyone gives +1 influence. Retraction: draw a card and destroy
 * a Scandal. Stories enter the main deck when the election in progress
 * reaches their year. The game ends when 1860 is decided (after that
 * turn); most prestige wins.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/cards_dc.php';

define('ENGINE_STATE_VERSION', 9);
define('DC_THEMES', ['Political', 'Economic', 'Social']);

// ---------------------------------------------------------------------
// Configuration -- mirrors DEFAULTS in tools/simulate_dc.py
// ---------------------------------------------------------------------

function engine_default_config() {
  return [
    'engine_version'   => ENGINE_STATE_VERSION,
    'hand'             => 5,
    'exchange_size'    => 5,
    'max_rounds'       => 60,     // safety: a game that stalls ends here
    'catchup'          => 1,      // later seats: +1 influence per place on their first turn
    'attack_reward'    => 1,      // influence when an attack hits any rival
    // the papers, as balanced (VARIANT.md, "balancing the papers")
    'argus_vp'         => 1,
    'sun_draw'         => 3,
    'north_star_at'    => 2,
    'north_star_draw'  => 1,
    'herald_cost'      => 2,
    'min_players'      => 1,
    'max_players'      => 5,
    'bots'             => 1,
    'bot_level'        => 'easy',
  ];
}

/** Host-adjustable knobs and their legal ranges (createGame clamps to these). */
function engine_config_knobs() {
  return [
    'catchup' => [0, 3],
  ];
}

/** Can this engine play the stored game? */
function engine_is_current($game) {
  return is_array($game['state'] ?? null)
    && (int) ($game['state']['engine_version'] ?? 0) === ENGINE_STATE_VERSION;
}

// ---------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------

/**
 * Everything the rules need about one card instance. Starters, Editorials
 * and Scandals are 'kind#n'; stories are their key; an election won is
 * 'elec#<index>:<side>' and is synthesised from the election card.
 */
function dc_view($id) {
  $id = (string) $id;
  if (strpos($id, 'elec#') === 0) {
    list($i, $side) = explode(':', substr($id, 5));
    $e = dc_election((int) $i);
    return [
      'key' => $id, 'kind' => 'election', 'name' => $e['year'] . ': ' . $e[$side],
      'type' => 'Election', 'theme' => $e[$side . '_theme'], 'cost' => 0, 'vp' => (int) $e['vp'],
      'gen' => (int) $e['patron_gen'], 'themed' => (int) $e['patron_themed'], 'campaign' => 0, 'draw' => 0,
      'trash' => 0, 'gain_upto' => 0, 'chain' => 0, 'per_same' => 0, 'per_office' => 0, 'attack' => null,
      'defense' => 0, 'retract' => 0, 'ongoing_gen' => 0, 'ongoing_draw' => 0, 'others_bonus' => 0,
      'others_theme' => null, 'released' => null, 'year' => (int) $e['year'], 'copies' => 1,
      'card_text' => 'Patron of ' . $e[$side] . ' (' . $e['year'] . '): +' . $e['patron_gen'] . ' influence, +'
                     . $e['patron_themed'] . ' ' . $e[$side . '_theme'] . '. Worth ' . $e['vp'] . ' prestige.',
      'flavor' => null,
    ];
  }
  $c = dc_card($id);
  if (!$c) return null;
  $c['kind'] = $c['key'];
  $c['key'] = $id;
  return $c;
}

function dc_is_story_kind($c) {
  return in_array($c['type'], ['Political story', 'Economic story', 'Social story', 'Negative story'], true);
}

/** Card instances as the client shows them. */
function dc_views($ids) {
  $out = [];
  foreach ($ids as $id) {
    $c = dc_view($id);
    if ($c) $out[] = $c;
  }
  return $out;
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------

/**
 * Deal the opening position: each paper's 10-card deck, its newspaper,
 * the 1796 news on the exchange, and the first turn.
 */
function engine_setup(&$game, &$players, $mysqli = null) {
  $stored = is_array($game['config']) ? $game['config'] : [];
  if ((int) ($stored['engine_version'] ?? 0) !== ENGINE_STATE_VERSION) {
    $stored = isset($stored['bots']) ? ['bots' => (int) $stored['bots'], 'bot_level' => $stored['bot_level'] ?? 'easy'] : [];
  }
  $config = array_merge(engine_default_config(), $stored);
  $config['engine_version'] = ENGINE_STATE_VERSION;
  $game['config'] = $config;

  $game['status'] = 'active';
  $game['phase'] = 'turn';
  $game['round_number'] = 1;
  $game['winner_seat'] = null;
  $game['ended_reason'] = null;

  $cards = dc_cards();
  $state = [
    'engine_version' => ENGINE_STATE_VERSION,
    'e'          => 0,                       // the election in progress
    'main'       => [],
    'exchange'   => [],
    'editorials' => (int) $cards['editorial']['copies'],
    'scandals'   => (int) $cards['scandal']['copies'],
    'trash'      => [],                      // destroyed cards
    'order'      => engine_seat_list($players),
    'turns'      => 0,
    'final'      => false,                   // 1860 decided: the game ends after this turn
    'history'    => [],                      // elections decided
    'last_turn'  => null,                    // summary of the previous turn, for the UI
    'turn'       => null,
  ];
  $game['state'] = $state;
  dc_release($game, (int) dc_election(0)['year']);
  dc_refill($game);

  // Newspapers: a paper chosen in the lobby is kept; the rest are dealt at random.
  $taken = [];
  foreach ($players as $seat => $p) {
    $k = $p['public_state']['paper'] ?? null;
    if ($k && isset(dc_papers()[$k]) && !in_array($k, $taken, true)) $taken[] = $k;
    else $players[$seat]['public_state']['paper'] = null;
  }
  $free = array_values(array_diff(array_keys(dc_papers()), $taken));
  shuffle($free);

  foreach ($players as $seat => $p) {
    $paper = $players[$seat]['public_state']['paper'] ?? null;
    if (!$paper) $paper = array_pop($free);
    $deck = [];
    for ($i = 0; $i < (int) $cards['letter']['copies']; $i++) $deck[] = 'letter#' . $seat . '.' . $i;
    for ($i = 0; $i < (int) $cards['notice']['copies']; $i++) $deck[] = 'notice#' . $seat . '.' . $i;
    shuffle($deck);
    $players[$seat]['public_state'] = [
      'paper'      => $paper,
      'elections'  => 0,
      'bought'     => 0,
      'attacks'    => 0,
      'scandals_taken' => 0,
      'turns'      => 0,
      'hand_count' => 0,
      'deck_count' => 0,
      'discard_count' => 0,
      'discard_top' => null,
      'locations'  => [],                    // media events in play (public)
      'prestige'   => 0,
    ];
    $players[$seat]['private_state'] = ['hand' => [], 'deck' => $deck, 'discard' => [], 'held' => []];
    $players[$seat]['score'] = 0;
    dc_draw($game, $players[$seat], (int) $config['hand']);
  }

  dc_start_turn($game, $players, $game['state']['order'][0], $mysqli);
  if ($mysqli) engine_log($mysqli, $game, null, 'campaign_begins', dc_campaign_text($game));
  engine_run_bots($game, $players, $mysqli);
}

/** Seat numbers need not be contiguous; always walk the seats that exist. */
function engine_seat_list($players) {
  $seats = array_map('intval', array_keys($players));
  sort($seats);
  return $seats;
}

function engine_human_seats($players) {
  $n = 0;
  foreach ($players as $p) if (empty($p['conceded']) && empty($p['is_bot'])) $n++;
  return $n;
}

// ---------------------------------------------------------------------
// Zones
// ---------------------------------------------------------------------

/** Draw up to $n cards, reshuffling the paper's discard pile when the deck runs out. */
function dc_draw(&$game, &$player, $n) {
  $ps = &$player['private_state'];
  $got = [];
  for ($i = 0; $i < $n; $i++) {
    if (empty($ps['deck'])) {
      if (empty($ps['discard'])) break;
      $ps['deck'] = $ps['discard'];
      $ps['discard'] = [];
      shuffle($ps['deck']);
    }
    $c = array_pop($ps['deck']);
    $ps['hand'][] = $c;
    $got[] = $c;
  }
  unset($ps);
  dc_count($player);
  return $got;
}

/** Refresh a paper's public counts and prestige. */
function dc_count(&$player) {
  $ps = $player['private_state'];
  $pub = &$player['public_state'];
  $pub['hand_count'] = count($ps['hand'] ?? []);
  $pub['deck_count'] = count($ps['deck'] ?? []);
  $pub['discard_count'] = count($ps['discard'] ?? []);
  $pub['discard_top'] = empty($ps['discard']) ? null : end($ps['discard']);
  unset($pub);
  $player['public_state']['prestige'] = dc_prestige($player);
  $player['score'] = $player['public_state']['prestige'];
}

/** Everything a paper owns. */
function dc_owned($player) {
  $ps = $player['private_state'];
  return array_merge($ps['hand'] ?? [], $ps['deck'] ?? [], $ps['discard'] ?? [], $ps['held'] ?? [],
                     $player['public_state']['locations'] ?? [], $ps['played'] ?? []);
}

function dc_offices($player) {
  $n = 0;
  foreach (dc_owned($player) as $id) if (strpos($id, 'elec#') === 0) $n++;
  return $n;
}

/** Prestige: every card owned, plus the Argus's bonus per office. */
function dc_prestige($player, $argusVp = null) {
  $vp = 0;
  foreach (dc_owned($player) as $id) {
    $c = dc_view($id);
    if ($c) $vp += (int) $c['vp'];
  }
  if (($player['public_state']['paper'] ?? null) === 'argus') {
    $vp += ($argusVp ?? (int) (engine_default_config()['argus_vp'])) * dc_offices($player);
  }
  return $vp;
}

/** Stories released at the election of $year go into the main deck. */
function dc_release(&$game, $year) {
  $fresh = [];
  foreach (dc_cards() as $k => $c) {
    if ($c['released'] !== null && (int) $c['released'] === (int) $year) $fresh[] = $k;
  }
  $game['state']['main'] = array_merge($game['state']['main'], $fresh);
  shuffle($game['state']['main']);
  $game['state']['last_released'] = $fresh;
}

function dc_refill(&$game) {
  $size = (int) ($game['config']['exchange_size'] ?? 5);
  while (count($game['state']['exchange']) < $size && !empty($game['state']['main'])) {
    $game['state']['exchange'][] = array_pop($game['state']['main']);
  }
}

// ---------------------------------------------------------------------
// The turn
// ---------------------------------------------------------------------

function dc_new_turn($seat) {
  $zero = ['gen' => 0, 'Political' => 0, 'Economic' => 0, 'Social' => 0, 'campaign' => 0];
  return [
    'seat' => (int) $seat, 'base' => $zero, 'spent' => $zero, 'played' => [],
    'counts' => ['Political' => 0, 'Economic' => 0, 'Social' => 0],
    'elected' => null, 'bought' => [], 'hits' => 0, 'attacks' => 0,
    'used' => ['sun' => false, 'herald' => false], 'star_done' => false, 'pending' => null,
  ];
}

/** Open a paper's turn: media events pay out, the first-turn catch-up. */
function dc_start_turn(&$game, &$players, $seat, $mysqli) {
  $game['current_seat'] = (int) $seat;
  $t = dc_new_turn($seat);
  $p = &$players[$seat];
  $p['public_state']['turns'] = 1 + (int) $p['public_state']['turns'];
  $p['public_state']['shielded'] = false;
  $p['private_state']['played'] = [];
  if ((int) $p['public_state']['turns'] === 1) {
    $place = array_search((int) $seat, $game['state']['order'], true);
    $t['base']['gen'] += (int) $game['config']['catchup'] * (int) $place;
  }
  foreach ($p['public_state']['locations'] as $loc) {
    $c = dc_view($loc);
    $t['base']['gen'] += (int) $c['ongoing_gen'];
    if ((int) $c['ongoing_draw'] > 0) dc_draw($game, $p, (int) $c['ongoing_draw']);
  }
  unset($p);
  foreach ($players as $s => $q) {
    if ((int) $s === (int) $seat) continue;
    foreach ($q['public_state']['locations'] ?? [] as $loc) {
      $c = dc_view($loc);
      if ($c['others_theme']) $t['base'][$c['others_theme']] += (int) $c['others_bonus'];
      else $t['base']['gen'] += (int) $c['others_bonus'];
    }
  }
  $game['state']['turn'] = $t;
  $game['state']['turns'] = 1 + (int) $game['state']['turns'];
}

/** Influence available right now: what was made (with every bonus) less what was spent. */
function dc_pools($game, $players) {
  $t = $game['state']['turn'];
  $p = $players[$t['seat']];
  $total = $t['base'];
  $negatives = [];
  foreach ($t['played'] as $id) {
    $c = dc_view($id);
    $th = $c['theme'];
    $n = $th ? (int) ($t['counts'][$th] ?? 0) : 0;
    if ((int) $c['per_same']) $total['gen'] += (int) $c['per_same'] * max(0, $n - 1);
    if ((int) $c['chain'] && $n >= 2) $total['gen'] += (int) $c['chain'];
    if ($c['type'] === 'Negative story') $negatives[$c['kind']] = true;
  }
  if (($p['public_state']['paper'] ?? null) === 'aurora') $total['gen'] += count($negatives);
  $out = [];
  foreach ($total as $k => $v) $out[$k] = $v - (int) $t['spent'][$k];
  return $out;
}

/** Take $need from the pools in $order; returns false (and takes nothing) if they fall short. */
function dc_pay(&$game, $pools, $need, $order) {
  $have = 0;
  foreach ($order as $k) $have += max(0, $pools[$k]);
  if ($have < $need) return false;
  foreach ($order as $k) {
    if ($need <= 0) break;
    $use = min(max(0, $pools[$k]), $need);
    $game['state']['turn']['spent'][$k] += $use;
    $need -= $use;
  }
  return true;
}

/** The pools a purchase may draw on, themed first. */
function dc_buy_order($c) {
  return ($c['theme'] && in_array($c['theme'], DC_THEMES, true)) ? [$c['theme'], 'gen'] : ['gen'];
}

/** The pools that may elect a man of $theme, in spending order. */
function dc_elect_order($player, $theme) {
  $order = [$theme, 'campaign'];
  if (($player['public_state']['paper'] ?? null) === 'globe' && $theme !== 'Political') $order[] = 'Political';
  $order[] = 'gen';
  return $order;
}

function dc_threshold($game, $side) {
  return (int) dc_election($game['state']['e'])[$side . '_threshold'];
}

/** Play one card from the current paper's hand. */
function dc_play(&$game, &$players, $cardId, $mysqli) {
  $t = &$game['state']['turn'];
  $seat = $t['seat'];
  $p = &$players[$seat];
  $at = array_search($cardId, $p['private_state']['hand'], true);
  if ($at === false) throw new Exception('That card is not in your hand.');
  array_splice($p['private_state']['hand'], $at, 1);
  $c = dc_view($cardId);
  $t['played'][] = $cardId;
  $p['private_state']['played'][] = $cardId;
  $paper = $p['public_state']['paper'] ?? null;

  $th = $c['theme'];
  if ($th && $c['type'] !== 'Election') {
    $t['counts'][$th] += 1;
    if ($paper === 'journal' && $th === 'Economic' && $t['counts']['Economic'] <= 2) $t['base']['gen'] += 1;
    if ($paper === 'north_star' && $th === 'Social' && !$t['star_done']
        && $t['counts']['Social'] === (int) $game['config']['north_star_at']) {
      $t['star_done'] = true;
      dc_draw($game, $p, (int) $game['config']['north_star_draw']);
    }
  }
  $t['base']['gen'] += (int) $c['gen'];
  if ($th) $t['base'][$th] += (int) $c['themed'];
  $t['base']['campaign'] += (int) $c['campaign'];
  if ((int) $c['per_office']) $t['base'][$th] += (int) $c['per_office'] * dc_offices($players[$seat]);
  if ((int) $c['draw']) dc_draw($game, $p, (int) $c['draw']);
  for ($i = 0; $i < (int) $c['retract']; $i++) dc_retract($game, $p);
  if ((int) $c['trash']) {
    $opts = array_values(array_merge($p['private_state']['hand'], $p['private_state']['discard']));
    if ($opts) $t['pending'] = ['type' => 'trash', 'card' => $cardId, 'options' => $opts];
  }
  if ((int) $c['gain_upto']) {
    $opts = [];
    foreach ($game['state']['exchange'] as $x) if ((int) dc_view($x)['cost'] <= (int) $c['gain_upto']) $opts[] = $x;
    if ($opts) {
      // A card with both a trash and a gain resolves the trash first.
      $gain = ['type' => 'gain', 'card' => $cardId, 'options' => $opts, 'max_cost' => (int) $c['gain_upto']];
      if ($t['pending']) $t['pending']['then'] = $gain; else $t['pending'] = $gain;
    }
  }
  unset($p, $t);
  if ($c['attack']) {
    $hits = 0;
    foreach ($game['state']['order'] as $s) {
      if ((int) $s === (int) $seat || !empty($players[$s]['conceded'])) continue;
      if (dc_attack($game, $players[$s], $c['attack'])) $hits++;
    }
    $game['state']['turn']['attacks'] += 1;
    $game['state']['turn']['hits'] += $hits;
    $players[$seat]['public_state']['attacks'] = 1 + (int) $players[$seat]['public_state']['attacks'];
    if ($hits > 0) $game['state']['turn']['base']['gen'] += (int) $game['config']['attack_reward'];
    if ($mysqli) {
      engine_log($mysqli, $game, $seat, 'attack',
        $players[$seat]['player_name'] . ' ran ' . $c['name'] . ': '
        . ($c['attack'] === 'scandal' ? 'a Scandal for every rival' : 'every rival discards a card')
        . ' (' . $hits . ' hit).', ['card' => $cardId, 'kind' => $c['attack'], 'hits' => $hits],
        $players[$seat]['player_name']);
    }
  }
  dc_count($players[$seat]);
}

/** Retraction: draw a card, then destroy a Scandal in hand or discard pile. */
function dc_retract(&$game, &$p) {
  dc_draw($game, $p, 1);
  foreach (['hand', 'discard'] as $pile) {
    foreach ($p['private_state'][$pile] as $i => $id) {
      if (strpos($id, 'scandal#') === 0) {
        array_splice($p['private_state'][$pile], $i, 1);
        $game['state']['trash'][] = $id;
        return;
      }
    }
  }
}

/** One attack on one rival. Returns whether it hit. */
function dc_attack(&$game, &$q, $kind) {
  foreach ($q['private_state']['hand'] as $i => $id) {
    if ((int) dc_view($id)['defense']) {          // Defense is automatic
      array_splice($q['private_state']['hand'], $i, 1);
      $q['private_state']['discard'][] = $id;
      dc_draw($game, $q, 1);
      return false;
    }
  }
  if ($kind === 'discard') {
    if (!$q['private_state']['hand']) return false;
    $i = mt_rand(0, count($q['private_state']['hand']) - 1);
    $id = $q['private_state']['hand'][$i];
    array_splice($q['private_state']['hand'], $i, 1);
    $q['private_state']['discard'][] = $id;
    dc_count($q);
    return true;
  }
  if ($kind === 'scandal') {
    if (($q['public_state']['paper'] ?? null) === 'intelligencer') return false;   // never gains Scandals
    if ((int) $game['state']['scandals'] <= 0) return false;
    $game['state']['scandals'] -= 1;
    $q['private_state']['discard'][] = 'scandal#' . $game['state']['scandals'];
    $q['public_state']['scandals_taken'] = 1 + (int) ($q['public_state']['scandals_taken'] ?? 0);
    dc_count($q);
    return true;
  }
  return false;
}

/** Answer the pending prompt (trash or gain). $pick null = decline. */
function dc_choose(&$game, &$players, $pick) {
  $t = &$game['state']['turn'];
  $pend = $t['pending'];
  if (!$pend) throw new Exception('Nothing to choose.');
  $p = &$players[$t['seat']];
  if ($pick !== null && $pick !== '') {
    if (!in_array($pick, $pend['options'], true)) throw new Exception('That is not one of the choices.');
    if ($pend['type'] === 'trash') {
      foreach (['hand', 'discard'] as $pile) {
        $i = array_search($pick, $p['private_state'][$pile], true);
        if ($i !== false) { array_splice($p['private_state'][$pile], $i, 1); break; }
      }
      $game['state']['trash'][] = $pick;
    } else {
      $i = array_search($pick, $game['state']['exchange'], true);
      if ($i === false) throw new Exception('That story is no longer on the exchange.');
      array_splice($game['state']['exchange'], $i, 1);
      $p['private_state']['discard'][] = $pick;
      dc_refill($game);
    }
  }
  $next = $pend['then'] ?? null;
  if ($next && $next['type'] === 'gain') {
    // Recompute: the exchange may have changed.
    $opts = [];
    foreach ($game['state']['exchange'] as $x) if ((int) dc_view($x)['cost'] <= (int) $next['max_cost']) $opts[] = $x;
    $next['options'] = $opts;
    $t['pending'] = $opts ? $next : null;
  } else {
    $t['pending'] = null;
  }
  unset($t);
  dc_count($p);
}

/** Elect: reach one man's threshold, take the election card. */
function dc_elect(&$game, &$players, $side, $mysqli) {
  $t = $game['state']['turn'];
  if ($t['elected']) throw new Exception('You have already won an election this turn.');
  if ($side !== 'nation' && $side !== 'states') throw new Exception('Name the man: nation or states.');
  $seat = $t['seat'];
  $e = dc_election($game['state']['e']);
  $theme = $e[$side . '_theme'];
  $need = dc_threshold($game, $side);
  if (!dc_pay($game, dc_pools($game, $players), $need, dc_elect_order($players[$seat], $theme))) {
    throw new Exception($e[$side] . ' needs ' . $need . ' influence (' . $theme . ', Campaign or plain).');
  }
  $card = 'elec#' . $game['state']['e'] . ':' . $side;
  $players[$seat]['private_state']['discard'][] = $card;
  $players[$seat]['public_state']['elections'] = 1 + (int) $players[$seat]['public_state']['elections'];
  $game['state']['turn']['elected'] = $side;
  $game['state']['history'][] = [
    'index' => (int) $game['state']['e'], 'year' => (int) $e['year'], 'side' => $side,
    'winner' => $e[$side], 'loser' => $e[$side === 'nation' ? 'states' : 'nation'],
    'seat' => (int) $seat, 'matched_history' => ($side === $e['historical_winner']),
    'round' => (int) $game['round_number'],
  ];
  if ($mysqli) {
    engine_log($mysqli, $game, $seat, 'election',
      $e['year'] . ': ' . $players[$seat]['player_name'] . ' elects ' . $e[$side]
      . ($side === $e['historical_winner'] ? '.' : ', and history is rewritten.'),
      ['year' => (int) $e['year'], 'side' => $side, 'winner' => $e[$side]], $players[$seat]['player_name']);
  }
  $game['state']['e'] += 1;
  if ($game['state']['e'] >= count(dc_elections())) {
    $game['state']['final'] = true;
  } else {
    dc_release($game, (int) dc_election($game['state']['e'])['year']);
    dc_refill($game);
    if ($mysqli) engine_log($mysqli, $game, null, 'campaign_begins', dc_campaign_text($game));
  }
  dc_count($players[$seat]);
}

/** Buy a story off the exchange, or an Editorial. */
function dc_buy(&$game, &$players, $cardId, $mysqli) {
  $t = $game['state']['turn'];
  $seat = $t['seat'];
  if ($cardId === 'editorial') {
    if ((int) $game['state']['editorials'] <= 0) throw new Exception('No Editorials left.');
    $c = dc_card('editorial');
    if (!dc_pay($game, dc_pools($game, $players), (int) $c['cost'], ['gen'])) {
      throw new Exception('An Editorial costs ' . $c['cost'] . ' plain influence.');
    }
    $game['state']['editorials'] -= 1;
    $id = 'editorial#' . $game['state']['editorials'];
  } else {
    $at = array_search($cardId, $game['state']['exchange'], true);
    if ($at === false) throw new Exception('That story is not on the exchange.');
    $c = dc_view($cardId);
    if (!dc_pay($game, dc_pools($game, $players), (int) $c['cost'], dc_buy_order($c))) {
      throw new Exception($c['name'] . ' costs ' . $c['cost'] . ($c['theme'] ? ' (' . $c['theme'] . ' or plain).' : ' plain influence.'));
    }
    array_splice($game['state']['exchange'], $at, 1);     // the exchange refills at the end of the turn
    $id = $cardId;
  }
  $players[$seat]['private_state']['discard'][] = $id;
  $players[$seat]['public_state']['bought'] = 1 + (int) $players[$seat]['public_state']['bought'];
  $game['state']['turn']['bought'][] = $id;
  dc_count($players[$seat]);
}

/** A newspaper's once-a-turn ability: the Sun (discard a Scandal, draw) or the Herald (the scoop). */
function dc_use_paper(&$game, &$players, $mysqli) {
  $t = $game['state']['turn'];
  $seat = $t['seat'];
  $paper = $players[$seat]['public_state']['paper'] ?? null;
  if ($paper === 'sun') {
    if ($t['used']['sun']) throw new Exception('The Sun has already used that this turn.');
    $p = &$players[$seat];
    foreach ($p['private_state']['hand'] as $i => $id) {
      if (strpos($id, 'scandal#') === 0) {
        array_splice($p['private_state']['hand'], $i, 1);
        $p['private_state']['discard'][] = $id;
        $game['state']['turn']['used']['sun'] = true;
        dc_draw($game, $p, (int) $game['config']['sun_draw']);
        unset($p);
        return;
      }
    }
    unset($p);
    throw new Exception('The Sun needs a Scandal in your hand.');
  }
  if ($paper === 'herald') {
    if ($t['used']['herald']) throw new Exception('The Herald has already scooped this turn.');
    if (empty($game['state']['main'])) throw new Exception('The main deck is empty.');
    $cost = (int) $game['config']['herald_cost'];
    if (!dc_pay($game, dc_pools($game, $players), $cost, ['gen'])) throw new Exception('The scoop costs ' . $cost . ' plain influence.');
    // Set aside: it joins the next hand (the simulator's timing).
    $players[$seat]['private_state']['held'][] = array_pop($game['state']['main']);
    $game['state']['turn']['used']['herald'] = true;
    dc_count($players[$seat]);
    return;
  }
  throw new Exception('Your paper has no ability to use now.');
}

/** End the turn: discard, media events into play, draw, and pass to the next paper. */
function dc_end_turn(&$game, &$players, $mysqli) {
  $t = $game['state']['turn'];
  $seat = $t['seat'];
  $p = &$players[$seat];
  foreach ($t['played'] as $id) {
    if (dc_view($id)['type'] === 'Media event') $p['public_state']['locations'][] = $id;
    else $p['private_state']['discard'][] = $id;
  }
  foreach ($p['private_state']['hand'] as $id) $p['private_state']['discard'][] = $id;
  $p['private_state']['hand'] = [];
  $p['private_state']['played'] = [];
  dc_draw($game, $p, (int) $game['config']['hand']);
  foreach ($p['private_state']['held'] as $id) $p['private_state']['hand'][] = $id;
  $p['private_state']['held'] = [];
  dc_count($p);
  unset($p);
  dc_refill($game);

  $pools = dc_pools($game, $players);
  $made = 0;
  foreach ($t['base'] as $k => $v) $made += $pools[$k] + (int) $t['spent'][$k];
  $game['state']['last_turn'] = [
    'seat' => (int) $seat, 'played' => $t['played'], 'influence' => $made,
    'elected' => $t['elected'], 'bought' => $t['bought'], 'attacks' => $t['attacks'], 'hits' => $t['hits'],
  ];
  if ($mysqli) engine_log($mysqli, $game, $seat, 'turn', dc_turn_text($game, $players, $t, $made),
                          $game['state']['last_turn'], $players[$seat]['player_name']);

  if ($game['state']['final']) {
    engine_end_game($game, $players, 'board_completed', $mysqli);
    return;
  }
  $next = dc_next_seat($game, $players, $seat);
  if ($next === null) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return;
  }
  $order = $game['state']['order'];
  if (array_search($next, $order, true) <= array_search((int) $seat, $order, true)) {
    $game['round_number'] = 1 + (int) $game['round_number'];
    if ($game['round_number'] > (int) $game['config']['max_rounds']) {
      engine_end_game($game, $players, 'stalled', $mysqli);
      return;
    }
  }
  dc_start_turn($game, $players, $next, $mysqli);
}

/** The next seat still playing after $seat, in turn order. */
function dc_next_seat($game, $players, $seat) {
  $order = $game['state']['order'];
  $n = count($order);
  $at = array_search((int) $seat, $order, true);
  for ($k = 1; $k <= $n; $k++) {
    $s = $order[($at + $k) % $n];
    if (empty($players[$s]['conceded'])) return $s;
  }
  return null;
}

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------

/**
 * The single mutating entry point. Throw Exception with a player-facing
 * message to reject an illegal action.
 *
 *   play      {card}          play one card from your hand
 *   play_all  {}              play every card in your hand (stops at a prompt)
 *   choose    {card|null}     answer the pending prompt (trash / gain); null declines
 *   elect     {side}          'nation' or 'states'
 *   buy       {card}          a story on the exchange, or 'editorial'
 *   paper     {}              use your newspaper's ability (the Sun, the Herald)
 *   end_turn  {}
 *   concede   {}
 */
function engine_apply_action(&$game, &$players, $seat, $action, $params, $mysqli) {
  if ($game['status'] !== 'active') throw new Exception('This game is not in progress.');
  if (!engine_is_current($game)) {
    throw new Exception('This game was started under the old rules and can no longer be played. Open a new table from the lobby.');
  }
  if (!isset($players[$seat])) throw new Exception('You are not seated in this game.');
  if (!empty($players[$seat]['conceded'])) throw new Exception('You have already left this game.');
  if ($action === 'concede') return engine_concede($game, $players, $seat, $mysqli);
  if ((int) $game['state']['turn']['seat'] !== (int) $seat) throw new Exception('It is not your turn.');
  $pending = $game['state']['turn']['pending'];
  if ($pending && $action !== 'choose') {
    throw new Exception('Finish your choice first: ' . ($pending['type'] === 'trash' ? 'destroy a card, or decline.' : 'gain a story, or decline.'));
  }
  $name = $players[$seat]['player_name'];
  switch ($action) {
    case 'play':
      dc_play($game, $players, (string) ($params['card'] ?? ''), $mysqli);
      $msg = $name . ' played ' . dc_view((string) $params['card'])['name'] . '.';
      break;
    case 'play_all':
      $n = 0;
      while (!empty($players[$seat]['private_state']['hand']) && !$game['state']['turn']['pending']) {
        dc_play($game, $players, $players[$seat]['private_state']['hand'][0], $mysqli);
        $n++;
      }
      $msg = $name . ' played ' . $n . ' ' . ($n === 1 ? 'card' : 'cards') . '.';
      break;
    case 'choose':
      $pick = $params['card'] ?? null;
      dc_choose($game, $players, $pick === null ? null : (string) $pick);
      $msg = 'Done.';
      break;
    case 'elect':
      dc_elect($game, $players, (string) ($params['side'] ?? ''), $mysqli);
      $msg = 'Elected.';
      break;
    case 'buy':
      dc_buy($game, $players, (string) ($params['card'] ?? ''), $mysqli);
      $msg = 'Bought.';
      break;
    case 'paper':
      dc_use_paper($game, $players, $mysqli);
      $msg = 'Done.';
      break;
    case 'end_turn':
      dc_end_turn($game, $players, $mysqli);
      $msg = 'Turn over.';
      break;
    default:
      throw new Exception('Unknown action: ' . $action);
  }
  if ($game['status'] === 'active') engine_run_bots($game, $players, $mysqli);
  return $msg;
}

/** Leave the table. On your turn, the turn passes on. */
function engine_concede(&$game, &$players, $seat, $mysqli) {
  $players[$seat]['conceded'] = 1;
  $msg = $players[$seat]['player_name'] . ' shut down the presses.';
  if ($mysqli) engine_log($mysqli, $game, $seat, 'concede', $msg, null, $players[$seat]['player_name']);
  if (engine_human_seats($players) < 1) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return $msg;
  }
  if ((int) $game['state']['turn']['seat'] === (int) $seat) {
    $game['state']['turn']['pending'] = null;
    $game['state']['final'] = $game['state']['final'] ?? false;
    dc_end_turn($game, $players, $mysqli);
  }
  if ($game['status'] === 'active') engine_run_bots($game, $players, $mysqli);
  return $msg;
}

/**
 * Let every bot whose turn it is play, until a person is on turn or the
 * game ends.
 */
function engine_run_bots(&$game, &$players, $mysqli) {
  $guard = 0;
  while ($game['status'] === 'active' && engine_human_seats($players) > 0) {
    $seat = (int) $game['state']['turn']['seat'];
    if (empty($players[$seat]['is_bot'])) return;
    dc_bot_turn($game, $players, $mysqli);
    if (++$guard > 500) throw new Exception('Bots did not yield.');
  }
}

// ---------------------------------------------------------------------
// The bot -- a port of Bot / value() in tools/simulate_dc.py (the
// "balanced" bot the balance was tuned against). Keep them in step.
// ---------------------------------------------------------------------

/** Bot styles by name, as in the simulator's BOTS. Both bot levels play "balanced" for now. */
function dc_bot_styles() {
  return [
    'balanced'  => ['focus' => null, 'attack' => 1.0, 'big' => false, 'min_value' => 1.5],
    'political' => ['focus' => 'Political', 'attack' => 1.0, 'big' => false, 'min_value' => 1.5],
    'economic'  => ['focus' => 'Economic', 'attack' => 1.0, 'big' => false, 'min_value' => 1.5],
    'social'    => ['focus' => 'Social', 'attack' => 1.0, 'big' => false, 'min_value' => 1.5],
    'attacker'  => ['focus' => null, 'attack' => 2.5, 'big' => false, 'min_value' => 1.5],
    'pacifist'  => ['focus' => null, 'attack' => 0.0, 'big' => false, 'min_value' => 1.5],
    'bigmoney'  => ['focus' => null, 'attack' => 1.0, 'big' => true, 'min_value' => 1.5],
  ];
}

function dc_bot_style($game, $player) {
  $styles = dc_bot_styles();
  $name = $player['public_state']['bot_style'] ?? 'balanced';
  return $styles[$name] ?? $styles['balanced'];
}

/** How much a bot wants a card: value() in the simulator. */
function dc_bot_value($c) {
  if ($c['type'] === 'Media event') {
    return 1.2 * $c['vp'] + 3 * ($c['ongoing_gen'] + 1.3 * $c['ongoing_draw']) - 0.5 * $c['others_bonus'];
  }
  $v = 1.2 * $c['vp'] + $c['gen'] + 0.8 * $c['themed'] + 0.6 * $c['campaign'] + 1.3 * $c['draw']
     + 0.8 * $c['trash'] + 0.4 * $c['gain_upto'] + 0.6 * $c['chain'] + 0.8 * $c['per_same']
     + 1.0 * $c['per_office'] + 0.3 * $c['defense'] + 0.8 * $c['retract'];
  if ($c['attack'] === 'scandal') $v += 1.5;
  elseif ($c['attack'] === 'discard') $v += 1.0;
  return $v;
}

/** Bot.score: the value, bent by the bot's style; near the end only prestige counts. */
function dc_bot_score($game, $style, $id) {
  $c = ($id === 'editorial') ? dc_card('editorial') : dc_view($id);
  $v = dc_bot_value($c);
  if ($style['focus']) {
    if ($c['theme'] === $style['focus']) $v *= 1.5;
    elseif ($c['theme'] || $c['type'] === 'Editorial') $v *= 0.85;
  }
  if ($c['attack']) $v *= $style['attack'];
  if ($style['big'] && $c['type'] !== 'Editorial' && (int) $c['cost'] < 5) $v = 0;
  if ((int) $game['state']['e'] >= count(dc_elections()) - 2) $v = $c['vp'] * 2 + 0.2 * $v;
  return $v;
}

/** Bot.choose: the best option (cost breaks a tie, the first wins a full tie); buying needs min_value. */
function dc_bot_choose($game, $style, $options, $buying) {
  $best = null;
  $bestKey = null;
  foreach ($options as $id) {
    $c = ($id === 'editorial') ? dc_card('editorial') : dc_view($id);
    $key = [dc_bot_score($game, $style, $id), (int) $c['cost']];
    if ($bestKey === null || $key > $bestKey) { $best = $id; $bestKey = $key; }
  }
  if ($best !== null && $buying && $bestKey[0] < $style['min_value']) return null;
  return $best;
}

/** The trash choice: a Scandal, then a Local Notice, then (once the deck has grown) a Letter. */
function dc_bot_trash_pick($player, $options) {
  $hand = $player['private_state']['hand'];
  $discard = $player['private_state']['discard'];
  $pool = array_merge($hand, $discard);
  foreach ($pool as $id) if (strpos($id, 'scandal#') === 0 && in_array($id, $options, true)) return $id;
  foreach ($pool as $id) if (strpos($id, 'notice#') === 0 && in_array($id, $options, true)) return $id;
  $grown = 0;
  foreach (dc_owned($player) as $id) {
    if (strpos($id, 'letter#') !== 0 && strpos($id, 'notice#') !== 0 && strpos($id, 'scandal#') !== 0) $grown++;
  }
  if ($grown >= 8) {
    foreach ($discard as $id) if (strpos($id, 'letter#') === 0 && in_array($id, $options, true)) return $id;
  }
  return null;
}

/** One whole bot turn, in the simulator's order. */
function dc_bot_turn(&$game, &$players, $mysqli) {
  $seat = (int) $game['state']['turn']['seat'];
  $style = dc_bot_style($game, $players[$seat]);
  $paper = $players[$seat]['public_state']['paper'] ?? null;

  // The Sun first: discard a Scandal, draw.
  if ($paper === 'sun') {
    foreach ($players[$seat]['private_state']['hand'] as $id) {
      if (strpos($id, 'scandal#') === 0) { dc_use_paper($game, $players, $mysqli); break; }
    }
  }
  // Play everything, answering prompts as the simulator's bot does.
  $safety = 0;
  while (++$safety < 200) {
    $t = $game['state']['turn'];
    if ($t['pending']) {
      $pend = $t['pending'];
      $pick = ($pend['type'] === 'trash')
        ? dc_bot_trash_pick($players[$seat], $pend['options'])
        : dc_bot_choose($game, $style, $pend['options'], false);
      dc_choose($game, $players, $pick);
      continue;
    }
    if (empty($players[$seat]['private_state']['hand'])) break;
    dc_play($game, $players, $players[$seat]['private_state']['hand'][0], $mysqli);
  }
  // Elect: the man it can reach spending the least plain influence; history breaks a tie.
  $sides = dc_electable($game, $players);
  if ($sides) {
    $pools = dc_pools($game, $players);
    $e = dc_election($game['state']['e']);
    $best = null;
    foreach ($sides as $side) {
      $need = dc_threshold($game, $side);
      $other = 0;
      foreach (dc_elect_order($players[$seat], $e[$side . '_theme']) as $k) {
        if ($k !== 'gen') $other += max(0, $pools[$k]);
      }
      $rank = [max(0, $need - $other), $side !== $e['historical_winner'] ? 1 : 0];
      if ($best === null || $rank < $best[0]) $best = [$rank, $side];
    }
    dc_elect($game, $players, $best[1], $mysqli);
  }
  // Buy while something is worth it.
  $safety = 0;
  while (++$safety < 50) {
    $pick = dc_bot_choose($game, $style, dc_affordable($game, $players), true);
    if ($pick === null) break;
    dc_buy($game, $players, $pick, $mysqli);
  }
  // The Herald's scoop, while stories still build the deck.
  if ($paper === 'herald' && !$game['state']['turn']['used']['herald'] && !empty($game['state']['main'])
      && (int) $game['state']['e'] < count(dc_elections()) - 2
      && max(0, dc_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) {
    dc_use_paper($game, $players, $mysqli);
  }
  dc_end_turn($game, $players, $mysqli);
}

/** Sides the paper on turn can elect right now. */
function dc_electable($game, $players) {
  $t = $game['state']['turn'];
  if ($t['elected'] || $game['state']['e'] >= count(dc_elections())) return [];
  $pools = dc_pools($game, $players);
  $e = dc_election($game['state']['e']);
  $out = [];
  foreach (['nation', 'states'] as $side) {
    $have = 0;
    foreach (dc_elect_order($players[$t['seat']], $e[$side . '_theme']) as $k) $have += max(0, $pools[$k]);
    if ($have >= dc_threshold($game, $side)) $out[] = $side;
  }
  return $out;
}

/** Exchange stories (and 'editorial') the paper on turn can buy right now. */
function dc_affordable($game, $players) {
  $pools = dc_pools($game, $players);
  $out = [];
  foreach ($game['state']['exchange'] as $id) {
    $c = dc_view($id);
    $have = 0;
    foreach (dc_buy_order($c) as $k) $have += max(0, $pools[$k]);
    if ($have >= (int) $c['cost']) $out[] = $id;
  }
  if ((int) $game['state']['editorials'] > 0 && max(0, $pools['gen']) >= (int) dc_card('editorial')['cost']) $out[] = 'editorial';
  return $out;
}

// ---------------------------------------------------------------------
// Ending and scoring
// ---------------------------------------------------------------------

/** Finish the game. Idempotent. */
function engine_end_game(&$game, &$players, $reason, $mysqli) {
  if ($game['status'] === 'ended') return;
  $game['status'] = 'ended';
  $game['phase'] = 'results';
  $game['current_seat'] = null;
  $game['ended_reason'] = $reason;
  foreach ($players as $seat => $p) {
    // Anything still in play or held counts: it is owned.
    $r = engine_score_player($players, $seat, $game);
    $players[$seat]['final_score'] = (int) $r['total'];
    $players[$seat]['score'] = (int) $r['total'];
    $players[$seat]['score_breakdown'] = $r['breakdown'];
  }
  // Most prestige; a tie goes to the paper with more elections won.
  $best = null;
  foreach (engine_seat_list($players) as $seat) {
    if (!empty($players[$seat]['conceded'])) continue;
    if ($best === null) { $best = $seat; continue; }
    $a = [(int) $players[$seat]['final_score'], (int) $players[$seat]['public_state']['elections']];
    $b = [(int) $players[$best]['final_score'], (int) $players[$best]['public_state']['elections']];
    if ($a > $b) $best = $seat;
  }
  $game['winner_seat'] = $best;
  if ($mysqli) {
    engine_log($mysqli, $game, null, 'game_ended',
      engine_ended_text($reason) . ($best !== null ? ' ' . $players[$best]['player_name'] . ' is the most honoured paper.' : ''),
      ['reason' => $reason, 'winner_seat' => $best, 'elections' => count($game['state']['history'])]);
  }
}

function engine_ended_text($reason) {
  $text = [
    'board_completed' => 'It is 1860: the last election is decided.',
    'all_humans_left' => 'The last editor walked away.',
    'stalled'         => 'The presses ran too long; the game is called.',
    'rules_changed'   => 'This game was started under the old rules.',
  ];
  return $text[$reason] ?? 'The game ended.';
}

/** Final score: prestige on every card owned (+ the Argus's bonus). */
function engine_score_player($players, $seat, $game = null) {
  $p = $players[$seat];
  $argus = $game ? (int) ($game['config']['argus_vp'] ?? 1) : null;
  $by = ['stories' => 0, 'elections' => 0, 'editorials' => 0, 'media' => 0, 'scandals' => 0, 'argus' => 0];
  foreach (dc_owned($p) as $id) {
    $c = dc_view($id);
    if (!$c) continue;
    $k = $c['type'] === 'Election' ? 'elections' : ($c['type'] === 'Editorial' ? 'editorials'
       : ($c['type'] === 'Media event' ? 'media' : ($c['type'] === 'Scandal' ? 'scandals' : 'stories')));
    $by[$k] += (int) $c['vp'];
  }
  if (($p['public_state']['paper'] ?? null) === 'argus') $by['argus'] = ($argus ?? 1) * dc_offices($p);
  $total = array_sum($by);
  return ['total' => $total, 'breakdown' => $by + [
    'prestige' => $total,
    'paper' => $p['public_state']['paper'] ?? null,
    'elections_won' => (int) ($p['public_state']['elections'] ?? 0),
    'bought' => (int) ($p['public_state']['bought'] ?? 0),
    'attacks' => (int) ($p['public_state']['attacks'] ?? 0),
    'scandals_taken' => (int) ($p['public_state']['scandals_taken'] ?? 0),
    'cards_owned' => count(dc_owned($p)),
  ]];
}

// ---------------------------------------------------------------------
// Public projection -- the ONLY thing getState.php serialises
// ---------------------------------------------------------------------

/**
 * Every seat sees the same public payload; one private block for the
 * asking seat. Hands and deck order are private; the exchange, the
 * election, discard counts, media events and what the paper on turn has
 * played are public.
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $current = engine_is_current($game);
  $status = $game['status'];
  $endedReason = $game['ended_reason'];
  if ($status === 'active' && !$current) { $status = 'ended'; $endedReason = 'rules_changed'; }
  $state = $current ? $game['state'] : [];
  $turn = $state['turn'] ?? null;

  $seats = [];
  foreach ($players as $seat => $p) {
    $paper = $p['public_state']['paper'] ?? null;
    $pp = $paper ? (dc_papers()[$paper] ?? null) : null;
    $seats[] = [
      'seat' => (int) $seat, 'player_name' => $p['player_name'], 'is_bot' => (bool) $p['is_bot'],
      'conceded' => (bool) $p['conceded'], 'is_you' => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
      'on_turn' => ($status === 'active' && $turn && (int) $turn['seat'] === (int) $seat),
      'paper' => $pp ? ['key' => $paper, 'name' => $pp['name'], 'ability' => $pp['ability'], 'leans' => $pp['leans']] : null,
      'prestige' => (int) ($p['public_state']['prestige'] ?? 0),
      'elections' => (int) ($p['public_state']['elections'] ?? 0),
      'bought' => (int) ($p['public_state']['bought'] ?? 0),
      'attacks' => (int) ($p['public_state']['attacks'] ?? 0),
      'scandals_taken' => (int) ($p['public_state']['scandals_taken'] ?? 0),
      'hand_count' => (int) ($p['public_state']['hand_count'] ?? 0),
      'deck_count' => (int) ($p['public_state']['deck_count'] ?? 0),
      'discard_count' => (int) ($p['public_state']['discard_count'] ?? 0),
      'discard_top' => ($p['public_state']['discard_top'] ?? null) ? dc_view($p['public_state']['discard_top']) : null,
      'locations' => dc_views($p['public_state']['locations'] ?? []),
      'final_score' => $p['final_score'],
      'score_breakdown' => ($status === 'ended') ? $p['score_breakdown'] : null,
    ];
  }

  $election = null;
  if ($current && $status === 'active' && ($state['e'] ?? 99) < count(dc_elections())) {
    $e = dc_election($state['e']);
    $election = [
      'index' => (int) $state['e'], 'year' => (int) $e['year'], 'era' => $e['era'], 'vp' => (int) $e['vp'],
      'historical_winner' => $e['historical_winner'],
      'nation' => ['name' => $e['nation'], 'theme' => $e['nation_theme'], 'threshold' => (int) $e['nation_threshold']],
      'states' => ['name' => $e['states'], 'theme' => $e['states_theme'], 'threshold' => (int) $e['states_threshold']],
      'patron_gen' => (int) $e['patron_gen'], 'patron_themed' => (int) $e['patron_themed'],
    ];
  }

  $turnPublic = null;
  if ($current && $turn && $status === 'active') {
    $turnPublic = [
      'seat' => (int) $turn['seat'], 'played' => dc_views($turn['played']),
      'pools' => dc_pools($game, $players), 'elected' => $turn['elected'],
      'bought' => dc_views($turn['bought']), 'pending' => $turn['pending'] ? $turn['pending']['type'] : null,
    ];
  }

  $you = null;
  if ($viewerSeat !== null && isset($players[$viewerSeat]) && $current) {
    $me = $players[$viewerSeat];
    $deck = dc_views($me['private_state']['deck'] ?? []);
    usort($deck, function ($a, $b) { return strcmp($a['name'], $b['name']); });   // contents, never order
    $pending = null;
    if ($turn && (int) $turn['seat'] === (int) $viewerSeat && $turn['pending']) {
      $pending = ['type' => $turn['pending']['type'], 'card' => dc_view($turn['pending']['card']),
                  'options' => dc_views($turn['pending']['options'])];
    }
    $you = [
      'seat' => (int) $viewerSeat, 'hand' => dc_views($me['private_state']['hand'] ?? []),
      'deck' => $deck, 'discard' => dc_views($me['private_state']['discard'] ?? []),
      'held' => dc_views($me['private_state']['held'] ?? []), 'pending' => $pending,
    ];
  }

  return [
    'game_id' => (int) $game['game_id'], 'join_code' => $game['join_code'] ?? null, 'status' => $status,
    'variant' => $game['variant'] ?? null, 'phase' => $game['phase'], 'round' => (int) $game['round_number'],
    'current_seat' => $game['current_seat'], 'max_players' => (int) ($game['max_players'] ?? 0),
    'winner_seat' => $game['winner_seat'], 'ended_reason' => $endedReason,
    'ended_text' => $endedReason ? engine_ended_text($endedReason) : null,
    'state_version' => (int) ($game['state_version'] ?? 0),
    'rules' => [
      'hand' => (int) ($game['config']['hand'] ?? 5), 'exchange_size' => (int) ($game['config']['exchange_size'] ?? 5),
      'attack_reward' => (int) ($game['config']['attack_reward'] ?? 1), 'bot_level' => (string) ($game['config']['bot_level'] ?? 'easy'),
    ],
    'election' => $election,
    'elections_total' => count(dc_elections()),
    'elections' => array_map(function ($e) {
      return ['year' => (int) $e['year'], 'era' => $e['era'], 'vp' => (int) $e['vp'],
              'nation' => $e['nation'], 'states' => $e['states'], 'historical_winner' => $e['historical_winner']];
    }, dc_elections()),
    'history' => $state['history'] ?? [],
    'exchange' => dc_views($state['exchange'] ?? []),
    'editorial' => $current ? array_merge(dc_view('editorial#x'), ['left' => (int) $state['editorials']]) : null,
    'main_count' => count($state['main'] ?? []),
    'scandals_left' => (int) ($state['scandals'] ?? 0),
    'news' => dc_views($state['last_released'] ?? []),
    'turn' => $turnPublic,
    'last_turn' => $state['last_turn'] ?? null,
    'players' => $seats,
    'you' => $you,
    'available_actions' => engine_available_actions($game, $players, $viewerSeat),
  ];
}

/** Legal actions for one seat: the advisory mirror the UI renders from. */
function engine_available_actions($game, $players, $seat) {
  if ($seat === null || !isset($players[$seat])) return [];
  if ($game['status'] !== 'active' || !engine_is_current($game)) return [];
  if (!empty($players[$seat]['conceded'])) return [];
  $t = $game['state']['turn'];
  if ((int) $t['seat'] !== (int) $seat) return ['concede' => true];
  if ($t['pending']) return ['choose' => $t['pending']['options'], 'concede' => true];
  $paper = $players[$seat]['public_state']['paper'] ?? null;
  $paperOk = false;
  if ($paper === 'sun' && !$t['used']['sun']) {
    foreach ($players[$seat]['private_state']['hand'] as $id) if (strpos($id, 'scandal#') === 0) $paperOk = true;
  }
  if ($paper === 'herald' && !$t['used']['herald'] && !empty($game['state']['main'])
      && max(0, dc_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) $paperOk = true;
  return [
    'play' => array_values($players[$seat]['private_state']['hand']),
    'play_all' => !empty($players[$seat]['private_state']['hand']),
    'elect' => dc_electable($game, $players),
    'buy' => dc_affordable($game, $players),
    'paper' => $paperOk,
    'end_turn' => true,
    'concede' => true,
  ];
}

// ---------------------------------------------------------------------
// Logging, export, scores
// ---------------------------------------------------------------------

function engine_log($mysqli, $game, $seat, $type, $message = '', $data = null, $playerName = null) {
  if (!$mysqli || !function_exists('log_event')) return;
  log_event($mysqli, (int) $game['game_id'], $seat, $type, $message, $data,
    $playerName, (int) $game['round_number'], $game['phase']);
}

function dc_campaign_text($game) {
  $e = dc_election($game['state']['e']);
  return 'The campaign of ' . $e['year'] . ' opens: ' . $e['nation'] . ' (' . $e['nation_theme'] . ', '
       . $e['nation_threshold'] . ') against ' . $e['states'] . ' (' . $e['states_theme'] . ', '
       . $e['states_threshold'] . ').';
}

/** "The Sun played 6 cards (+11 influence), elected Jefferson (1800), bought The Embargo Act." */
function dc_turn_text($game, $players, $t, $made) {
  $name = $players[$t['seat']]['player_name'];
  $parts = [count($t['played']) . ' ' . (count($t['played']) === 1 ? 'card' : 'cards') . ' played (' . $made . ' influence)'];
  if ($t['elected']) {
    $h = end($game['state']['history']);
    $parts[] = 'elected ' . $h['winner'] . ' (' . $h['year'] . ')';
  }
  if ($t['bought']) {
    $names = [];
    foreach ($t['bought'] as $id) $names[] = dc_view($id)['name'];
    $parts[] = 'bought ' . implode(', ', $names);
  }
  $msg = $name . ': ' . implode('; ', $parts) . '.';
  return mb_strlen($msg) > 480 ? mb_substr($msg, 0, 477) . '...' : $msg;
}

/**
 * The verbatim playthrough export. While a game runs, a player gets only
 * their own private state, and never any deck order.
 */
function engine_build_export($mysqli, $game, $players, $viewerSeat = null) {
  $hide = ($viewerSeat !== null && $game['status'] !== 'ended');
  $seats = [];
  foreach ($players as $seat => $p) {
    $private = ($hide && (int) $seat !== (int) $viewerSeat) ? null : $p['private_state'];
    if ($hide && is_array($private) && isset($private['deck'])) sort($private['deck']);
    $seats[] = [
      'seat' => (int) $seat, 'player_name' => $p['player_name'], 'is_bot' => (bool) $p['is_bot'],
      'conceded' => (bool) $p['conceded'], 'final_score' => $p['final_score'], 'score' => (int) $p['score'],
      'score_breakdown' => $p['score_breakdown'], 'public_state' => $p['public_state'], 'private_state' => $private,
    ];
  }
  $board = $game['state'];
  if ($hide && is_array($board)) {
    $board['main'] = count($board['main'] ?? []);
    if (isset($board['turn']) && (int) $board['turn']['seat'] !== (int) $viewerSeat) $board['turn']['pending'] = null;
  }
  return [
    'export_version' => 5,
    'exported_at' => gmdate('c'),
    'summary' => [
      'game_id' => (int) $game['game_id'], 'join_code' => $game['join_code'] ?? null, 'variant' => $game['variant'] ?? null,
      'status' => $game['status'], 'phase' => $game['phase'], 'rounds' => (int) $game['round_number'],
      'elections_decided' => count($game['state']['history'] ?? []), 'total_elections' => count(dc_elections()),
      'winner_seat' => $game['winner_seat'], 'ended_reason' => $game['ended_reason'],
      'created_at' => $game['created_at'] ?? null, 'ended_at' => $game['ended_at'] ?? null, 'config' => $game['config'],
    ],
    'elections' => $game['state']['history'] ?? [],
    'final_board' => $board,
    'players' => $seats,
    'events' => ($mysqli && function_exists('all_events')) ? all_events($mysqli, (int) $game['game_id']) : [],
  ];
}

/** One vg_scores row per human seat at game end (score = prestige). */
function engine_record_scores($mysqli, $game, $players) {
  if ($game['status'] !== 'ended' || !$mysqli) return;
  $playersCount = count($players);
  foreach ($players as $seat => $p) {
    if (!empty($p['is_bot'])) continue;
    $detail = json_encode($p['score_breakdown'], JSON_UNESCAPED_UNICODE);
    $won = ($game['winner_seat'] !== null && (int) $game['winner_seat'] === (int) $seat) ? 1 : 0;
    $score = (int) ($p['final_score'] ?? 0);
    $stmt = $mysqli->prepare("
      INSERT IGNORE INTO vg_scores
        (game_id, seat, player_name, variant, score, players_count, rounds, ended_reason, won, detail)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $seatVal = (int) $seat;
    $rounds = (int) $game['round_number'];
    $stmt->bind_param('iissiiisis', $game['game_id'], $seatVal, $p['player_name'], $game['variant'], $score,
      $playersCount, $rounds, $game['ended_reason'], $won, $detail);
    @$stmt->execute();
    $stmt->close();
  }
}
