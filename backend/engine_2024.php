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
 * spends on anything; REPUBLICAN only on Trump (buying a state for him, or
 * a Republican story); DEMOCRATIC only on Harris (or a Democratic story);
 * CAMPAIGN only on states.
 *
 * CURRENCY COUNTS FROM THE HAND (the user, 2026-10-03): every card's
 * currency (neutral, party, Campaign, per 3 states) counts as soon as it is
 * in the hand on the outlet's turn -- at the start, and when drawn -- once
 * a turn, even if the card later leaves the hand. Playing a card only USES
 * ITS ABILITY; a card with none is never played.
 *
 * TWO FRAMINGS (the user, 2026-10-04/05): every story is used one way or
 * the other. Its TOP is the positive framing: the card's ability (draw,
 * destroy, gain, Retraction, chain, compounding, or +1 of its party). Its
 * BOTTOM is the oppositional framing: +1 or +2 of the OTHER party's
 * currency, and on twenty stories a plank knockout.
 * PARTY PLANKS (20, ten from each 2024 platform) are played into play and
 * stay there, paying their owner each turn while every other outlet gets
 * +1 of the plank's party, until a rival KNOCKS one out (a story's bottom,
 * or a swing state played for the other side): it goes to its owner's
 * discard pile.
 *
 * On its turn an outlet either STAKES -- sets one card from its hand aside,
 * face down, on Trump or Harris, discards the rest and draws 5 -- or plays:
 *   - USES the abilities of cards in its hand, one at a time;
 *   - BUYS a STATE for a side at that side's threshold: three state decks
 *     (large, medium, small), one state face up on each. Buying it turns up
 *     the next, and that state's REVEAL (a real article from its own press)
 *     hits every outlet: a Scandal, a discard, or a fresh exchange;
 *   - BUYS off the exchange (5 face up from the main deck: the stories and
 *     planks) or Editorials. The main deck is two sets stacked, no calendar:
 *     the Biden set on top, the SWITCH card (set aside when it comes up),
 *     then the Harris set;
 *     A state may be bought WITH ITS HOUSE DELEGATION, for the same side, at
 *     1 per 4 seats (at least 1); never later. If every state is claimed and
 *     neither side has 270 (269-269), the side with more House seats wins;
 *     with no House majority the election is deadlocked and no stake scores;
 *   - ENDS the turn: everything played and left in hand is discarded (planks
 *     stay in play), it draws 5, and the oldest card on the exchange goes to
 *     the bottom of the main deck (the news cycle).
 * Flipping a state costs 1 more per 6 points of its 2024 margin. A state
 * counts for its side once claimed. When a side reaches 270 the round is
 * played out and the game ends. The score is the wealth (-1 to 12) of the
 * cards an outlet staked on the winner; nothing else scores.
 * Retraction: draw a card and destroy a Scandal.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/cards_2024.php';     // cards, states and the eight outlets (e24_outlets)

define('ENGINE_STATE_VERSION', 25);
define('E24_SIDES', ['trump', 'harris']);
define('E24_KINDS', ['Political', 'Economic', 'Social']);
define('E24_DECKS', ['large', 'medium', 'small']);
define('E24_ONGOING', ['Media event', 'Plank']);     // cards that stay in play

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

function e24_other_party($party) {
  return $party === 'rep' ? 'dem' : 'rep';
}

function e24_party_name($party) {
  return $party === 'rep' ? 'Republican' : 'Democratic';
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
        'historical' => $s['winner'] === $sd, 'knock' => (int) $s[$sd . '_strike'] ? e24_party(e24_other($sd)) : null,
      ];
    }
    $v = [
      'key' => $id, 'kind' => 'state', 'type' => 'State', 'state' => $s['key'], 'abbr' => $s['abbr'],
      // A claimed half is named for that side's 2024 base in the state ("Central Pennsylvania").
      'name' => $side ? $s[$side . '_name'] : $s['state'],
      'half_names' => ['trump' => $s['trump_name'], 'harris' => $s['harris_name']],
      'state_name' => $s['state'], 'side' => $side, 'lean' => $side ? e24_party($side) : null, 'story_kind' => null,
      'ev' => (int) $s['ev'], 'vp' => (int) $s['vp'], 'tier' => $s['tier'], 'margin' => (float) $s['margin'],
      'winner_2024' => $s['winner'], 'deck' => $s['deck'], 'big' => $s['deck'] === 'large', 'sides' => $sides,
      'house_seats' => (int) $s['house_seats'], 'house_cost' => (int) $s['house_cost'],
      'knock' => $side ? $sides[$side]['knock'] : null,
      'reveal' => ['kind' => $s['reveal_kind'], 'n' => (int) ($s['reveal_n'] ?? 1), 'title' => $s['reveal_title'],
                   'outlet' => $s['reveal_outlet'], 'url' => $s['reveal_url'], 'date' => $s['reveal_date'],
                   'text' => $s['reveal_text']],
      'top_party' => 0, 'bottom_party' => 0, 'bottom_attack' => null, 'strike' => null, 'era' => null,
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
  $c['knock'] = null;
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

  // Three state decks, one state face up on each (the end of each list is its top).
  $decks = ['large' => [], 'medium' => [], 'small' => []];
  foreach (e24_states() as $k => $s) $decks[$s['deck']][] = $k;
  $up = [];
  foreach (E24_DECKS as $d) {
    shuffle($decks[$d]);
    $up[$d] = array_pop($decks[$d]);
  }
  // The main deck: the Biden set on top (the end), the switch, the Harris set.
  $sets = ['biden' => [], 'harris' => []];
  $switch = null;
  $cards = e24_cards();
  foreach ($cards as $k => $c) {
    if ($c['era'] === 'switch') $switch = $k;
    elseif (isset($sets[$c['era']])) $sets[$c['era']][] = $k;
  }
  shuffle($sets['biden']);
  shuffle($sets['harris']);
  $main = array_merge($sets['harris'], $switch ? [$switch] : [], $sets['biden']);
  $game['state'] = [
    'engine_version' => ENGINE_STATE_VERSION,
    'decks'      => $decks,                  // the state decks still face down
    'up'         => $up,                     // the state face up on each deck (null when it is empty)
    'switched'   => null,                    // the round the switch card came up: the Harris set has begun
    'last_reveal' => null,                   // the last state turned up: [key, deck, round]
    'main'       => $main,
    'exchange'   => [],
    'editorials' => (int) $cards['editorial']['copies'],
    'scandals'   => (int) $cards['scandal']['copies'],
    'trash'      => [],
    'order'      => engine_seat_list($players),
    'turns'      => 0,
    'claims'     => [],                      // state key => [side, seat, how, round]
    'reveals'    => [],                      // states turned up, in order
    'final'      => false,                   // a side reached 270: the round plays out
    'winner_side' => null,
    'trigger_seat' => null,
    'last_turn'  => null,
    'turn'       => null,
  ];
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
  if ($mysqli) engine_log($mysqli, $game, null, 'campaign_begins', e24_decks_text($game));
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

function e24_is_switch($id) {
  $c = e24_card(explode('#', (string) $id)[0]);
  return $c && $c['era'] === 'switch';
}

/** Deal the exchange back up to size; the switch card is set aside when it comes up. */
function e24_refill(&$game) {
  $size = (int) ($game['config']['exchange_size'] ?? 5);
  while (count($game['state']['exchange']) < $size && !empty($game['state']['main'])) {
    $c = array_pop($game['state']['main']);
    if (e24_is_switch($c)) {
      $game['state']['switched'] = (int) ($game['round_number'] ?? 1);
      continue;
    }
    $game['state']['exchange'][] = $c;
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

/** House seats bought for each side (the 269-269 tiebreaker). */
function e24_house_tally($game) {
  $seats = ['trump' => 0, 'harris' => 0];
  foreach ($game['state']['claims'] ?? [] as $k => $c) {
    if (!empty($c['house'])) $seats[$c['side']] += (int) e24_state($k)['house_seats'];
  }
  return $seats;
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
    'frames' => [],      // card => 'top' / 'bottom': how each story was used
    'used' => ['sun' => false, 'herald' => false], 'star_done' => false, 'pending' => null, 'queue' => [],
    'counted' => [],     // cards whose currency counts this turn (they have been in the hand)
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
  e24_count_hand($game, $players);
}

/** A card's currency while it is in the hand: [pool => amount]. */
function e24_hand_currency($game, $player, $c) {
  $out = ['gen' => (int) $c['gen'], 'campaign' => (int) $c['campaign']];
  if ($c['lean']) {
    $out[$c['lean']] = (int) $c['themed'];
    if ((int) $c['per_office']) {
      $out[$c['lean']] += (int) $c['per_office'] * intdiv(e24_states_held($player), (int) $game['config']['office_div']);
    }
  }
  return $out;
}

/** Count the currency of every card in the hand of the outlet on turn that has not been counted yet. */
function e24_count_hand(&$game, &$players) {
  if (empty($game['state']['turn']) || $game['state']['turn']['staked']) return;
  $t = &$game['state']['turn'];
  $seat = $t['seat'];
  if (!isset($t['counted'])) $t['counted'] = [];
  foreach ($players[$seat]['private_state']['hand'] as $id) {
    if (in_array($id, $t['counted'], true)) continue;
    $t['counted'][] = $id;
    foreach (e24_hand_currency($game, $players[$seat], e24_view($id)) as $k => $v) $t['base'][$k] += $v;
  }
  unset($t);
}

/** Does playing this card do anything? (Currency alone counts from the hand.) */
function e24_has_ability($c) {
  return (int) $c['draw'] || (int) $c['trash'] || (int) $c['gain_upto'] || (int) $c['retract']
      || (int) $c['chain'] || (int) $c['per_same'] || (int) ($c['top_party'] ?? 0) || (int) ($c['bottom_party'] ?? 0)
      || in_array($c['type'], E24_ONGOING, true) || !empty($c['knock']);
}

/** The framings a card can be used in: a story with an oppositional side has both. */
function e24_framings($c) {
  return (int) ($c['bottom_party'] ?? 0) > 0 ? ['top', 'bottom'] : ['top'];
}

/** Currency available right now: what was made (with every bonus) less what was spent. */
function e24_pools($game, $players) {
  $t = $game['state']['turn'];
  $p = $players[$t['seat']];
  $total = $t['base'];
  $bottoms = [];
  foreach ($t['played'] as $id) {
    $c = e24_view($id);
    $k = $c['story_kind'];
    $n = $k ? (int) ($t['counts'][$k] ?? 0) : 0;
    $top = ($t['frames'][$id] ?? 'top') === 'top';
    if ((int) $c['per_same'] && $top) $total['gen'] += (int) $c['per_same'] * max(0, $n - 1);
    if ((int) $c['chain'] && $n >= 2 && $top) $total['gen'] += (int) $c['chain'];
    if (!$top) $bottoms[$c['kind']] = true;
  }
  // CNN's Debate Stage: +1 neutral for each different story used on its bottom side.
  if (($p['public_state']['paper'] ?? null) === 'aurora') $total['gen'] += count($bottoms);
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

function e24_play(&$game, &$players, $cardId, $mysqli, $framing = 'top') {
  $t = &$game['state']['turn'];
  if ($t['staked']) throw new Exception('You staked this turn.');
  $seat = $t['seat'];
  $p = &$players[$seat];
  $at = array_search($cardId, $p['private_state']['hand'], true);
  if ($at === false) throw new Exception('That card is not in your hand.');
  $c = e24_view($cardId);
  if (!e24_has_ability($c)) throw new Exception($c['name'] . ' has no ability to use: its currency already counts from your hand.');
  $framing = $framing ?: 'top';
  if (!in_array($framing, e24_framings($c), true)) throw new Exception($c['name'] . ' has no ' . $framing . ' side to use.');
  unset($p, $t);
  e24_count_hand($game, $players);            // its currency counts whether or not it is played
  $t = &$game['state']['turn'];
  $p = &$players[$seat];
  array_splice($p['private_state']['hand'], $at, 1);
  $t['played'][] = $cardId;
  $t['frames'][$cardId] = $framing;
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
  if ($framing === 'bottom') {
    // The oppositional framing: the other party's currency, and maybe a plank knockout.
    $t['base'][e24_other_party($c['lean'])] += (int) $c['bottom_party'];
    if ($c['bottom_attack'] === 'plank') {
      e24_push_prompt($t, ['type' => 'knock', 'card' => $cardId, 'party' => $c['strike'], 'left' => 1]);
      $t['attacks'] += 1;
      $p['public_state']['attacks'] = 1 + (int) $p['public_state']['attacks'];
    }
  } else {
    if ((int) ($c['top_party'] ?? 0) && $c['lean']) $t['base'][$c['lean']] += (int) $c['top_party'];
    if (!empty($c['knock'])) e24_push_prompt($t, ['type' => 'knock', 'card' => $cardId, 'party' => $c['knock'], 'left' => 1]);
    if ((int) $c['draw']) e24_draw($game, $p, (int) $c['draw']);
    for ($i = 0; $i < (int) $c['retract']; $i++) e24_retract($game, $p);
    if ((int) $c['trash']) e24_push_prompt($t, ['type' => 'trash', 'card' => $cardId, 'left' => (int) $c['trash']]);
    if ((int) $c['gain_upto']) e24_push_prompt($t, ['type' => 'gain', 'card' => $cardId, 'max_cost' => (int) $c['gain_upto'], 'left' => 1]);
  }
  unset($p, $t);
  if (!$game['state']['turn']['pending']) e24_next_prompt($game, $players);
  e24_count_hand($game, $players);            // cards drawn count at once
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

/** One hit of a reveal on one outlet: a discard at random, its dearest card, or a Scandal. */
function e24_hit(&$game, &$q, $kind) {
  if ($kind === 'discard_dearest') {
    if (!$q['private_state']['hand']) return false;
    $best = 0;
    foreach ($q['private_state']['hand'] as $i => $id) {
      if ((int) e24_view($id)['cost'] > (int) e24_view($q['private_state']['hand'][$best])['cost']) $best = $i;
    }
    $id = $q['private_state']['hand'][$best];
    array_splice($q['private_state']['hand'], $best, 1);
    $q['private_state']['discard'][] = $id;
    e24_count($q);
    return true;
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

/**
 * A state turns face up: its reveal (a real article from its own press)
 * hits every outlet at the table. 'sweep' sends the exchange to the bottom of
 * the main deck and deals it fresh.
 */
function e24_reveal(&$game, &$players, $key, $deck, $mysqli) {
  $s = e24_state($key);
  $kind = $s['reveal_kind'];
  $n = max(1, (int) ($s['reveal_n'] ?? 1));
  $game['state']['last_reveal'] = ['key' => $key, 'deck' => $deck, 'round' => (int) $game['round_number']];
  $game['state']['reveals'][] = $key;
  if ($kind === 'sweep') {
    $game['state']['main'] = array_merge($game['state']['exchange'], $game['state']['main']);
    $game['state']['exchange'] = [];
    e24_refill($game);
  } else {
    foreach ($game['state']['order'] as $seat) {
      if (!empty($players[$seat]['conceded'])) continue;
      for ($i = 0; $i < $n; $i++) e24_hit($game, $players[$seat], $kind);
    }
  }
  if ($mysqli) {
    engine_log($mysqli, $game, null, 'reveal',
      $s['state'] . ' turns up' . ($s['reveal_title'] ? ': "' . $s['reveal_title'] . '" (' . $s['reveal_outlet'] . ').' : '.')
      . ' ' . e24_reveal_effect($kind, $n),
      ['state' => $key, 'deck' => $deck, 'kind' => $kind, 'n' => $n]);
  }
}

function e24_reveal_effect($kind, $n) {
  if ($kind === 'scandal') return $n > 1 ? "Every outlet takes $n Scandals." : 'Every outlet takes a Scandal.';
  if ($kind === 'discard') return $n > 1 ? "Every outlet discards $n cards at random." : 'Every outlet discards a card at random.';
  if ($kind === 'discard_dearest') return 'Every outlet discards its dearest card.';
  if ($kind === 'sweep') return 'The stories on the exchange are swept away and dealt fresh.';
  return '';
}

/** The planks of a party that seat's rivals have in play, as plank card ids. */
function e24_rival_planks($players, $seat, $party) {
  $out = [];
  foreach ($players as $s => $q) {
    if ((int) $s === (int) $seat) continue;
    foreach ($q['public_state']['locations'] ?? [] as $loc) {
      $c = e24_view($loc);
      if ($c['type'] === 'Plank' && $c['lean'] === $party) $out[] = $loc;
    }
  }
  return $out;
}

/** Knock a plank out of play: to its owner's discard pile. */
function e24_knock(&$game, &$players, $seat, $loc, $mysqli) {
  foreach ($players as $s => $q) {
    $i = array_search($loc, $q['public_state']['locations'] ?? [], true);
    if ($i === false) continue;
    array_splice($players[$s]['public_state']['locations'], $i, 1);
    $players[$s]['private_state']['discard'][] = $loc;
    e24_count($players[$s]);
    $players[$seat]['public_state']['knocks'] = 1 + (int) ($players[$seat]['public_state']['knocks'] ?? 0);
    if ($mysqli) {
      engine_log($mysqli, $game, $seat, 'knock',
        $players[$seat]['player_name'] . ' knocks ' . e24_view($loc)['name'] . ' out of ' . $players[$s]['player_name']
        . "'s play.", ['plank' => $loc, 'owner' => (int) $s], $players[$seat]['player_name']);
    }
    return true;
  }
  return false;
}

function e24_push_prompt(&$t, $prompt) {
  if (!isset($t['queue'])) $t['queue'] = [];
  $t['queue'][] = $prompt;
}

/** What a prompt can choose from. A claimed state is never destroyed: it stays claimed. */
function e24_prompt_options($game, $players, $seat, $pend) {
  $player = $players[$seat];
  if ($pend['type'] === 'knock') return e24_rival_planks($players, $seat, $pend['party']);
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
    $next['options'] = e24_prompt_options($game, $players, $t['seat'], $next);
    if ($next['options']) { $t['pending'] = $next; return; }
  }
}

function e24_choose(&$game, &$players, $pick, $mysqli = null) {
  $pend = $game['state']['turn']['pending'];
  if (!$pend) throw new Exception('Nothing to choose.');
  $seat = $game['state']['turn']['seat'];
  $p = &$players[$seat];
  $done = true;
  if ($pick !== null && $pick !== '') {
    if (!in_array($pick, $pend['options'], true)) throw new Exception('That is not one of the choices.');
    if ($pend['type'] === 'knock') {
      unset($p);
      e24_knock($game, $players, $seat, $pick, $mysqli);
      $p = &$players[$seat];
    } elseif ($pend['type'] === 'trash') {
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
      $pend['options'] = e24_prompt_options($game, $players, $seat, $pend);
      if ($pend['options']) { $game['state']['turn']['pending'] = $pend; $done = false; }
    }
  }
  if ($done) e24_next_prompt($game, $players);
  e24_count($p);
  unset($p);
  e24_count_hand($game, $players);
}

/** Buy a story or plank off the exchange, a face-up state (for a side), or an Editorial. */
function e24_buy(&$game, &$players, $cardId, $side, $mysqli, $house = false) {
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
    if (e24_is_state($cardId)) {
      if (!in_array($side, E24_SIDES, true)) throw new Exception('Buy a state for a side: trump or harris.');
      $key = e24_state_key($cardId);
      $s = e24_state($key);
      $deck = $s ? $s['deck'] : null;
      if (!$s || ($game['state']['up'][$deck] ?? null) !== $key) throw new Exception('That state is not face up.');
      $need = (int) $s[$side . '_threshold'];
      $house = $house && (int) $s['house_cost'] > 0;
      $total = $need + ($house ? (int) $s['house_cost'] : 0);
      if (!e24_pay($game, e24_pools($game, $players), $total, e24_side_order($players[$seat], $side, true))) {
        throw new Exception($s['state'] . ' for ' . e24_side_name($side) . ' costs ' . $need
          . ($house ? ' + ' . $s['house_cost'] . ' for its House delegation' : '') . ' ('
          . ($side === 'trump' ? 'Republican' : 'Democratic') . ', Campaign or neutral).');
      }
      $id = 'st#' . $key . ':' . $side;
      $players[$seat]['public_state']['states_bought'] = 1 + (int) $players[$seat]['public_state']['states_bought'];
      if ($deck === 'large') $players[$seat]['public_state']['called'] = 1 + (int) $players[$seat]['public_state']['called'];
      if ($mysqli) {
        engine_log($mysqli, $game, $seat, 'claim',
          $players[$seat]['player_name'] . ' buys ' . $s['state'] . ' (' . $s['ev'] . ') for ' . e24_side_name($side)
          . ($side === $s['winner'] ? '.' : ', and history is rewritten.'),
          ['state' => $key, 'side' => $side, 'ev' => (int) $s['ev']], $players[$seat]['player_name']);
      }
      $players[$seat]['private_state']['discard'][] = $id;
      e24_claim($game, $players, $seat, $key, $side, 'buy', $mysqli);
      if ($house) {
        $game['state']['claims'][$key]['house'] = true;
        $players[$seat]['public_state']['house_seats'] = (int) $s['house_seats'] + (int) ($players[$seat]['public_state']['house_seats'] ?? 0);
        if ($mysqli) {
          engine_log($mysqli, $game, $seat, 'house', $players[$seat]['player_name'] . ' takes ' . $s['state'] . "'s House delegation ("
            . $s['house_seats'] . ' seats) for ' . e24_side_name($side) . '.', ['state' => $key, 'side' => $side, 'seats' => (int) $s['house_seats']],
            $players[$seat]['player_name']);
        }
      }
      // The next state on that deck turns up, and its reveal hits everyone.
      $next = $game['state']['decks'][$deck] ? array_pop($game['state']['decks'][$deck]) : null;
      $game['state']['up'][$deck] = $next;
      if ($next) e24_reveal($game, $players, $next, $deck, $mysqli);
    } else {
      $at = array_search($cardId, $game['state']['exchange'], true);
      if ($at === false) throw new Exception('That card is not on the exchange.');
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
  if ($t['played'] || $t['bought'] || $t['used']['sun'] || $t['used']['herald'] || $t['staked']) {
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
        e24_count_hand($game, $players);
        return;
      }
    }
    unset($p);
    throw new Exception('The Sun needs a Scandal in your hand.');
  }
  if ($paper === 'herald') {
    if ($t['used']['herald']) throw new Exception('The Herald has already scooped this turn.');
    $at = e24_top_story($game);
    if ($at === null) throw new Exception('There is no card left in the main deck.');
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

/** Politico's scoop: the topmost card of the main deck (the switch card is passed over). */
function e24_top_story($game) {
  for ($i = count($game['state']['main']) - 1; $i >= 0; $i--) {
    if (!e24_is_switch($game['state']['main'][$i])) return $i;
  }
  return null;
}

/** End the turn: discard, planks into play, draw, the news cycle, the next outlet. */
function e24_end_turn(&$game, &$players, $mysqli) {
  $game['state']['turn']['pending'] = null;
  $game['state']['turn']['queue'] = [];
  $t = $game['state']['turn'];
  $seat = $t['seat'];
  $p = &$players[$seat];
  foreach ($t['played'] as $id) {
    if (in_array(e24_view($id)['type'], E24_ONGOING, true) && ($t['frames'][$id] ?? 'top') === 'top') {
      $p['public_state']['locations'][] = $id;
    }
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
    'bought' => $t['bought'], 'attacks' => $t['attacks'], 'frames' => $t['frames'] ?? [],
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
  // Every state is claimed and neither side has 270 (269-269): the House decides, by the delegations
  // bought; with no House majority the election is deadlocked.
  if (!$game['state']['final'] && !array_filter($game['state']['up'])) {
    $h = e24_house_tally($game);
    if ($h['trump'] !== $h['harris']) {
      $game['state']['winner_side'] = $h['trump'] > $h['harris'] ? 'trump' : 'harris';
      engine_end_game($game, $players, 'house', $mysqli);
    } else {
      engine_end_game($game, $players, 'deadlock', $mysqli);
    }
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
 *   play      {card, framing?}  use one card from your hand: framing 'top' (default) or 'bottom'
 *   play_all  {}              use every card in your hand on its top (stops at a prompt)
 *   choose    {card|null}     answer the pending prompt (destroy / gain / knock out); null declines
 *   buy       {card, side?, house?}  a story or plank on the exchange, a face-up state ('st#pa', with a side;
 *                             house: true also buys its House delegation), or 'editorial'
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
    $pt = $game['state']['turn']['pending']['type'];
    throw new Exception('Finish your choice first: ' . ($pt === 'trash' ? 'destroy a card, or decline.'
      : ($pt === 'knock' ? 'knock out a plank, or decline.' : 'gain a story, or decline.')));
  }
  $name = $players[$seat]['player_name'];
  switch ($action) {
    case 'play':
      e24_play($game, $players, (string) ($params['card'] ?? ''), $mysqli, (string) ($params['framing'] ?? 'top'));
      $msg = $name . ' played ' . e24_view((string) $params['card'])['name'] . '.';
      break;
    case 'play_all':
      $n = 0;
      if ($game['state']['turn']['staked']) throw new Exception('You staked this turn.');
      while (!$game['state']['turn']['pending'] && ($next = e24_first_usable($players[$seat])) !== null) {
        e24_play($game, $players, $next, $mysqli);
        $n++;
      }
      $msg = $name . ' used ' . $n . ' ' . ($n === 1 ? 'ability' : 'abilities') . '.';
      break;
    case 'choose':
      $pick = $params['card'] ?? null;
      e24_choose($game, $players, $pick === null ? null : (string) $pick, $mysqli);
      $msg = 'Done.';
      break;
    case 'buy':
      e24_buy($game, $players, (string) ($params['card'] ?? ''), (string) ($params['side'] ?? ''), $mysqli, !empty($params['house']));
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
  if (in_array($c['type'], E24_ONGOING, true)) {
    return $vp + 3 * ($c['ongoing_gen'] + 1.3 * $c['ongoing_draw']) - 0.5 * $c['others_bonus'];
  }
  $v = $vp + $c['gen'] + 0.8 * $c['themed'] + 0.6 * $c['campaign'] + 1.3 * $c['draw']
     + 0.8 * $c['trash'] + 0.4 * $c['gain_upto'] + 0.6 * $c['chain'] + 0.8 * $c['per_same']
     + 1.0 * $c['per_office'] + 0.3 * $c['defense'] + 0.8 * $c['retract'];
  if ($c['attack'] === 'scandal') $v += 1.5;
  elseif ($c['attack'] === 'discard') $v += 1.0;
  $v += 0.8 * (int) ($c['top_party'] ?? 0);
  $v += 0.3 * (int) ($c['bottom_party'] ?? 0);      // the oppositional framing is an option on top of the ability
  if (($c['bottom_attack'] ?? null) === 'plank') $v += 0.6;
  return $v;
}

/** Bot.frame: the top (the card's own ability) or the bottom (the other party's currency, worth less to an
 *  outlet staked on this card's own party; and maybe a plank knockout). */
function e24_bot_frame($game, $players, $seat, $style, $c) {
  if (!in_array('bottom', e24_framings($c), true)) return 'top';
  if (in_array($c['type'], E24_ONGOING, true)) {
    $top = 3 * ($c['ongoing_gen'] + 1.3 * $c['ongoing_draw']);
  } else {
    $top = 1.3 * $c['draw'] + 0.8 * $c['trash'] + 0.4 * $c['gain_upto'] + 0.6 * $c['chain']
         + 0.8 * $c['per_same'] + 0.8 * $c['retract'] + 0.8 * (int) $c['top_party'];
  }
  $side = e24_bot_leaning($style, $players[$seat]);
  $bottom = 0.8 * (int) $c['bottom_party'] * (($side !== null && $side === ($c['lean'] === 'rep' ? 'trump' : 'harris')) ? 0.5 : 1.0);
  if ($c['bottom_attack'] === 'plank') {
    $best = null;
    foreach (e24_rival_planks($players, $seat, $c['strike']) as $loc) {
      $v = e24_bot_value(e24_view($loc));
      if ($best === null || $v > $best) $best = $v;
    }
    if ($best !== null) $bottom += $style['attack'] * 0.5 * $best;
  }
  return $top >= $bottom ? 'top' : 'bottom';
}

/** The most valuable plank among the options (the simulator's knock). */
function e24_bot_knock_pick($options) {
  $best = null;
  $bestV = null;
  foreach ($options as $loc) {
    $v = e24_bot_value(e24_view($loc));
    if ($bestV === null || $v > $bestV) { $best = $loc; $bestV = $v; }
  }
  return $best;
}

function e24_bot_late($game) {
  $ev = e24_tally($game);
  return max($ev) >= (int) $game['config']['win_at'] - 70;
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
      $v -= $game['config']['power_choice'] * 0.3 * e24_bot_neutral_needed($players[$seat], $pools, $c['side'], (int) $c['cost'], true);
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
      if ($pend['type'] === 'trash') $pick = e24_bot_trash_pick($players[$seat], $pend['options']);
      elseif ($pend['type'] === 'knock') $pick = e24_bot_knock_pick($pend['options']);
      else $pick = e24_bot_choose($game, $players, $seat, $style, $pend['options'], false);
      e24_choose($game, $players, $pick, $mysqli);
      continue;
    }
    $next = e24_first_usable($players[$seat]);
    if ($next === null) break;
    e24_play($game, $players, $next, $mysqli, e24_bot_frame($game, $players, $seat, $style, e24_view($next)));
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
    if (e24_is_state($pick)) {
      $key = e24_state_key($pick);
      $side = e24_card_side($pick);
      $s = e24_state($key);
      // The House delegation: late in the race, for the side this outlet backs (or any, before it has bet).
      $house = (int) $s['house_cost'] > 0 && e24_bot_late($game)
        && in_array(e24_bot_leaning($style, $players[$seat]), [null, $side], true)
        && e24_have(e24_pools($game, $players), e24_side_order($players[$seat], $side, true)) >= (int) $s[$side . '_threshold'] + (int) $s['house_cost'];
      e24_buy($game, $players, 'st#' . $key, $side, $mysqli, $house);
    }
    else e24_buy($game, $players, $pick, null, $mysqli);
  }
  if ($paper === 'herald' && !$game['state']['turn']['used']['herald'] && e24_top_story($game) !== null
      && !e24_bot_late($game) && max(0, e24_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) {
    e24_use_paper($game, $players, $mysqli);
  }
  e24_end_turn($game, $players, $mysqli);
}

/** Cards in the hand with an ability to use. */
function e24_usable($player) {
  $out = [];
  foreach ($player['private_state']['hand'] as $id) if (e24_has_ability(e24_view($id))) $out[] = $id;
  return $out;
}

function e24_first_usable($player) {
  $u = e24_usable($player);
  return $u ? $u[0] : null;
}

/**
 * What the outlet on turn can buy right now: story and plank keys,
 * 'editorial', and the face-up states as 'st#pa:trump' / 'st#pa:harris'.
 */
function e24_affordable($game, $players) {
  $t = $game['state']['turn'];
  if ($t['staked']) return [];
  $pools = e24_pools($game, $players);
  $player = $players[$t['seat']];
  $out = [];
  foreach ($game['state']['exchange'] as $id) {
    $c = e24_view($id);
    if (e24_have($pools, e24_story_order($c)) >= (int) $c['cost']) $out[] = $id;
  }
  foreach (E24_DECKS as $d) {
    $key = $game['state']['up'][$d] ?? null;
    if (!$key) continue;
    $s = e24_state($key);
    foreach (E24_SIDES as $side) {
      if (e24_have($pools, e24_side_order($player, $side, true)) >= (int) $s[$side . '_threshold']) $out[] = 'st#' . $key . ':' . $side;
    }
  }
  if ((int) $game['state']['editorials'] > 0 && max(0, $pools['gen']) >= (int) e24_card('editorial')['cost']) $out[] = 'editorial';
  return $out;
}

/** The face-up state buys ('st#pa:trump') that can take the House delegation too. */
function e24_house_affordable($game, $players) {
  $t = $game['state']['turn'];
  if ($t['staked']) return [];
  $pools = e24_pools($game, $players);
  $out = [];
  foreach (E24_DECKS as $d) {
    $key = $game['state']['up'][$d] ?? null;
    if (!$key || !(int) e24_state($key)['house_cost']) continue;
    $s = e24_state($key);
    foreach (E24_SIDES as $side) {
      if (e24_have($pools, e24_side_order($players[$t['seat']], $side, true)) >= (int) $s[$side . '_threshold'] + (int) $s['house_cost']) {
        $out[] = 'st#' . $key . ':' . $side;
      }
    }
  }
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
    'deadlock'        => 'Every state is claimed and neither side has 270, and the House is tied: the election is deadlocked, and no stake scores.',
    'house'           => 'Every state is claimed and neither side has 270: the House decides, by the delegations bought.',
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
    'prestige' => $total, 'wealth' => $total, 'stakes_won' => $won, 'stakes_lost' => $lost, 'argus' => $argus, 'stakes' => $stakes,
    'winner_side' => $w,
    'paper' => $p['public_state']['paper'] ?? null,
    'called' => (int) ($p['public_state']['called'] ?? 0),
    'large_bought' => (int) ($p['public_state']['called'] ?? 0),
    'states_bought' => (int) ($p['public_state']['states_bought'] ?? 0),
    'ev_claimed' => (int) ($p['public_state']['ev_claimed'] ?? 0),
    'bought' => (int) ($p['public_state']['bought'] ?? 0),
    'knocks' => (int) ($p['public_state']['knocks'] ?? 0),
    'house_seats' => (int) ($p['public_state']['house_seats'] ?? 0),
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
      'knocks' => (int) ($p['public_state']['knocks'] ?? 0),
      'house_seats' => (int) ($p['public_state']['house_seats'] ?? 0),
      'hand_count' => (int) ($p['public_state']['hand_count'] ?? 0),
      'deck_count' => (int) ($p['public_state']['deck_count'] ?? 0),
      'discard_count' => (int) ($p['public_state']['discard_count'] ?? 0),
      'locations' => e24_views($p['public_state']['locations'] ?? []),
      'final_score' => $p['final_score'],
      'score_breakdown' => $ended ? $p['score_breakdown'] : null,
    ];
  }

  // The three state decks: the state face up on each (its reveal, both halves) and how many are left.
  $decksPublic = [];
  foreach (E24_DECKS as $d) {
    $key = $state['up'][$d] ?? null;
    $decksPublic[$d] = [
      'deck' => $d, 'left' => count($state['decks'][$d] ?? []),
      'up' => $key ? e24_view('st#' . $key) : null,
    ];
  }
  $lastReveal = null;
  if (!empty($state['last_reveal'])) {
    $lr = $state['last_reveal'];
    $lastReveal = array_merge(e24_view('st#' . $lr['key'])['reveal'], [
      'key' => $lr['key'], 'state' => e24_state($lr['key'])['state'], 'deck' => $lr['deck'], 'round' => (int) $lr['round'],
      'effect' => e24_reveal_effect(e24_state($lr['key'])['reveal_kind'], (int) (e24_state($lr['key'])['reveal_n'] ?? 1)),
    ]);
  }

  $map = [];
  foreach (e24_states() as $k => $s) {
    $cl = $state['claims'][$k] ?? null;
    $map[] = ['key' => $k, 'abbr' => $s['abbr'], 'state' => $s['state'], 'ev' => (int) $s['ev'], 'vp' => (int) $s['vp'],
              'deck' => $s['deck'], 'tier' => $s['tier'], 'margin' => (float) $s['margin'], 'winner_2024' => $s['winner'],
              'trump_cost' => (int) $s['trump_threshold'], 'harris_cost' => (int) $s['harris_threshold'],
              'side' => $cl['side'] ?? null, 'seat' => $cl['seat'] ?? null, 'how' => $cl['how'] ?? null,
              'house_seats' => (int) $s['house_seats'], 'house' => !empty($cl['house']) ? $cl['side'] : null];
  }

  $turnPublic = null;
  if ($current && $turn && $status === 'active') {
    $turnPublic = [
      'seat' => (int) $turn['seat'], 'played' => e24_views($turn['played']),
      'pools' => e24_pools($game, $players), 'frames' => $turn['frames'] ?? [],
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
                  'options' => e24_views($turn['pending']['options']), 'left' => (int) ($turn['pending']['left'] ?? 1),
                  'party' => $turn['pending']['party'] ?? null];
      if ($turn['pending']['type'] === 'knock') {
        foreach ($pending['options'] as $i => $o) {
          foreach ($players as $s2 => $q) {
            if (in_array($o['key'], $q['public_state']['locations'] ?? [], true)) {
              $pending['options'][$i]['owner'] = ['seat' => (int) $s2, 'name' => $q['player_name']];
            }
          }
        }
      }
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
               'trigger_seat' => $state['trigger_seat'] ?? null,
               'house' => $current ? e24_house_tally($game) : ['trump' => 0, 'harris' => 0]],
    'state_decks' => $decksPublic,
    'last_reveal' => $lastReveal,
    'set' => ($state['switched'] ?? null) !== null ? 'harris' : 'biden',
    'switched_round' => $state['switched'] ?? null,
    'map' => $map,
    'exchange' => e24_views($state['exchange'] ?? []),
    'editorial' => $current ? array_merge(e24_view('editorial#x'), ['left' => (int) $state['editorials']]) : null,
    'main_count' => count($state['main'] ?? []),
    'scandals_left' => (int) ($state['scandals'] ?? 0),
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
  $fresh = !$t['played'] && !$t['bought'] && !$t['used']['sun'] && !$t['used']['herald'] && !$t['staked'];
  $paper = $players[$seat]['public_state']['paper'] ?? null;
  $paperOk = false;
  if ($paper === 'sun' && !$t['used']['sun']) {
    foreach ($players[$seat]['private_state']['hand'] as $id) if (strpos($id, 'scandal#') === 0) $paperOk = true;
  }
  if ($paper === 'herald' && !$t['used']['herald'] && e24_top_story($game) !== null
      && max(0, e24_pools($game, $players)['gen']) >= (int) $game['config']['herald_cost']) $paperOk = true;
  $framings = [];
  foreach (e24_usable($players[$seat]) as $id) $framings[$id] = e24_framings(e24_view($id));
  return [
    'play' => e24_usable($players[$seat]),
    'framings' => $framings,
    'play_all' => !empty(e24_usable($players[$seat])),
    'buy_house' => e24_house_affordable($game, $players),
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

function e24_decks_text($game) {
  $parts = [];
  foreach (E24_DECKS as $d) {
    $key = $game['state']['up'][$d] ?? null;
    if ($key) $parts[] = e24_state($key)['state'] . ' (' . $d . ')';
  }
  return 'Face up: ' . implode(', ', $parts) . '.';
}

function e24_turn_text($game, $players, $t, $made) {
  $name = $players[$t['seat']]['player_name'];
  $parts = [$made . ' currency, ' . count($t['played']) . ' ' . (count($t['played']) === 1 ? 'ability' : 'abilities') . ' used'];
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
    foreach (E24_DECKS as $d) $board['decks'][$d] = count($board['decks'][$d] ?? []);   // the order still to come is hidden
    if (isset($board['turn']) && (int) $board['turn']['seat'] !== (int) $viewerSeat) $board['turn']['pending'] = null;
  }
  return [
    'export_version' => 7,
    'exported_at' => gmdate('c'),
    'summary' => [
      'game_id' => (int) $game['game_id'], 'join_code' => $game['join_code'] ?? null, 'variant' => $game['variant'] ?? null,
      'status' => $game['status'], 'phase' => $game['phase'], 'rounds' => (int) $game['round_number'],
      'tally' => is_array($game['state']) && engine_is_current($game) ? e24_tally($game) : null,
      'winner_side' => $game['state']['winner_side'] ?? null,
      'winner_seat' => $game['winner_seat'], 'ended_reason' => $game['ended_reason'],
      'created_at' => $game['created_at'] ?? null, 'ended_at' => $game['ended_at'] ?? null, 'config' => $game['config'],
    ],
    'reveals' => $game['state']['reveals'] ?? [],
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
