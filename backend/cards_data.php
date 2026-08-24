<?php
/**
 * cards_data.php — the deck: historical forces the press navigates.
 *
 * CONTENT, not rules. Same reasoning as history_data.php: authored material
 * belongs in code so a tuning change shows up in a diff.
 *
 * ---------------------------------------------------------------------
 * EACH CARD, TWO USES (from the brief)
 *
 *   FINANCE  Play it for money. If you control the sitting president you
 *            get more. ALWAYS legal — a card is never dead in hand.
 *
 *   SWAY     Spend money to push support toward ONE of the two candidates
 *            on the current space, gaining control points on them.
 *
 * The card also MOVES THE ISSUE TRACKS when swayed, and the direction is
 * fixed by history, not chosen by the player. That is the whole dilemma:
 * the Tariff of Abominations pushes protection whoever prints it, so a
 * player backing a free-trade candidate has to decide whether the control
 * points are worth handing their opponent the argument.
 * ---------------------------------------------------------------------
 *
 * PACKS. The deck starts as 'base' — cards arguing the founding questions
 * of markets, tariffs and federal power. Each of the three key cards
 * shuffles its pack into the draw deck, so playing Manifest Destiny is
 * literally what brings Texas, Oregon and Mexico into the conversation.
 *
 * A card whose tracks have been superseded stays playable for FINANCE but
 * not for SWAY: the Embargo is no longer what the country argues about,
 * though you can still sell papers about it.
 *
 * TUNING. finance / sway_cost / sway_cp are first-draft numbers, to be set
 * by simulation rather than by feel — see tools/simulate.py. The number
 * that matters most is engine config control_bonus, not anything here.
 */

/**
 * The whole card pool, keyed by card key.
 *
 * deltas are applied to the issue tracks on a SWAY play, clamped to the
 * track range by the engine. stability is applied on a sway play too.
 */
function vg_cards() {
  return [

    // ---------------------------------------------------------------
    // BASE PACK — markets, tariffs, federal power. 1796 to roughly 1840.
    // ---------------------------------------------------------------

    'jay_treaty' => [
      'name' => 'The Jay Treaty', 'year' => 1795, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['market' => -2, 'federal' => 1], 'stability' => -1,
      'flavor' => 'Peace with Britain, bought with the carrying trade. Burned in effigy from Boston to Charleston.'],

    'xyz_affair' => [
      'name' => 'The XYZ Affair', 'year' => 1798, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 4,
      'deltas' => ['market' => 2, 'federal' => 1], 'stability' => -1,
      'flavor' => 'Millions for defence, not one cent for tribute. The best copy the Federalists ever had.'],

    'alien_sedition' => [
      'name' => 'The Alien and Sedition Acts', 'year' => 1798, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => 3], 'stability' => -2,
      'flavor' => 'Twenty-five arrests, and most of them printers. The press learned what it was worth.'],

    'kentucky_resolutions' => [
      'name' => 'The Kentucky and Virginia Resolutions', 'year' => 1798, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => -3], 'stability' => -1,
      'flavor' => 'Jefferson and Madison, anonymously, arguing that a state might judge the compact for itself.'],

    'report_manufactures' => [
      'name' => 'The Report on Manufactures', 'year' => 1791, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['tariff' => 2, 'federal' => 1], 'stability' => 0,
      'flavor' => 'Hamilton on why a farming republic should build factories it does not yet want.'],

    'louisiana_purchase' => [
      'name' => 'The Louisiana Purchase', 'year' => 1803, 'pack' => 'base',
      'finance' => 6, 'sway_cost' => 4, 'sway_cp' => 3,
      'deltas' => ['market' => 1, 'federal' => 2], 'stability' => 1,
      'flavor' => 'Fifteen million dollars, and a strict constructionist who could not find the clause.'],

    'marbury' => [
      'name' => 'Marbury v. Madison', 'year' => 1803, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['federal' => 2], 'stability' => 0,
      'flavor' => 'Marshall gave up a commission and took the power to void an act of Congress.'],

    'embargo_act' => [
      'name' => 'The Embargo Act', 'year' => 1807, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['market' => 3, 'tariff' => 1], 'stability' => -2,
      'flavor' => 'Grass grew on the wharves of Salem. New England read it as a southern war on shipping.'],

    'chesapeake_affair' => [
      'name' => 'The Chesapeake Affair', 'year' => 1807, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['market' => 2], 'stability' => -1,
      'flavor' => 'A British broadside into an American frigate in American water. Impressment made personal.'],

    'war_of_1812' => [
      'name' => 'Mr. Madison\'s War', 'year' => 1812, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['market' => 3, 'federal' => 1], 'stability' => -2,
      'flavor' => 'The second war of independence, or a Virginian adventure at New England expense.'],

    'hartford_convention' => [
      'name' => 'The Hartford Convention', 'year' => 1814, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => -3], 'stability' => -2,
      'flavor' => 'New England Federalists met in secret to discuss leaving, and destroyed their own party.'],

    'treaty_of_ghent' => [
      'name' => 'The Treaty of Ghent', 'year' => 1814, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 2, 'sway_cp' => 2,
      'deltas' => ['market' => 1], 'stability' => 2,
      'flavor' => 'Status quo ante bellum. Everyone claimed to have won.'],

    'second_bank' => [
      'name' => 'The Second Bank of the United States', 'year' => 1816, 'pack' => 'base',
      'finance' => 6, 'sway_cost' => 4, 'sway_cp' => 3,
      'deltas' => ['federal' => 2, 'tariff' => 1], 'stability' => 0,
      'flavor' => 'A national currency, and a monster, depending on which paper you took.'],

    'tariff_of_1816' => [
      'name' => 'The Tariff of 1816', 'year' => 1816, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['tariff' => 2, 'market' => 1], 'stability' => 0,
      'flavor' => 'The first protective tariff, passed by southerners who had learned what a blockade does.'],

    'panic_of_1819' => [
      'name' => 'The Panic of 1819', 'year' => 1819, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['tariff' => 1, 'federal' => -1], 'stability' => -2,
      'flavor' => 'The Bank called in its loans and the west discovered what credit was.'],

    'missouri_compromise' => [
      'name' => 'The Missouri Compromise', 'year' => 1820, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['federal' => 1], 'stability' => 2,
      'flavor' => 'A fire bell in the night, muffled for thirty years by a line at 36 degrees 30 minutes.'],

    'monroe_doctrine' => [
      'name' => 'The Monroe Doctrine', 'year' => 1823, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['market' => 2, 'federal' => 1], 'stability' => 1,
      'flavor' => 'A hemisphere closed to European colonisation, announced by a nation that could not enforce it.'],

    'erie_canal' => [
      'name' => 'The Erie Canal', 'year' => 1825, 'pack' => 'base',
      'finance' => 6, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['market' => 1, 'federal' => 1], 'stability' => 1,
      'flavor' => 'Three hundred and sixty-three miles, and the price of flour in New York fell by half.'],

    'american_system' => [
      'name' => 'The American System', 'year' => 1824, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['tariff' => 3, 'federal' => 2], 'stability' => 0,
      'flavor' => 'Clay wanted tariffs, a bank and roads, and to be remembered for all three.'],

    'tariff_abominations' => [
      'name' => 'The Tariff of Abominations', 'year' => 1828, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['tariff' => 3], 'stability' => -2,
      'flavor' => 'Written to be so bad it would fail, and passed anyway.'],

    'sc_exposition' => [
      'name' => 'The South Carolina Exposition', 'year' => 1828, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => -3, 'tariff' => -1], 'stability' => -2,
      'flavor' => 'Calhoun, anonymously and while Vice President, on the right of a state to say no.'],

    'webster_hayne' => [
      'name' => 'The Webster-Hayne Debate', 'year' => 1830, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['federal' => 2], 'stability' => -1,
      'flavor' => 'Liberty and Union, now and forever, one and inseparable. It sold very well in print.'],

    'bank_war' => [
      'name' => 'The Bank War', 'year' => 1832, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => -3], 'stability' => -1,
      'flavor' => 'The Bank tried to make itself the election. Jackson was delighted to oblige.'],

    'force_bill' => [
      'name' => 'The Force Bill', 'year' => 1833, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['federal' => 3], 'stability' => -1,
      'flavor' => 'Authority to collect the tariff by arms, signed the same day as the compromise that made it moot.'],

    'specie_circular' => [
      'name' => 'The Specie Circular', 'year' => 1836, 'pack' => 'base',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['federal' => -1, 'market' => 1], 'stability' => -1,
      'flavor' => 'Public land for gold and silver only. The paper economy discovered how thin it was.'],

    'panic_of_1837' => [
      'name' => 'The Panic of 1837', 'year' => 1837, 'pack' => 'base',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 4,
      'deltas' => ['tariff' => 1, 'federal' => -2], 'stability' => -2,
      'flavor' => 'Six years of depression, and a president renamed Van Ruin for the duration.'],

    'penny_press' => [
      'name' => 'The Penny Press', 'year' => 1833, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 1, 'sway_cp' => 3,
      'deltas' => [], 'stability' => -1,
      'flavor' => 'A cent a copy, sold in the street rather than by subscription. Your own trade, industrialised.'],

    'lowell_mills' => [
      'name' => 'The Lowell Mills', 'year' => 1826, 'pack' => 'base',
      'finance' => 6, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['tariff' => 2, 'market' => 2], 'stability' => 0,
      'flavor' => 'Textile capital with a moral architecture attached, and a constituency for protection.'],

    'cotton_boom' => [
      'name' => 'The Cotton Boom', 'year' => 1830, 'pack' => 'base',
      'finance' => 7, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['market' => -3, 'tariff' => -2], 'stability' => 0,
      'flavor' => 'Two thirds of American exports, sold to Liverpool. Independence was never that simple.'],

    'gag_rule' => [
      'name' => 'The Gag Rule', 'year' => 1836, 'pack' => 'base',
      'finance' => 3, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['federal' => 1], 'stability' => -2,
      'flavor' => 'Antislavery petitions tabled unread, which turned the right of petition into the argument.'],

    // ---------------------------------------------------------------
    // KEY CARDS — one per track. Each shifts ONE issue and shuffles its
    // pack into the deck. Removed from the game once played.
    // ---------------------------------------------------------------

    'key_manifest_destiny' => [
      'name' => 'Manifest Destiny', 'year' => 1845, 'pack' => 'base',
      'key' => true, 'earliest_space' => 8, 'transitions' => 'market', 'unlocks' => 'expansion',
      'finance' => 4, 'sway_cost' => 0, 'sway_cp' => 0,
      'deltas' => [], 'stability' => -1,
      'flavor' => 'O\'Sullivan gave it a name, and the question stopped being Europe and started being the continent.'],

    'key_slave_power' => [
      'name' => 'The Slave Power', 'year' => 1846, 'pack' => 'base',
      'key' => true, 'earliest_space' => 9, 'transitions' => 'tariff', 'unlocks' => 'slavery',
      'finance' => 4, 'sway_cost' => 0, 'sway_cp' => 0,
      'deltas' => [], 'stability' => -2,
      'flavor' => 'Once the tariff stopped being the sectional question, there was only one candidate to replace it.'],

    'key_states_rights' => [
      'name' => 'The Doctrine of States Rights', 'year' => 1850, 'pack' => 'base',
      'key' => true, 'earliest_space' => 10, 'transitions' => 'federal', 'unlocks' => 'states',
      'finance' => 4, 'sway_cost' => 0, 'sway_cp' => 0,
      'deltas' => [], 'stability' => -2,
      'flavor' => 'The old argument about federal power, restated as an argument about sovereignty.'],

    // ---------------------------------------------------------------
    // EXPANSION PACK — unlocked by Manifest Destiny.
    // ---------------------------------------------------------------

    'texas_annexation' => [
      'name' => 'The Annexation of Texas', 'year' => 1845, 'pack' => 'expansion',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['expansion' => 3], 'stability' => -2,
      'flavor' => 'Admitted by joint resolution because a treaty could not get two thirds.'],

    'oregon_treaty' => [
      'name' => 'Fifty-four Forty', 'year' => 1846, 'pack' => 'expansion',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['expansion' => 2], 'stability' => 0,
      'flavor' => 'Fifty-four forty or fight, settled quietly at forty-nine.'],

    'mexican_war' => [
      'name' => 'The Mexican War', 'year' => 1846, 'pack' => 'expansion',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['expansion' => 3], 'stability' => -2,
      'flavor' => 'American blood shed on American soil, if you accepted where the soil began.'],

    'gold_rush' => [
      'name' => 'The Gold Rush', 'year' => 1849, 'pack' => 'expansion',
      'finance' => 8, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['expansion' => 2], 'stability' => -1,
      'flavor' => 'Ninety thousand people crossed a continent in a year, and California wanted in at once.'],

    'gadsden_purchase' => [
      'name' => 'The Gadsden Purchase', 'year' => 1854, 'pack' => 'expansion',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 2,
      'deltas' => ['expansion' => 2], 'stability' => 0,
      'flavor' => 'Ten million dollars of desert, bought for a southern railroad route.'],

    'ostend_manifesto' => [
      'name' => 'The Ostend Manifesto', 'year' => 1854, 'pack' => 'expansion',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['expansion' => 3, 'slavery' => 2], 'stability' => -2,
      'flavor' => 'Three ministers abroad proposed simply taking Cuba, and someone leaked it.'],

    'filibusters' => [
      'name' => 'The Filibusters', 'year' => 1856, 'pack' => 'expansion',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['expansion' => 2, 'slavery' => 1], 'stability' => -1,
      'flavor' => 'Walker took Nicaragua with fifty-eight men and reinstated slavery there.'],

    'transcontinental_railroad' => [
      'name' => 'The Pacific Railroad Surveys', 'year' => 1853, 'pack' => 'expansion',
      'finance' => 6, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['expansion' => 2, 'states' => -1], 'stability' => 0,
      'flavor' => 'Every proposed route was an argument about which section would own the future.'],

    // ---------------------------------------------------------------
    // SLAVERY PACK — unlocked by The Slave Power.
    // ---------------------------------------------------------------

    'wilmot_proviso' => [
      'name' => 'The Wilmot Proviso', 'year' => 1846, 'pack' => 'slavery',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['slavery' => -3], 'stability' => -2,
      'flavor' => 'Neither slavery nor involuntary servitude in any territory taken from Mexico. It passed the House eight times.'],

    'compromise_1850' => [
      'name' => 'The Compromise of 1850', 'year' => 1850, 'pack' => 'slavery',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 3,
      'deltas' => ['slavery' => 1], 'stability' => 3,
      'flavor' => 'Five bills passed separately because no majority existed for all five together.'],

    'fugitive_slave_act' => [
      'name' => 'The Fugitive Slave Act', 'year' => 1850, 'pack' => 'slavery',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['slavery' => 3, 'states' => -2], 'stability' => -3,
      'flavor' => 'Federal power used against the North on behalf of the South, and it radicalised both.'],

    'uncle_toms_cabin' => [
      'name' => 'Uncle Tom\'s Cabin', 'year' => 1852, 'pack' => 'slavery',
      'finance' => 6, 'sway_cost' => 3, 'sway_cp' => 4,
      'deltas' => ['slavery' => -3], 'stability' => -2,
      'flavor' => 'Three hundred thousand copies in a year. The best-selling argument any of you ever printed.'],

    'kansas_nebraska' => [
      'name' => 'The Kansas-Nebraska Act', 'year' => 1854, 'pack' => 'slavery',
      'finance' => 4, 'sway_cost' => 5, 'sway_cp' => 5,
      'deltas' => ['slavery' => 2, 'states' => 2], 'stability' => -3,
      'flavor' => 'Douglas repealed the Missouri line to get his railroad, and detonated the party system.'],

    'bleeding_kansas' => [
      'name' => 'Bleeding Kansas', 'year' => 1856, 'pack' => 'slavery',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['slavery' => 1], 'stability' => -3,
      'flavor' => 'Two territorial governments, two constitutions, and a body count. Circulation soared.'],

    'sumner_caning' => [
      'name' => 'The Caning of Charles Sumner', 'year' => 1856, 'pack' => 'slavery',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 4,
      'deltas' => ['slavery' => -2], 'stability' => -3,
      'flavor' => 'Beaten unconscious on the Senate floor. The South sent Brooks replacement canes.'],

    'dred_scott' => [
      'name' => 'Dred Scott v. Sandford', 'year' => 1857, 'pack' => 'slavery',
      'finance' => 4, 'sway_cost' => 5, 'sway_cp' => 5,
      'deltas' => ['slavery' => 3, 'states' => 1], 'stability' => -3,
      'flavor' => 'No rights which the white man was bound to respect, and Congress powerless in the territories.'],

    'lincoln_douglas' => [
      'name' => 'The Lincoln-Douglas Debates', 'year' => 1858, 'pack' => 'slavery',
      'finance' => 5, 'sway_cost' => 3, 'sway_cp' => 4,
      'deltas' => ['slavery' => -2], 'stability' => -1,
      'flavor' => 'Seven towns, three hours each, printed verbatim in every paper in the country. Including yours.'],

    'john_browns_raid' => [
      'name' => 'John Brown at Harpers Ferry', 'year' => 1859, 'pack' => 'slavery',
      'finance' => 5, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['slavery' => -1, 'states' => 2], 'stability' => -4,
      'flavor' => 'A martyr or a murderer, and no paper in the country could avoid choosing.'],

    // ---------------------------------------------------------------
    // STATES RIGHTS PACK — unlocked by The Doctrine of States Rights.
    // ---------------------------------------------------------------

    'personal_liberty_laws' => [
      'name' => 'The Personal Liberty Laws', 'year' => 1855, 'pack' => 'states',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['states' => 2, 'slavery' => -1], 'stability' => -2,
      'flavor' => 'Northern states nullifying a federal statute, which the South noticed with interest.'],

    'ableman_v_booth' => [
      'name' => 'Ableman v. Booth', 'year' => 1859, 'pack' => 'states',
      'finance' => 3, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['states' => -3], 'stability' => -1,
      'flavor' => 'Taney told Wisconsin that no state court could overrule a federal one.'],

    'georgia_platform' => [
      'name' => 'The Georgia Platform', 'year' => 1850, 'pack' => 'states',
      'finance' => 3, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['states' => 2], 'stability' => -1,
      'flavor' => 'Georgia would accept the Compromise, and named the conditions under which it would not.'],

    'nashville_convention' => [
      'name' => 'The Nashville Convention', 'year' => 1850, 'pack' => 'states',
      'finance' => 3, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['states' => 3], 'stability' => -2,
      'flavor' => 'Nine states met to consider what the South would do if the territories were closed.'],

    'lecompton_constitution' => [
      'name' => 'The Lecompton Constitution', 'year' => 1857, 'pack' => 'states',
      'finance' => 4, 'sway_cost' => 4, 'sway_cp' => 4,
      'deltas' => ['states' => 2, 'slavery' => 2], 'stability' => -3,
      'flavor' => 'A proslavery constitution written by a minority, which split the Democratic party in two.'],

    'freeport_doctrine' => [
      'name' => 'The Freeport Doctrine', 'year' => 1858, 'pack' => 'states',
      'finance' => 4, 'sway_cost' => 3, 'sway_cp' => 3,
      'deltas' => ['states' => 2, 'slavery' => -1], 'stability' => -2,
      'flavor' => 'Douglas held that a territory could exclude slavery by simply declining to police it. It cost him the South.'],
  ];
}

/** Cards belonging to one pack. */
function vg_cards_in_pack($pack) {
  $out = [];
  foreach (vg_cards() as $key => $card) {
    if ($card['pack'] === $pack) $out[] = $key;
  }
  return $out;
}

/** The three key cards, in the order their tracks are listed. */
function vg_key_cards() {
  $out = [];
  foreach (vg_cards() as $key => $card) {
    if (!empty($card['key'])) $out[] = $key;
  }
  return $out;
}

/** One card definition by key, or null. */
function vg_card($key) {
  $cards = vg_cards();
  return isset($cards[$key]) ? $cards[$key] : null;
}
