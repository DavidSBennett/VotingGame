<?php
/**
 * engine_2024.php -- the rules engine for the 2024 game (VARIANT.md
 * revision 5): the DC-style Fourth Estate played on the 2024 electoral
 * college.
 *
 * Same entry points as engine_dc.php (engine_setup, engine_apply_action,
 * engine_run_bots, engine_public_state, engine_build_export,
 * engine_record_scores, ...); lib.php loads exactly one engine per request.
 * Needs no lib.php: tools/engine_test_2024.php plays games with no database.
 *
 * Pure functions over the $game and $players arrays the endpoints load.
 * The client is presentation only; the server is always right.
 *
 * ---------------------------------------------------------------------
 * THE RULES (tools/simulate_2024.py plays the same rules; keep them in step)
 *
 * Outlets take TURNS. Each starts with 7 Letters to the Editor (+1) and 3
 * Local Notices (nothing), shuffled; draws 5. Three currencies: NEUTRAL
 * spends on anything; REPUBLICAN only on Trump (calling or buying a state
 * for him, or a Republican story); DEMOCRATIC only on Harris (or a
 * Democratic story); CAMPAIGN only on calling a big state.
 *
 * On its turn an outlet either STAKES -- sets one card from its hand aside,
 * face down, on Trump or Harris, discards the rest and draws 5 -- or plays:
 *   - PLAYS cards from its hand, one at a time, making currency;
 *   - may CALL the big state up, once: the ten biggest states, shuffled,
 *     one at a time. Either side's threshold claims it for that side, and
 *     the outlet gains that side's card. Each call moves the calendar on a
 *     step, releasing that step's stories;
 *   - BUYS off the exchange (5 face up from the main deck: the other 41
 *     states and the stories) -- a state for a side at that side's
 *     threshold -- or Editorials;
 *   - ENDS the turn: everything played and left in hand is discarded (media
 *     events stay in play), it draws 5, and the oldest card on the exchange
 *     goes to the bottom of the main deck (the news cycle).
 * Flipping a state costs 1 more per 6 points of its 2024 margin. A state
 * counts for its side once claimed. When a side reaches 270 the round is
 * played out and the game ends. The score is the prestige (1-12) of the
 * cards an outlet staked on the winner; nothing else scores.
 * Negative stories attack every rival (discard at random, or a Scandal); a
 * Defense card in hand is used automatically; an attack that hits gives +1
 * neutral. Retraction: draw a card and destroy a Scandal.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/cards_2024.php';     // cards, states and the eight outlets (e24_outlets)

define('ENGINE_STATE_VERSION', 24);
define('E24_SIDES', ['trump', 'harris']);
define('E24_KINDS', ['Political', 'Economic', 'Social']);

// ---------------------------------------------------------------------
// Configuration -- mirrors DEFAULTS in tools/simulate_2024.py
// ---------------------------------------------------------------------

function engine_default_config() {
  return [
    'engine_version'   => ENGINE_STATE_VERSION,
    'engine'           => '2024',
    'hand'             => 5,
    'exchange_size'    => 5,
    'win_at'           => 270,
    'max_rounds'       => 80,     // safety: a game no one will finish ends here
    'office_div'       => 3,      // per-office stories: one office per this many states held
    'attack_reward'    => 1,
    'argus_vp'         => 1,
    'sun_draw'         => 3,
    'north_star_at'    => 2,
    'north_star_draw'  => 1,
    'herald_cost'      => 2,
    'power_choice'     => 0.5,
    'stake_eager'      => 6.0,
    'stake_floor'      => 2.0,
    'stake_gap'        => 1,
    'min_players'      => 1,
    'max_players'      => 5,
    'bots'             => 1,
    'bot_level'        => 'easy',
  ];
}

function engine_config_knobs() {
  return [];
}

function engine_is_current($game) {
  return is_array($game['state'] ?? null)
    && (int) ($game['state']['engine_version'] ?? 0) === ENGINE_STATE_VERSION;
}

function e24_other($side) {
  return $side === 'trump' ? 'harris' : 'trump';
}

function e24_party($side) {
  return $side === 'trump' ? 'rep' : 'dem';
}

function e24_side_name($side) {
  return $side === 'trump' ? 'Trump' : 'Harris';
}

// ---------------------------------------------------------------------
// Cards
// ---------------------------------------------------------------------

/**
 * Everything the rules and the client need about one card instance.
 * Starters, Editorials and Scandals are 'kind#n'; stories are their key; a
 * state is 'st#pa' on the exchange (no side yet) or 'st#pa:harris' once
 * claimed.
 */
function e24_view($id) {
  $id = (string) $id;
  if (strpos($id, 'st#') === 0) {
    $rest = substr($id, 3);
    $parts = explode(':', $rest);
    $s = e24_state($parts[0]);
    if (!$s) return null;
    $side = $parts[1] ?? null;
    $sides = [];
    foreach (E24_SIDES as $sd) {
      $sides[$sd] = [
        'cost' => (int) $s[$sd . '_threshold'], 'gen' => (int) $s[$sd . '_gen'], 'party' => (int) $s[$sd . '_party'],
        'draw' => (int) $s[$sd . '_draw'], 'trash' => (int) $s[$sd . '_trash'], 'text' => $s[$sd . '_text'],
        'historical' => $s['winner'] === $sd,
      ];
    }
    $v = [
      'key' => $id, 'kind' => 'state', 'type' => 'State', 'state' => $s['key'], 'abbr' => $s['abbr'],
      // A claimed half is named for that side's 2024 base in the state ("Central Pennsylvania").
      'name' => $side ? $s[$side . '_name'] : $s['state'],
      'half_names' => ['trump' => $s['trump_name'], 'harris' => $s['harris_name']],
      'state_name' => $s['state'], 'side' => $side, 'lean' => $side ? e24_party($side) : null, 'story_kind' => null,
      'ev' => (int) $s['ev'], 'vp' => (int) $s['vp'], 'tier' => $s['tier'], 'margin' => (float) $s['margin'],
      'winner_2024' => $s['winner'], 'big' => $s['deck'] === 'elections', 'sides' => $sides,
      'cost' => $side ? (int) $s[$side . '_threshold'] : min((int) $s['trump_threshold'], (int) $s['harris_threshold']),
      'gen' => $side ? (int) $s[$side . '_gen'] : 0, 'themed' => $side ? (int) $s[$side . '_party'] : 0,
      'campaign' => 0, 'draw' => $side ? (int) $s[$side . '_draw'] : 0, 'trash' => $side ? (int) $s[$side . '_trash'] : 0,
      'gain_upto' => 0, 'chain' => 0, 'per_same' => 0, 'per_office' => 0, 'attack' => null, 'defense' => 0,
      'retract' => 0, 'ongoing_gen' => 0, 'ongoing_draw' => 0, 'others_bonus' => 0, 'year' => 2024, 'copies' => 1,
      'card_text' => $side
        ? $s['state'] . ', ' . (int) $s['ev'] . ' electoral votes, for ' . e24_side_name($side) . '. ' . $s[$side . '_text']
        : $s['state'] . ', ' . (int) $s['ev'] . ' electoral votes. Buy it for Trump (' . $s['trump_threshold']
          . ': ' . $s['trump_text'] . ') or for Harris (' . $s['harris_threshold'] . ': ' . $s['harris_text'] . ')',
      'flavor' => $s['flavor'],
    ];
    return $v;
  }
  $base = explode('#', $id)[0];
  $c = e24_card($base);
  if (!$c) return null;
  $c['story_kind'] = $c['kind'];       // Political / Economic / Social (stories)
  $c['kind'] = $c['key'];
  $c['key'] = $id;
  $c['side'] = null;
  return $c;
}

function e24_views($ids) {
  $out = [];
  foreach ($ids as $id) {
    $c = e24_view($id);
    if ($c) $out[] = $c;
  }
  return $out;
}

function e24_is_state($id) {
  return strpos((string) $id, 'st#') === 0;
}

function e24_state_key($id) {
  return explode(':', substr((string) $id, 3))[0];
}

function e24_card_side($id) {
  $parts = explode(':', (string) $id);
  return $parts[1] ?? null;
}

// ---------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------

function engine_setup(&$game, &$players, $mysqli = null) {
  $stored = is_array($game['config']) ? $game['config'] : [];
  if ((int) ($stored['engine_version'] ?? 0) !== ENGINE_STATE_VERSION) {
    $stored = isset($stored['bots']) ? ['bots' => (int) $stored['bots'], 'bot_level' => $stored['bot_level'] ?? 'easy'] : [];
  }
  $config = array_merge(engine_default_config(), $stored);
  $config['engine_version'] = ENGINE_STATE_VERSION;
  $config['engine'] = '2024';
  $game['config'] = $config;

  $game['status'] = 'active';
  $game['phase'] = 'turn';
  $game['round_number'] = 1;
  $game['winner_seat'] = null;
  $game['ended_reason'] = null;

  $big = [];
  $main = [];
  foreach (e24_states() as $k => $s) {
    if ($s['deck'] === 'elections') $big[] = $k;
    else $main[] = 'st#' . $k;
  }
  shuffle($big);
  $cards = e24_cards();
  $game['state'] = [
    'engine_version' => ENGINE_STATE_VERSION,
    'big'        => $big,                    // the elections deck, in the order it comes up
    'e'          => 0,                       // the big state up
    'step'       => 0,                       // the calendar
    'main'       => $main,
    'exchange'   => [],
    'editorials' => (int) $cards['editorial']['copies'],
    'scandals'   => (int) $cards['scandal']['copies'],
    'trash'      => [],
    'order'      => engine_seat_list($players),
    'turns'      => 0,
    'claims'     => [],                      // state key => [side, seat, how, round]
    'history'    => [],                      // big states called
    'final'      => false,                   // a side reached 270: the round plays out
    'winner_side' => null,
    'trigger_seat' => null,
    'last_turn'  => null,
    'turn'       => null,
  ];
  e24_release($game, 0);
  e24_refill($game);

  $taken = [];
  foreach ($players as $seat => $p) {
    $k = $p['public_state']['paper'] ?? null;
    if ($k && isset(e24_outlets()[$k]) && !in_array($k, $taken, true)) $taken[] = $k;
    else $players[$seat]['public_state']['paper'] = null;
  }
  $free = array_values(array_diff(array_keys(e24_outlets()), $taken));
  shuffle($free);

  foreach ($players as $seat => $p) {
    $paper = $players[$seat]['public_state']['paper'] ?? null;
    if (!$paper) $paper = array_pop($free);
    $style = $players[$seat]['public_state']['bot_style'] ?? null;
    $deck = [];
    for ($i = 0; $i < (int) $cards['letter']['copies']; $i++) $deck[] = 'letter#' . $seat . '.' . $i;
    for ($i = 0; $i < (int) $cards['notice']['copies']; $i++) $deck[] = 'notice#' . $seat . '.' . $i;
    shuffle($deck);
    $players[$seat]['public_state'] = [
      'paper'         => $paper,
      'called'        => 0,
      'states_bought' => 0,
      'ev_claimed'    => 0,
      'bought'        => 0,
      'attacks'       => 0,
      'scandals_taken' => 0,
      'turns'         => 0,
      'stakes'        => 0,                  // how many cards staked (their sides are secret)
      'last_stake'    => -99,
      'hand_count'    => 0,
      'deck_count'    => 0,
      'discard_count' => 0,
      'discard_top'   => null,
      'locations'     => [],
    ];
    if ($style) $players[$seat]['public_state']['bot_style'] = $style;
    $players[$seat]['private_state'] = ['hand' => [], 'deck' => $deck, 'discard' => [], 'held' => [], 'played' => [],
                                        'staked' => []];
    $players[$seat]['score'] = 0;
    e24_draw($game, $players[$seat], (int) $config['hand']);
  }

  e24_start_turn($game, $players, $game['state']['order'][0], $mysqli);
  if ($mysqli) engine_log($mysqli, $game, null, 'campaign_begins', e24_big_text($game));
  engine_run_bots($game, $players, $mysqli);
}

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

function e24_draw(&$game, &$player, $n) {
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
  e24_count($player);
  return $got;
}

function e24_count(&$player) {
  $ps = $player['private_state'];
  $pub = &$player['public_state'];
  $pub['hand_count'] = count($ps['hand'] ?? []);
  $pub['deck_count'] = count($ps['deck'] ?? []);
  $pub['discard_count'] = count($ps['discard'] ?? []);
  $pub['discard_top'] = empty($ps['discard']) ? null : end($ps['discard']);
  $pub['stakes'] = count($ps['staked'] ?? []);
  unset($pub);
}

/** Everything an outlet owns (staked cards apart). */
function e24_owned($player) {
  $ps = $player['private_state'];
  return array_merge($ps['hand'] ?? [], $ps['deck'] ?? [], $ps['discard'] ?? [], $ps['held'] ?? [],
                     $player['public_state']['locations'] ?? [], $ps['played'] ?? []);
}

/** The states an outlet holds in its deck (for per-office stories). */
function e24_states_held($player) {
  $n = 0;
  foreach (e24_owned($player) as $id) if (e24_is_state($id)) $n++;
  return $n;
}

function e24_release(&$game, $step) {
  $fresh = [];
  foreach (e24_cards() as $k => $c) {
    if ($c['step'] !== null && (int) $c['step'] === (int) $step) $fresh[] = $k;
  }
  $game['state']['main'] = array_merge($game['state']['main'], $fresh);
  shuffle($game['state']['main']);
  $game['state']['last_released'] = $fresh;
}

function e24_refill(&$game) {
  $size = (int) ($game['config']['exchange_size'] ?? 5);
  while (count($game['state']['exchange']) < $size && !empty($game['state']['main'])) {
    $game['state']['exchange'][] = array_pop($game['state']['main']);
  }
}

/** The oldest card on the exchange slides to the bottom of the main deck; a fresh one is dealt. */
function e24_news_cycle(&$game) {
  if (empty($game['state']['exchange']) || empty($game['state']['main'])) return;
  $old = array_shift($game['state']['exchange']);
  array_unshift($game['state']['main'], $old);          // the bottom is the start
  e24_refill($game);
}

// ---------------------------------------------------------------------
// The race
// ---------------------------------------------------------------------

/** Electoral votes claimed for each side. */
function e24_tally($game) {
  $ev = ['trump' => 0, 'harris' => 0];
  foreach ($game['state']['claims'] ?? [] as $k => $c) $ev[$c['side']] += (int) e24_state($k)['ev'];
  return $ev;
}

function e24_winner_side($game) {
  $ev = e24_tally($game);
  foreach (E24_SIDES as $s) if ($ev[$s] >= (int) $game['config']['win_at']) return $s;
  return null;
}

function e24_claim(&$game, &$players, $seat, $key, $side, $how, $mysqli) {
  $game['state']['claims'][$key] = ['side' => $side, 'seat' => (int) $seat, 'how' => $how,
                                    'round' => (int) $game['round_number']];
  $players[$seat]['public_state']['ev_claimed'] = (int) e24_state($key)['ev'] + (int) ($players[$seat]['public_state']['ev_claimed'] ?? 0);
  if (!$game['state']['final']) {
    $w = e24_winner_side($game);
    if ($w) {
      $game['state']['final'] = true;
      $game['state']['winner_side'] = $w;
      $game['state']['trigger_seat'] = (int) $seat;
      if ($mysqli) {
        $ev = e24_tally($game);
        engine_log($mysqli, $game, $seat, 'race_called',
          e24_side_name($w) . ' reaches ' . $ev[$w] . ' with ' . e24_state($key)['state'] . ', claimed by '
          . $players[$seat]['player_name'] . '. The round plays out, then the stakes are revealed.',
          ['side' => $w, 'ev' => $ev], $players[$seat]['player_name']);
      }
    }
  }
}

// ---------------------------------------------------------------------
// The turn
// ---------------------------------------------------------------------

function e24_new_turn($seat) {
  $zero = ['gen' => 0, 'rep' => 0, 'dem' => 0, 'campaign' => 0];
  return [
    'seat' => (int) $seat, 'base' => $zero, 'spent' => $zero, 'played' => [],
    'counts' => ['Political' => 0, 'Economic' => 0, 'Social' => 0],
    'called' => null, 'bought' => [], 'hits' => 0, 'attacks' => 0, 'staked' => false,
    'used' => ['sun' => false, 'herald' => false], 'star_done' => false, 'pending' => null, 'queue' => [],
  ];
}

function e24_start_turn(&$game, &$players, $seat, $mysqli) {
  $game['current_seat'] = (int) $seat;
  $t = e24_new_turn($seat);
  $p = &$players[$seat];
  $p['public_state']['turns'] = 1 + (int) $p['public_state']['turns'];
  $p['private_state']['played'] = [];
  foreach ($p['public_state']['locations'] as $loc) {
    $c = e24_view($loc);
    $t['base']['gen'] += (int) $c['ongoing_gen'];
    if ((int) $c['ongoing_draw'] > 0) e24_draw($game, $p, (int) $c['ongoing_draw']);
  }
  unset($p);
  foreach ($players as $s => $q) {
    if ((int) $s === (int) $seat) continue;
    foreach ($q['public_state']['locations'] ?? [] as $loc) {
      $c = e24_view($loc);
      $t['base'][$c['lean'] ?: 'gen'] += (int) $c['others_bonus'];   // the others' bonus, in the event's party currency
    }
  }
  $game['state']['turn'] = $t;
  $game['state']['turns'] = 1 + (int) $game['state']['turns'];
}

/** Currency available right now: what was made (with every bonus) less what was spent. */
function e24_pools($game, $players) {
  $t = $game['state']['turn'];
  $p = $players[$t['seat']];
  $total = $t['base'];
  $negatives = [];
  foreach ($t['played'] as $id) {
    $c = e24_view($id);
    $k = $c['story_kind'];
    $n = $k ? (int) ($t['counts'][$k] ?? 0) : 0;
    if ((int) $c['per_same']) $total['gen'] += (int) $c['per_same'] * max(0, $n - 1);
    if ((int) $c['chain'] && $n >= 2) $total['gen'] += (int) $c['chain'];
    if ($c['type'] === 'Negative story') $negatives[$c['kind']] = true;
  }
  if (($p['public_state']['paper'] ?? null) === 'aurora') $total['gen'] += count($negatives);
  $out = [];
  foreach ($total as $k => $v) $out[$k] = $v - (int) $t['spent'][$k];
  return $out;
}

function e24_pay(&$game, $pools, $need, $order) {
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

/** The pools that pay for $side, in spending order (the Globe: either party's). */
function e24_side_order($player, $side, $campaign) {
  $order = [e24_party($side)];
  if ($campaign) $order[] = 'campaign';
  if (($player['public_state']['paper'] ?? null) === 'globe') $order[] = e24_party(e24_other($side));
  $order[] = 'gen';
  return $order;
}

function e24_story_order($c) {
  return $c['lean'] ? [$c['lean'], 'gen'] : ['gen'];
}

function e24_have($pools, $order) {
  $have = 0;
  foreach ($order as $k) $have += max(0, $pools[$k]);
  return $have;
}

function e24_play(&$game, &$players, $cardId, $mysqli) {
  $t = &$game['state']['turn'];
  if ($t['staked']) throw new Exception('You staked this turn.');
  $seat = $t['seat'];
  $p = &$players[$seat];
  $at = array_search($cardId, $p['private_state']['hand'], true);
  if ($at === false) throw new Exception('That card is not in your hand.');
  array_splice($p['private_state']['hand'], $at, 1);
  $c = e24_view($cardId);
  $t['played'][] = $cardId;
  $p['private_state']['played'][] = $cardId;
  $paper = $p['public_state']['paper'] ?? null;

  $k = $c['story_kind'];
  if ($k) {
    $t['counts'][$k] += 1;
    if ($paper === 'journal' && $k === 'Economic' && $t['counts']['Economic'] <= 2) $t['base']['gen'] += 1;
    if ($paper === 'north_star' && $k === 'Social' && !$t['star_done']
        && $t['counts']['Social'] === (int) $game['config']['north_star_at']) {
      $t['star_done'] = true;
      e24_draw($game, $p, (int) $game['config']['north_star_draw']);
    }
  }
  $t['base']['gen'] += (int) $c['gen'];
  if ($c['lean']) $t['base'][$c['lean']] += (int) $c['themed'];
  $t['base']['campaign'] += (int) $c['campaign'];
  if ((int) $c['per_office'] && $c['lean']) {
    $t['base'][$c['lean']] += (int) $c['per_office'] * intdiv(e24_states_held($players[$seat]), (int) $game['config']['office_div']);
  }
  if ((int) $c['draw']) e24_draw($game, $p, (int) $c['draw']);
  for ($i = 0; $i < (int) $c['retract']; $i++) e24_retract($game, $p);
  if ((int) $c['trash']) e24_push_prompt($t, ['type' => 'trash', 'card' => $cardId, 'left' => (int) $c['trash']]);
  if ((int) $c['gain_upto']) e24_push_prompt($t, ['type' => 'gain', 'card' => $cardId, 'max_cost' => (int) $c['gain_upto'], 'left' => 1]);
  unset($p, $t);
  if (!$game['state']['turn']['pending']) e24_next_prompt($game, $players);
  if ($c['attack']) {
    $hits = 0;
    foreach ($game['state']['order'] as $s) {
      if ((int) $s === (int) $seat || !empty($players[$s]['conceded'])) continue;
      if (e24_attack($game, $players[$s], $c['attack'])) $hits++;
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
  e24_count($players[$seat]);
}

function e24_retract(&$game, &$p) {
  e24_draw($game, $p, 1);
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

function e24_attack(&$game, &$q, $kind) {
  foreach ($q['private_state']['hand'] as $i => $id) {
    if ((int) e24_view($id)['defense']) {
      array_splice($q['private_state']['hand'], $i, 1);
      $q['private_state']['discard'][] = $id;
      e24_draw($game, $q, 1);
      return false;
    }
  }
  if ($kind === 'discard') {
    if (!$q['private_state']['hand']) return false;
    $i = mt_rand(0, count($q['private_state']['hand']) - 1);
    $id = $q['private_state']['hand'][$i];
    array_splice($q['private_state']['hand'], $i, 1);
    $q['private_state']['discard'][] = $id;
    e24_count($q);
    return true;
  }
  if ($kind === 'scandal') {
    if (($q['public_state']['paper'] ?? null) === 'intelligencer') return false;
    if ((int) $game['state']['scandals'] <= 0) return false;
    $game['state']['scandals'] -= 1;
    $q['private_state']['discard'][] = 'scandal#' . $game['state']['scandals'];
    $q['public_state']['scandals_taken'] = 1 + (int) ($q['public_state']['scandals_taken'] ?? 0);
    e24_count($q);
    return true;
  }
  return false;
}

function e24_push_prompt(&$t, $prompt) {
  if (!isset($t['queue'])) $t['queue'] = [];
  $t['queue'][] = $prompt;
}

/** What a prompt can choose from. A claimed state is never destroyed: it stays called. */
function e24_prompt_options($game, $player, $pend) {
  if ($pend['type'] === 'trash') {
    $opts = [];
    foreach (array_merge($player['private_state']['hand'], $player['private_state']['discard']) as $id) {
      if (!e24_is_state($id)) $opts[] = $id;
    }
    return $opts;
  }
  if ($pend['type'] === 'gain') {
    $opts = [];
    foreach ($game['state']['exchange'] as $x) {
      if (!e24_is_state($x) && (int) e24_view($x)['cost'] <= (int) $pend['max_cost']) $opts[] = $x;
    }
    return $opts;
  }
  return [];
}

function e24_next_prompt(&$game, &$players) {
  $t = &$game['state']['turn'];
  $t['pending'] = null;
  while (!empty($t['queue'])) {
    $next = array_shift($t['queue']);
    $next['options'] = e24_prompt_options($game, $players[$t['seat']], $next);
    if ($next['options']) { $t['pending'] = $next; return; }
  }
}

function e24_choose(&$game, &$players, $pick) {
  $pend = $game['state']['turn']['pending'];
  if (!$pend) throw new Exception('Nothing to choose.');
  $seat = $game['state']['turn']['seat'];
  $p = &$players[$seat];
  $done = true;
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
      e24_refill($game);
    }
    $left = (int) ($pend['left'] ?? 1) - 1;
    if ($left > 0 && $pend['type'] === 'trash') {
      $pend['left'] = $left;
      $pend['options'] = e24_prompt_options($game, $p, $pend);
      if ($pend['options']) { $game['state']['turn']['pending'] = $pend; $done = false; }
    }
  }
  if ($done) e24_next_prompt($game, $players);
  e24_count($p);
  unset($p);
}

/** Call the big state up for a side (once a turn). */
function e24_call(&$game, &$players, $side, $mysqli) {
  $t = $game['state']['turn'];
  if ($t['staked']) throw new Exception('You staked this turn.');
  if ($t['called']) throw new Exception('You have already called a big state this turn.');
  if (!in_array($side, E24_SIDES, true)) throw new Exception('Name the side: trump or harris.');
  if ((int) $game['state']['e'] >= count($game['state']['big'])) throw new Exception('Every big state has been called.');
  $seat = $t['seat'];
  $key = $game['state']['big'][$game['state']['e']];
  $s = e24_state($key);
  $need = (int) $s[$side . '_threshold'];
  if (!e24_pay($game, e24_pools($game, $players), $need, e24_side_order($players[$seat], $side, true))) {
    throw new Exception($s['state'] . ' for ' . e24_side_name($side) . ' needs ' . $need . ' ('
      . ($side === 'trump' ? 'Republican' : 'Democratic') . ', Campaign or neutral).');
  }
  $card = 'st#' . $key . ':' . $side;
  $players[$seat]['private_state']['discard'][] = $card;
  $players[$seat]['public_state']['called'] = 1 + (int) $players[$seat]['public_state']['called'];
  $game['state']['turn']['called'] = $key;
  $game['state']['history'][] = [
    'index' => (int) $game['state']['e'], 'key' => $key, 'state' => $s['state'], 'ev' => (int) $s['ev'],
    'side' => $side, 'seat' => (int) $seat, 'matched_history' => ($side === $s['winner']),
    'round' => (int) $game['round_number'],
  ];
  if ($mysqli) {
    engine_log($mysqli, $game, $seat, 'call',
      $players[$seat]['player_name'] . ' calls ' . $s['state'] . ' (' . $s['ev'] . ') for ' . e24_side_name($side)
      . ($side === $s['winner'] ? '.' : ', and history is rewritten.'),
      ['state' => $key, 'side' => $side, 'ev' => (int) $s['ev']], $players[$seat]['player_name']);
  }
  e24_claim($game, $players, $seat, $key, $side, 'call', $mysqli);
  $game['state']['e'] += 1;
  if ($game['state']['e'] < count($game['state']['big'])) {
    $game['state']['step'] += 1;
    e24_release($game, $game['state']['step']);
    e24_refill($game);
    if ($mysqli) engine_log($mysqli, $game, null, 'campaign_begins', e24_big_text($game));
  }
  e24_count($players[$seat]);
}

/** Buy a story or a state (for a side) off the exchange, or an Editorial. */
function e24_buy(&$game, &$players, $cardId, $side, $mysqli) {
  $t = $game['state']['turn'];
  if ($t['staked']) throw new Exception('You staked this turn.');
  $seat = $t['seat'];
  if ($cardId === 'editorial') {
    if ((int) $game['state']['editorials'] <= 0) throw new Exception('No Editorials left.');
    $c = e24_card('editorial');
    if (!e24_pay($game, e24_pools($game, $players), (int) $c['cost'], ['gen'])) {
      throw new Exception('An Editorial costs ' . $c['cost'] . ' neutral.');
    }
    $game['state']['editorials'] -= 1;
    $id = 'editorial#' . $game['state']['editorials'];
  } else {
    $at = array_search($cardId, $game['state']['exchange'], true);
    if ($at === false) throw new Exception('That card is not on the exchange.');
    if (e24_is_state($cardId)) {
      if (!in_array($side, E24_SIDES, true)) throw new Exception('Buy a state for a side: trump or harris.');
      $key = e24_state_key($cardId);
      $s = e24_state($key);
      $need = (int) $s[$side . '_threshold'];
      if (!e24_pay($game, e24_pools($game, $players), $need, e24_side_order($players[$seat], $side, false))) {
        throw new Exception($s['state'] . ' for ' . e24_side_name($side) . ' costs ' . $need . ' ('
          . ($side === 'trump' ? 'Republican' : 'Democratic') . ' or neutral).');
      }
      array_splice($game['state']['exchange'], $at, 1);
      $id = 'st#' . $key . ':' . $side;
      $players[$seat]['public_state']['states_bought'] = 1 + (int) $players[$seat]['public_state']['states_bought'];
      if ($mysqli) {
        engine_log($mysqli, $game, $seat, 'claim',
          $players[$seat]['player_name'] . ' buys ' . $s['state'] . ' (' . $s['ev'] . ') for ' . e24_side_name($side)
          . ($side === $s['winner'] ? '.' : ', and history is rewritten.'),
          ['state' => $key, 'side' => $side, 'ev' => (int) $s['ev']], $players[$seat]['player_name']);
      }
      $players[$seat]['private_state']['discard'][] = $id;
      e24_claim($game, $players, $seat, $key, $side, 'buy', $mysqli);
    } else {
      $c = e24_view($cardId);
      if (!e24_pay($game, e24_pools($game, $players), (int) $c['cost'], e24_story_order($c))) {
        throw new Exception($c['name'] . ' costs ' . $c['cost']
          . ($c['lean'] ? ' (' . ($c['lean'] === 'rep' ? 'Republican' : 'Democratic') . ' or neutral).' : ' neutral.'));
      }
      array_splice($game['state']['exchange'], $at, 1);
      $id = $cardId;
    }
  }
  if (!e24_is_state($id)) $players[$seat]['private_state']['discard'][] = $id;
  $players[$seat]['public_state']['bought'] = 1 + (int) $players[$seat]['public_state']['bought'];
  $game['state']['turn']['bought'][] = $id;
  e24_count($players[$seat]);
}

/** Stake: in place of the whole turn, set one card from the hand aside on a side. */
function e24_stake(&$game, &$players, $cardId, $side, $mysqli) {
  $t = $game['state']['turn'];
  if ($t['played'] || $t['called'] || $t['bought'] || $t['used']['sun'] || $t['used']['herald'] || $t['staked']) {
    throw new Exception('Stake in place of your turn: before you play or buy anything.');
  }
  if (!in_array($side, E24_SIDES, true)) throw new Exception('Stake on a side: trump or harris.');
  $seat = $t['seat'];
  $p = &$players[$seat];
  $at = array_search($cardId, $p['private_state']['hand'], true);
  if ($at === false) throw new Exception('That card is not in your hand.');
  array_splice($p['private_state']['hand'], $at, 1);
  $p['private_state']['staked'][] = ['card' => $cardId, 'side' => $side, 'round' => (int) $game['round_number']];
  $p['public_state']['last_stake'] = (int) $p['public_state']['turns'];
  $game['state']['turn']['staked'] = true;
  e24_count($p);
  unset($p);
  if ($mysqli) {
    engine_log($mysqli, $game, $seat, 'stake',
      $players[$seat]['player_name'] . ' stakes a card, face down (' . count($players[$seat]['private_state']['staked']) . ' staked).',
      null, $players[$seat]['player_name']);
  }
  e24_end_turn($game, $players, $mysqli);
}

function e24_use_paper(&$game, &$players, $mysqli) {
  $t = $game['state']['turn'];
  if ($t['staked']) throw new Exception('You staked this turn.');
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
        e24_draw($game, $p, (int) $game['config']['sun_draw']);
        unset($p);
        return;
      }
    }
    unset($p);
    throw new Exception('The Sun needs a Scandal in your hand.');
  }
  if ($paper === 'herald') {
    if ($t['used']['herald']) throw new Exception('The Herald has already scooped this turn.');
    $at = e24_top_story($game);
    if ($at === null) throw new Exception('There is no story left in the main deck.');
    $cost = (int) $game['config']['herald_cost'];
    if (!e24_pay($game, e24_pools($game, $players), $cost, ['gen'])) throw new Exception('The scoop costs ' . $cost . ' neutral.');
    $story = $game['state']['main'][$at];
    array_splice($game['state']['main'], $at, 1);
    $players[$seat]['private_state']['held'][] = $story;
    $game['state']['turn']['used']['herald'] = true;
    e24_count($players[$seat]);
    return;
  }
  throw new Exception('Your outlet has no ability to use now.');
}

/** The Herald's scoop: the topmost story in the main deck (states are passed over). */
function e24_top_story($game) {
  for ($i = count($game['state']['main']) - 1; $i >= 0; $i--) {
    if (!e24_is_state($game['state']['main'][$i])) return $i;
  }
  return null;
}

/** End the turn: discard, media events into play, draw, the news cycle, the next outlet. */
function e24_end_turn(&$game, &$players, $mysqli) {
  $game['state']['turn']['pending'] = null;
  $game['state']['turn']['queue'] = [];
  $t = $game['state']['turn'];
  $seat = $t['seat'];
  $p = &$players[$seat];
  foreach ($t['played'] as $id) {
    if (e24_view($id)['type'] === 'Media event') $p['public_state']['locations'][] = $id;
    else $p['private_state']['discard'][] = $id;
  }
  foreach ($p['private_state']['hand'] as $id) $p['private_state']['discard'][] = $id;
  $p['private_state']['hand'] = [];
  $p['private_state']['played'] = [];
  e24_draw($game, $p, (int) $game['config']['hand']);
  foreach ($p['private_state']['held'] as $id) $p['private_state']['hand'][] = $id;
  $p['private_state']['held'] = [];
  e24_count($p);
  unset($p);
  e24_refill($game);
  e24_news_cycle($game);

  $pools = e24_pools($game, $players);
  $made = 0;
  foreach ($t['base'] as $k => $v) $made += $pools[$k] + (int) $t['spent'][$k];
  $game['state']['last_turn'] = [
    'seat' => (int) $seat, 'played' => $t['played'], 'influence' => $made, 'staked' => $t['staked'],
    'called' => $t['called'], 'bought' => $t['bought'], 'attacks' => $t['attacks'], 'hits' => $t['hits'],
  ];
  if ($mysqli && !$t['staked']) {
    engine_log($mysqli, $game, $seat, 'turn', e24_turn_text($game, $players, $t, $made),
               $game['state']['last_turn'], $players[$seat]['player_name']);
  }

  $next = e24_next_seat($game, $players, $seat);
  if ($next === null) {
    engine_end_game($game, $players, 'all_humans_left', $mysqli);
    return;
  }
  $order = $game['state']['order'];
  $wraps = array_search($next, $order, true) <= array_search((int) $seat, $order, true);
  if ($wraps && $game['state']['final']) {
    engine_end_game($game, $players, 'race_called', $mysqli);
    return;
  }
  if ($wraps) {
    $game['round_number'] = 1 + (int) $game['round_number'];
    if ($game['round_number'] > (int) $game['config']['max_rounds']) {
      engine_end_game($game, $players, 'stalled', $mysqli);
      return;
    }
  }
  e24_start_turn($game, $players, $next, $mysqli);
}

function e24_next_seat($game, $players, $seat) {
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
 *   choose    {card|null}     answer the pending prompt (destroy / gain); null declines
 *   call      {side}          the big state up, for 'trump' or 'harris'
 *   buy       {card, side?}   a story or state on the exchange (a state needs a side), or 'editorial'
 *   stake     {card, side}    in place of the turn: one card from your hand, face down, on a side
 *   paper     {}              your outlet's ability (the Sun, the Herald)
 *   end_turn  {}
 *   concede   {}
 */
function engine_apply_action(&$game, &$players, $seat, $action, $params, $mysqli) {
  if ($game['status'] !== 'active') throw new Exception('This game is not in progress.');
  if (!engine_is_current($game)) {
    throw new Exception('This game was started under other rules and can no longer be played. Open a new table from the lobby.');
  }
  if (!isset($players[$seat])) throw new Exception('You are not seated in this game.');
  if (!empty($players[$seat]['conceded'])) throw new Exception('You have already left this game.');
  if ($action === 'concede') return engine_concede($game, $players, $seat, $mysqli);
  if ((int) $game['state']['turn']['seat'] !== (int) $seat) throw new Exception('It is not your turn.');
  if ($game['state']['turn']['pending'] && $action !== 'choose') {
    throw new Exception('Finish your choice first: ' . ($game['state']['turn']['pending']['type'] === 'trash'
      ? 'destroy a card, or decline.' : 'gain a story, or decline.'));
  }
  $name = $players[$seat]['player_name'];
  switch ($action) {
    case 'play':
      e24_play($game, $players, (string) ($params['card'] ?? ''), $mysqli);
      $msg = $name . ' played ' . e24_view((string) $params['card'])['name'] . '.';
      break;
    case 'play_all':
      $n = 0;
      if ($game['state']['turn']['staked']) throw new Exception('You staked this turn.');
      while (!empty($players[$seat]['private_state']['hand']) && !$game['state']['turn']['pending']) {
        e24_play($game, $players, $players[$seat]['private_state']['hand'][0], $mysqli);
        $n++;
      }
      $msg = $name . ' played ' . $n . ' ' . ($n === 1 ? 'card' : 'cards') . '.';
      break;
    case 'choose':
      $pick = $params['card'] ?? null;
      e24_choose($game, $players, $pick === null ? null : (string) $pick);
      $msg = 'Done.';
      break;
    case 'call':
      e24_call($game, $players, (string) ($params['side'] ?? ''), $mysqli);
      $msg = 'Called.';
      break;
    case 'buy':
      e24_buy($game, $players, (string) ($params['card'] ?? ''), (string) ($params['side'] ?? ''), $mysqli);
      $msg = 'Bought.';
      break;
    case 'stake':
      e24_stake($game, $players, (string) ($params['card'] ?? ''), (string) ($params['side'] ?? ''), $mysqli);
      $msg = 'Staked.';
      break;
    case 'paper':
      e24_use_paper($game, $players, $mysqli);
      $msg = 'Done.';
      break;
    case 'end_turn':
      e24_end_turn($game, $players, $mysqli);
      $msg = 'Turn over.';
      break;
    default:
      throw new Exception('Unknown action: ' . $action);
  }
  if ($game['status'] === 'active') engine_run_bots($game, $players, $mysqli);
  return $msg;
}

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
    e24_end_turn($game, $players, $mysqli);
  }
  if ($game['status'] === 'active') engine_run_bots($game, $players, $mysqli);
  return $msg;
}

function engine_run_bots(&$game, &$players, $mysqli) {
  $guard = 0;
  while ($game['status'] === 'active' && engine_human_seats($players) > 0) {
    $seat = (int) $game['state']['turn']['seat'];
    if (empty($players[$seat]['is_bot'])) return;
    e24_bot_turn($game, $players, $mysqli);
    if (++$guard > 500) throw new Exception('Bots did not yield.');
  }
}

// ---------------------------------------------------------------------
// The bot -- a port of Bot / value() in tools/simulate_2024.py. Keep them
// in step.
// ---------------------------------------------------------------------

define('E24_VP_WEIGHT', 0.6);

function e24_bot_styles() {
  return [
    'balanced' => ['attack' => 1.0, 'big' => false, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => null],
    'attacker' => ['attack' => 2.5, 'big' => false, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => null],
    'pacifist' => ['attack' => 0.0, 'big' => false, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => null],
    'bigmoney' => ['attack' => 1.0, 'big' => true, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => null],
    'late'     => ['attack' => 1.0, 'big' => false, 'min_value' => 1.5, 'eager' => 2.0, 'partisan' => null],
    'early'    => ['attack' => 1.0, 'big' => false, 'min_value' => 1.5, 'eager' => 0.5, 'partisan' => null],
    'trump'    => ['attack' => 1.0, 'big' => false, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => 'trump'],
    'harris'   => ['attack' => 1.0, 'big' => false, 'min_value' => 1.5, 'eager' => 1.0, 'partisan' => 'harris'],
  ];
}

function e24_bot_style($player) {
  $styles = e24_bot_styles();
  $name = $player['public_state']['bot_style'] ?? 'balanced';
  return $styles[$name] ?? $styles['balanced'];
}

function e24_rand() {
  return mt_rand() / mt_getrandmax();
}

/** value() in the simulator: power, plus E24_VP_WEIGHT per prestige. */
function e24_bot_value($c) {
  $vp = E24_VP_WEIGHT * (int) $c['vp'];
  if ($c['type'] === 'Media event') {
    return $vp + 3 * ($c['ongoing_gen'] + 1.3 * $c['ongoing_draw']) - 0.5 * $c['others_bonus'];
  }
  $v = $vp + $c['gen'] + 0.8 * $c['themed'] + 0.6 * $c['campaign'] + 1.3 * $c['draw']
     + 0.8 * $c['trash'] + 0.4 * $c['gain_upto'] + 0.6 * $c['chain'] + 0.8 * $c['per_same']
     + 1.0 * $c['per_office'] + 0.3 * $c['defense'] + 0.8 * $c['retract'];
  if ($c['attack'] === 'scandal') $v += 1.5;
  elseif ($c['attack'] === 'discard') $v += 1.0;
  return $v;
}

function e24_bot_late($game) {
  return (int) $game['state']['e'] >= count($game['state']['big']) - 2;
}

/** The side this outlet has a stake in (most staked prestige), if any. */
function e24_bot_leaning($style, $player) {
  if ($style['partisan']) return $style['partisan'];
  $w = ['trump' => 0, 'harris' => 0];
  foreach ($player['private_state']['staked'] ?? [] as $st) $w[$st['side']] += (int) e24_view($st['card'])['vp'];
  if (!$w['trump'] && !$w['harris']) return null;
  return $w['harris'] > $w['trump'] ? 'harris' : 'trump';
}

/**
 * If $side reached 270 now, would this outlet top the table? It knows its
 * own stakes; of a rival it sees only how many cards it has staked
 * (guessed at the mean prestige staked so far, on this side half the
 * time), and that rivals still to play this round get one more stake.
 */
function e24_bot_end_pays($game, $players, $seat, $side) {
  $mine = 0;
  foreach ($players[$seat]['private_state']['staked'] ?? [] as $st) {
    if ($st['side'] === $side) $mine += (int) e24_view($st['card'])['vp'];
  }
  $all = [];
  foreach ($players as $q) foreach ($q['private_state']['staked'] ?? [] as $st) $all[] = (int) e24_view($st['card'])['vp'];
  $avg = $all ? array_sum($all) / count($all) : 3.0;
  $order = $game['state']['order'];
  $myAt = array_search((int) $seat, $order, true);
  $best = 0.0;
  foreach ($players as $s => $q) {
    if ((int) $s === (int) $seat || !empty($q['conceded'])) continue;
    $est = 0.5 * $avg * count($q['private_state']['staked'] ?? []);
    if (array_search((int) $s, $order, true) > $myAt) {
      $top = $avg;
      foreach ($q['private_state']['hand'] as $id) $top = max($top, (int) e24_view($id)['vp']);
      $est += $top;
    }
    $best = max($best, $est);
  }
  return $mine >= $best;
}

/** Claim a state only if it does not end the game against this outlet. */
function e24_bot_will_claim($game, $players, $seat, $key, $side) {
  $ev = e24_tally($game);
  if ($ev[$side] + (int) e24_state($key)['ev'] < (int) $game['config']['win_at']) return true;
  return e24_bot_end_pays($game, $players, $seat, $side);
}

/** A state for the side behind is worth more while the side ahead nears 270 and its finishing would not pay. */
function e24_bot_blocking($game, $players, $seat, $side) {
  $ev = e24_tally($game);
  $lead = $ev['harris'] > $ev['trump'] ? 'harris' : 'trump';
  if ($side === $lead || $ev[$lead] < (int) $game['config']['win_at'] - 60) return 0.0;
  return e24_bot_end_pays($game, $players, $seat, $lead) ? 0.0 : 2.0;
}

function e24_bot_score($game, $players, $seat, $style, $id) {
  $c = ($id === 'editorial') ? e24_card('editorial') : e24_view($id);
  $v = e24_bot_value($c);
  if ($c['attack']) $v *= $style['attack'];
  if ($style['big'] && $c['type'] !== 'Editorial' && $c['type'] !== 'State' && (int) $c['cost'] < 5) $v = 0;
  if (e24_bot_late($game)) $v = (int) $c['vp'] + 0.4 * $v;
  if ($c['type'] === 'State' && $c['side']) {
    if ($c['side'] === e24_bot_leaning($style, $players[$seat])) $v += 1.0;
    $v += e24_bot_blocking($game, $players, $seat, $c['side']);
    $s = e24_state($c['state']);
    $v -= 0.15 * ((int) $c['cost'] - (int) $s[$s['winner'] . '_threshold']);
  }
  return $v;
}

/** The neutral a side's claim would still need after its party currency (and Campaign). */
function e24_bot_neutral_needed($player, $pools, $side, $need, $campaign) {
  $other = 0;
  foreach (e24_side_order($player, $side, $campaign) as $k) if ($k !== 'gen') $other += max(0, $pools[$k]);
  return max(0, $need - $other);
}

/** Bot.choose: the best option; buying needs min_value. */
function e24_bot_choose($game, $players, $seat, $style, $options, $buying, $pools = null) {
  $best = null;
  $bestKey = null;
  foreach ($options as $id) {
    $c = ($id === 'editorial') ? e24_card('editorial') : e24_view($id);
    $v = e24_bot_score($game, $players, $seat, $style, $id);
    if ($pools !== null && e24_is_state($id)) {
      $v -= $game['config']['power_choice'] * 0.3 * e24_bot_neutral_needed($players[$seat], $pools, $c['side'], (int) $c['cost'], false);
    }
    $key = [$v, $id === 'editorial' ? 3 : (int) $c['cost'], e24_rand()];
    if ($bestKey === null || $key > $bestKey) { $best = $id; $bestKey = $key; }
  }
  if ($best !== null && $buying && e24_bot_score($game, $players, $seat, $style, $best) < $style['min_value']) return null;
  return $best;
}

/** (the side ahead, the chance it reaches 270 first): the lead over the votes still unclaimed. */
function e24_bot_win_chance($game, $style) {
  $ev = e24_tally($game);
  if ($ev['trump'] === $ev['harris']) $lead = e24_rand() < 0.5 ? 'trump' : 'harris';
  else $lead = $ev['trump'] > $ev['harris'] ? 'trump' : 'harris';
  if ($style['partisan']) $lead = $style['partisan'];
  $margin = $ev[$lead] - $ev[e24_other($lead)];
  $open = 538 - $ev['trump'] - $ev['harris'];
  return [$lead, 1 / (1 + exp(-$margin / (0.15 * $open + 8)))];
}

/** Stake the hand's best bet once it promises enough (the simulator's Bot.stake). */
function e24_bot_stake($game, $players, $seat, $style) {
  $p = $players[$seat];
  if (!$p['private_state']['hand']) return null;
  list($lead, $prob) = e24_bot_win_chance($game, $style);
  $best = null;
  foreach ($p['private_state']['hand'] as $id) {
    $c = e24_view($id);
    $k = [(int) $c['vp'], -e24_bot_value($c)];
    if ($best === null || $k > $best[0]) $best = [$k, $id];
  }
  $expect = $prob * (int) e24_view($best[1])['vp'];
  if ($game['state']['final']) return $expect > 0 ? [$best[1], $game['state']['winner_side']] : null;
  if ((int) $p['public_state']['turns'] - (int) ($p['public_state']['last_stake'] ?? -99) <= (int) $game['config']['stake_gap']) return null;
  $ev = e24_tally($game);
  $need = $game['config']['stake_eager'] * $style['eager'] * max(0.0, 1 - max($ev) / (int) $game['config']['win_at']);
  if ($expect >= max($game['config']['stake_floor'], $need)) return [$best[1], $lead];
  return null;
}

function e24_bot_trash_pick($player, $options) {
  $pool = array_merge($player['private_state']['hand'], $player['private_state']['discard']);
  foreach ($pool as $id) if (strpos($id, 'scandal#') === 0 && in_array($id, $options, true)) return $id;
  foreach ($pool as $id) if (strpos($id, 'notice#') === 0 && in_array($id, $options, true)) return $id;
  $grown = 0;
  foreach (e24_owned($player) as $id) {
    if (strpos($id, 'letter#') !== 0 && strpos($id, 'notice#') !== 0 && strpos($id, 'scandal#') !== 0) $grown++;
  }
  if ($grown >= 8) {
    foreach ($player['private_state']['discard'] as $id) if (strpos($id, 'letter#') === 0 && in_array($id, $options, true)) return $id;
  }
  return null;
}

/** One whole bot turn, in the simulator's order. */
function e24_bot_turn(&$game, &$players, $mysqli) {
  $seat = (int) $game['state']['turn']['seat'];
  $style = e24_bot_style($players[$seat]);
  $paper = $players[$seat]['public_state']['paper'] ?? null;

  $stake = e24_bot_stake($game, $players, $seat, $style);
  if ($stake) {
    e24_stake($game, $players, $stake[0], $stake[1], $mysqli);
    return;
  }
  if ($paper === 'sun') {
    foreach ($players[$seat]['private_state']['hand'] as $id) {
      if (strpos($id, 'scandal#') === 0) { e24_use_paper($game, $players, $mysqli); break; }
    }
  }
  $safety = 0;
  while (++$safety < 200) {
    $t = $game['state']['turn'];
    if ($t['pending']) {
      $pend = $t['pending'];
      $pick = $pend['type'] === 'trash' ? e24_bot_trash_pick($players[$seat], $pend['options'])
                                        : e24_bot_choose($game, $players, $seat, $style, $pend['options'], false);
      e24_choose($game, $players, $pick);
      continue;
    }
    if (empty($players[$seat]['private_state']['hand'])) break;
    e24_play($game, $players, $players[$seat]['private_state']['hand'][0], $mysqli);
  }
  // Call the big state up: weigh each side's card against the neutral it costs.
  $sides = e24_callable($game, $players);
  if ($sides) {
    $pools = e24_pools($game, $players);
    $key = $game['state']['big'][$game['state']['e']];
    $best = null;
    foreach ($sides as $side) {
      if (!e24_bot_will_claim($game, $players, $seat, $key, $side)) continue;
      $need = (int) e24_state($key)[$side . '_threshold'];
      $rank = [e24_bot_score($game, $players, $seat, $style, 'st#' . $key . ':' . $side)
               - $game['config']['power_choice'] * e24_bot_neutral_needed($players[$seat], $pools, $side, $need, true),
               e24_rand()];
      if ($best === null || $rank > $best[0]) $best = [$rank, $side];
    }
    if ($best) e24_call($game, $players, $best[1], $mysqli);
  }
  // Buy while something is worth it (never the state that would end the game against it).
  $safety = 0;
  while (++$safety < 50 && $game['status'] === 'active') {
    $options = [];
    foreach (e24_affordable($game, $players) as $id) {
      if (e24_is_state($id) && !e24_bot_will_claim($game, $players, $seat, e24_state_key($id), e24_card_side($id))) continue;
      $options[] = $id;
    }
    $pick = e24_bot_choose($game, $players, $seat, $style, $options, true, e24_pools($game, $players));
    if ($pick === null) break;
    if (e24_is_state($pick)) e24_buy($game, $players, 'st#' . e24_state_key($pick), e24_card_side($pick), $mysqli);
    else e24_buy($game, $players, $pick, null, $mysqli);
  }
  if ($paper === 'herald' && !$game['state']['turn']['used']['herald'] && e24_top_story($game) !== null
      && !e24_bot_late($game) && max(0, e24_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) {
    e24_use_paper($game, $players, $mysqli);
  }
  e24_end_turn($game, $players, $mysqli);
}

/** Sides the outlet on turn can call the big state for right now. */
function e24_callable($game, $players) {
  $t = $game['state']['turn'];
  if ($t['called'] || $t['staked'] || (int) $game['state']['e'] >= count($game['state']['big'])) return [];
  $pools = e24_pools($game, $players);
  $key = $game['state']['big'][$game['state']['e']];
  $out = [];
  foreach (E24_SIDES as $side) {
    if (e24_have($pools, e24_side_order($players[$t['seat']], $side, true)) >= (int) e24_state($key)[$side . '_threshold']) $out[] = $side;
  }
  return $out;
}

/**
 * What the outlet on turn can buy right now: story keys, 'editorial', and
 * states as 'st#pa:trump' / 'st#pa:harris'.
 */
function e24_affordable($game, $players) {
  $t = $game['state']['turn'];
  if ($t['staked']) return [];
  $pools = e24_pools($game, $players);
  $player = $players[$t['seat']];
  $out = [];
  foreach ($game['state']['exchange'] as $id) {
    if (e24_is_state($id)) {
      $s = e24_state(e24_state_key($id));
      foreach (E24_SIDES as $side) {
        if (e24_have($pools, e24_side_order($player, $side, false)) >= (int) $s[$side . '_threshold']) $out[] = $id . ':' . $side;
      }
    } else {
      $c = e24_view($id);
      if (e24_have($pools, e24_story_order($c)) >= (int) $c['cost']) $out[] = $id;
    }
  }
  if ((int) $game['state']['editorials'] > 0 && max(0, $pools['gen']) >= (int) e24_card('editorial')['cost']) $out[] = 'editorial';
  return $out;
}

// ---------------------------------------------------------------------
// Ending and scoring
// ---------------------------------------------------------------------

function engine_end_game(&$game, &$players, $reason, $mysqli) {
  if ($game['status'] === 'ended') return;
  $game['status'] = 'ended';
  $game['phase'] = 'results';
  $game['current_seat'] = null;
  $game['ended_reason'] = $reason;
  foreach ($players as $seat => $p) {
    $r = engine_score_player($players, $seat, $game);
    $players[$seat]['final_score'] = (int) $r['total'];
    $players[$seat]['score'] = (int) $r['total'];
    $players[$seat]['score_breakdown'] = $r['breakdown'];
  }
  // Most prestige; a tie goes to the outlet that claimed more electoral votes.
  $best = null;
  foreach (engine_seat_list($players) as $seat) {
    if (!empty($players[$seat]['conceded'])) continue;
    if ($best === null) { $best = $seat; continue; }
    $a = [(int) $players[$seat]['final_score'], (int) $players[$seat]['public_state']['ev_claimed']];
    $b = [(int) $players[$best]['final_score'], (int) $players[$best]['public_state']['ev_claimed']];
    if ($a > $b) $best = $seat;
  }
  $game['winner_seat'] = $best;
  if ($mysqli) {
    $w = $game['state']['winner_side'] ?? null;
    engine_log($mysqli, $game, null, 'game_ended',
      engine_ended_text($reason) . ($w ? ' ' . e24_side_name($w) . ' wins the election.' : '')
      . ($best !== null ? ' ' . $players[$best]['player_name'] . ' called it best.' : ''),
      ['reason' => $reason, 'winner_seat' => $best, 'winner_side' => $w, 'ev' => e24_tally($game)]);
  }
}

function engine_ended_text($reason) {
  $text = [
    'race_called'     => 'A candidate reached 270; the stakes are revealed.',
    'all_humans_left' => 'The last editor walked away.',
    'stalled'         => 'No one would finish the race; the game is called with no winner.',
    'rules_changed'   => 'This game was started under other rules.',
  ];
  return $text[$reason] ?? 'The game ended.';
}

/** Final score: the prestige of the cards staked on the winner (+ the Argus's bonus). */
function engine_score_player($players, $seat, $game = null) {
  $p = $players[$seat];
  $w = $game ? ($game['state']['winner_side'] ?? null) : null;
  $won = 0;
  $lost = 0;
  $stakes = [];
  foreach ($p['private_state']['staked'] ?? [] as $st) {
    $c = e24_view($st['card']);
    $hit = ($w !== null && $st['side'] === $w);
    if ($hit) $won += (int) $c['vp'];
    else $lost += (int) $c['vp'];
    $stakes[] = ['card' => $c['name'], 'vp' => (int) $c['vp'], 'side' => $st['side'], 'won' => $hit];
  }
  $argus = (($p['public_state']['paper'] ?? null) === 'argus')
    ? (int) ($game['config']['argus_vp'] ?? 1) * (int) ($p['public_state']['called'] ?? 0) : 0;
  $total = $won + $argus;
  return ['total' => $total, 'breakdown' => [
    'prestige' => $total, 'stakes_won' => $won, 'stakes_lost' => $lost, 'argus' => $argus, 'stakes' => $stakes,
    'winner_side' => $w,
    'paper' => $p['public_state']['paper'] ?? null,
    'called' => (int) ($p['public_state']['called'] ?? 0),
    'states_bought' => (int) ($p['public_state']['states_bought'] ?? 0),
    'ev_claimed' => (int) ($p['public_state']['ev_claimed'] ?? 0),
    'bought' => (int) ($p['public_state']['bought'] ?? 0),
    'attacks' => (int) ($p['public_state']['attacks'] ?? 0),
    'scandals_taken' => (int) ($p['public_state']['scandals_taken'] ?? 0),
  ]];
}

// ---------------------------------------------------------------------
// Public projection -- the ONLY thing getState.php serialises
// ---------------------------------------------------------------------

/**
 * Every seat sees the same public payload; one private block for the
 * asking seat. Hands, deck order and staked cards are private (rivals see
 * how many cards each outlet has staked, never which or on whom) until the
 * game ends.
 */
function engine_public_state($game, $players, $viewerSeat = null) {
  $current = engine_is_current($game);
  $status = $game['status'];
  $endedReason = $game['ended_reason'];
  if ($status === 'active' && !$current) { $status = 'ended'; $endedReason = 'rules_changed'; }
  $state = $current ? $game['state'] : [];
  $turn = $state['turn'] ?? null;
  $ended = ($status === 'ended');

  $seats = [];
  foreach ($players as $seat => $p) {
    $paper = $p['public_state']['paper'] ?? null;
    $pp = $paper ? (e24_outlets()[$paper] ?? null) : null;
    $seats[] = [
      'seat' => (int) $seat, 'player_name' => $p['player_name'], 'is_bot' => (bool) $p['is_bot'],
      'conceded' => (bool) $p['conceded'], 'is_you' => ($viewerSeat !== null && (int) $seat === (int) $viewerSeat),
      'on_turn' => ($status === 'active' && $turn && (int) $turn['seat'] === (int) $seat),
      'paper' => $pp ? ['key' => $paper, 'name' => $pp['name'], 'ability' => $pp['ability'],
                        'ability_name' => $pp['ability_name'], 'flavor' => $pp['flavor']] : null,
      'stakes' => count($p['private_state']['staked'] ?? []),
      'called' => (int) ($p['public_state']['called'] ?? 0),
      'states_bought' => (int) ($p['public_state']['states_bought'] ?? 0),
      'ev_claimed' => (int) ($p['public_state']['ev_claimed'] ?? 0),
      'bought' => (int) ($p['public_state']['bought'] ?? 0),
      'attacks' => (int) ($p['public_state']['attacks'] ?? 0),
      'scandals_taken' => (int) ($p['public_state']['scandals_taken'] ?? 0),
      'hand_count' => (int) ($p['public_state']['hand_count'] ?? 0),
      'deck_count' => (int) ($p['public_state']['deck_count'] ?? 0),
      'discard_count' => (int) ($p['public_state']['discard_count'] ?? 0),
      'locations' => e24_views($p['public_state']['locations'] ?? []),
      'final_score' => $p['final_score'],
      'score_breakdown' => $ended ? $p['score_breakdown'] : null,
    ];
  }

  $big = null;
  if ($current && $status === 'active' && (int) ($state['e'] ?? 99) < count($state['big'] ?? [])) {
    $key = $state['big'][$state['e']];
    $s = e24_state($key);
    $big = [
      'index' => (int) $state['e'], 'key' => $key, 'state' => $s['state'], 'abbr' => $s['abbr'], 'ev' => (int) $s['ev'],
      'vp' => (int) $s['vp'], 'tier' => $s['tier'], 'margin' => (float) $s['margin'], 'winner_2024' => $s['winner'],
      'trump' => ['threshold' => (int) $s['trump_threshold'], 'card' => e24_view('st#' . $key . ':trump')],
      'harris' => ['threshold' => (int) $s['harris_threshold'], 'card' => e24_view('st#' . $key . ':harris')],
    ];
  }

  $map = [];
  foreach (e24_states() as $k => $s) {
    $cl = $state['claims'][$k] ?? null;
    $map[] = ['key' => $k, 'abbr' => $s['abbr'], 'state' => $s['state'], 'ev' => (int) $s['ev'], 'vp' => (int) $s['vp'],
              'deck' => $s['deck'], 'tier' => $s['tier'], 'margin' => (float) $s['margin'], 'winner_2024' => $s['winner'],
              'trump_cost' => (int) $s['trump_threshold'], 'harris_cost' => (int) $s['harris_threshold'],
              'side' => $cl['side'] ?? null, 'seat' => $cl['seat'] ?? null, 'how' => $cl['how'] ?? null];
  }

  $turnPublic = null;
  if ($current && $turn && $status === 'active') {
    $turnPublic = [
      'seat' => (int) $turn['seat'], 'played' => e24_views($turn['played']),
      'pools' => e24_pools($game, $players), 'called' => $turn['called'],
      'bought' => e24_views($turn['bought']), 'pending' => $turn['pending'] ? $turn['pending']['type'] : null,
    ];
  }

  $you = null;
  if ($viewerSeat !== null && isset($players[$viewerSeat]) && $current) {
    $me = $players[$viewerSeat];
    $deck = e24_views($me['private_state']['deck'] ?? []);
    usort($deck, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    $pending = null;
    if ($turn && (int) $turn['seat'] === (int) $viewerSeat && $turn['pending']) {
      $pending = ['type' => $turn['pending']['type'], 'card' => e24_view($turn['pending']['card']),
                  'options' => e24_views($turn['pending']['options']), 'left' => (int) ($turn['pending']['left'] ?? 1)];
    }
    $staked = [];
    foreach ($me['private_state']['staked'] ?? [] as $st) $staked[] = ['card' => e24_view($st['card']), 'side' => $st['side']];
    $you = [
      'seat' => (int) $viewerSeat, 'hand' => e24_views($me['private_state']['hand'] ?? []),
      'deck' => $deck, 'discard' => e24_views($me['private_state']['discard'] ?? []),
      'held' => e24_views($me['private_state']['held'] ?? []), 'pending' => $pending, 'staked' => $staked,
    ];
  }

  $ev = $current ? e24_tally($game) : ['trump' => 0, 'harris' => 0];
  return [
    'engine' => '2024',
    'game_id' => (int) $game['game_id'], 'join_code' => $game['join_code'] ?? null, 'status' => $status,
    'variant' => $game['variant'] ?? null, 'phase' => $game['phase'], 'round' => (int) $game['round_number'],
    'current_seat' => $game['current_seat'], 'max_players' => (int) ($game['max_players'] ?? 0),
    'winner_seat' => $game['winner_seat'], 'ended_reason' => $endedReason,
    'ended_text' => $endedReason ? engine_ended_text($endedReason) : null,
    'state_version' => (int) ($game['state_version'] ?? 0),
    'rules' => [
      'hand' => (int) ($game['config']['hand'] ?? 5), 'exchange_size' => (int) ($game['config']['exchange_size'] ?? 5),
      'win_at' => (int) ($game['config']['win_at'] ?? 270), 'bot_level' => (string) ($game['config']['bot_level'] ?? 'easy'),
    ],
    'race' => ['trump' => $ev['trump'], 'harris' => $ev['harris'], 'win_at' => (int) ($game['config']['win_at'] ?? 270),
               'final' => (bool) ($state['final'] ?? false), 'winner_side' => $state['winner_side'] ?? null,
               'trigger_seat' => $state['trigger_seat'] ?? null],
    'big' => $big,
    'big_total' => count($state['big'] ?? []),
    'big_called' => $state['history'] ?? [],
    'step' => (int) ($state['step'] ?? 0),
    'map' => $map,
    'exchange' => e24_views($state['exchange'] ?? []),
    'editorial' => $current ? array_merge(e24_view('editorial#x'), ['left' => (int) $state['editorials']]) : null,
    'main_count' => count($state['main'] ?? []),
    'scandals_left' => (int) ($state['scandals'] ?? 0),
    'news' => e24_views($state['last_released'] ?? []),
    'turn' => $turnPublic,
    'last_turn' => $state['last_turn'] ?? null,
    'players' => $seats,
    'you' => $you,
    'available_actions' => engine_available_actions($game, $players, $viewerSeat),
  ];
}

function engine_available_actions($game, $players, $seat) {
  if ($seat === null || !isset($players[$seat])) return [];
  if ($game['status'] !== 'active' || !engine_is_current($game)) return [];
  if (!empty($players[$seat]['conceded'])) return [];
  $t = $game['state']['turn'];
  if ((int) $t['seat'] !== (int) $seat) return ['concede' => true];
  if ($t['pending']) return ['choose' => $t['pending']['options'], 'concede' => true];
  $fresh = !$t['played'] && !$t['called'] && !$t['bought'] && !$t['used']['sun'] && !$t['used']['herald'] && !$t['staked'];
  $paper = $players[$seat]['public_state']['paper'] ?? null;
  $paperOk = false;
  if ($paper === 'sun' && !$t['used']['sun']) {
    foreach ($players[$seat]['private_state']['hand'] as $id) if (strpos($id, 'scandal#') === 0) $paperOk = true;
  }
  if ($paper === 'herald' && !$t['used']['herald'] && e24_top_story($game) !== null
      && max(0, e24_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) $paperOk = true;
  return [
    'play' => array_values($players[$seat]['private_state']['hand']),
    'play_all' => !empty($players[$seat]['private_state']['hand']),
    'call' => e24_callable($game, $players),
    'buy' => e24_affordable($game, $players),
    'stake' => $fresh ? array_values($players[$seat]['private_state']['hand']) : [],
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

function e24_big_text($game) {
  $key = $game['state']['big'][$game['state']['e']];
  $s = e24_state($key);
  return 'Up now: ' . $s['state'] . ' (' . $s['ev'] . ' electoral votes) -- Trump ' . $s['trump_threshold']
       . ', Harris ' . $s['harris_threshold'] . '.';
}

function e24_turn_text($game, $players, $t, $made) {
  $name = $players[$t['seat']]['player_name'];
  $parts = [count($t['played']) . ' ' . (count($t['played']) === 1 ? 'card' : 'cards') . ' played (' . $made . ' currency)'];
  if ($t['called']) {
    $h = end($game['state']['history']);
    $parts[] = 'called ' . $h['state'] . ' for ' . e24_side_name($h['side']);
  }
  if ($t['bought']) {
    $names = [];
    foreach ($t['bought'] as $id) $names[] = e24_view($id)['name'];
    $parts[] = 'bought ' . implode(', ', $names);
  }
  $msg = $name . ': ' . implode('; ', $parts) . '.';
  return mb_strlen($msg) > 480 ? mb_substr($msg, 0, 477) . '...' : $msg;
}

/**
 * The verbatim playthrough export. While a game runs, a player gets only
 * their own private state (stakes included), and never any deck order.
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
    $board['big'] = array_slice($board['big'] ?? [], 0, (int) ($board['e'] ?? 0) + 1);   // the order still to come is hidden
    if (isset($board['turn']) && (int) $board['turn']['seat'] !== (int) $viewerSeat) $board['turn']['pending'] = null;
  }
  return [
    'export_version' => 6,
    'exported_at' => gmdate('c'),
    'summary' => [
      'game_id' => (int) $game['game_id'], 'join_code' => $game['join_code'] ?? null, 'variant' => $game['variant'] ?? null,
      'status' => $game['status'], 'phase' => $game['phase'], 'rounds' => (int) $game['round_number'],
      'tally' => is_array($game['state']) && engine_is_current($game) ? e24_tally($game) : null,
      'winner_side' => $game['state']['winner_side'] ?? null,
      'winner_seat' => $game['winner_seat'], 'ended_reason' => $game['ended_reason'],
      'created_at' => $game['created_at'] ?? null, 'ended_at' => $game['ended_at'] ?? null, 'config' => $game['config'],
    ],
    'big_called' => $game['state']['history'] ?? [],
    'final_board' => $board,
    'players' => $seats,
    'events' => ($mysqli && function_exists('all_events')) ? all_events($mysqli, (int) $game['game_id']) : [],
  ];
}

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
