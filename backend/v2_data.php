<?php
/**
 * v2_data.php — content for the SIMPLIFIED ruleset (v2). Not yet read by
 * the engine: the live game still runs on history_data.php and
 * cards_data.php. tools/simulate_v2.py parses this file, so tuning runs
 * against exactly the content the rewritten engine will read.
 *
 * ONE TRACK, Nation (+) against States (-), from -5 to +5.
 *
 * DERIVED, NOT RE-AUTHORED. Every number here came mechanically from the
 * v1 data, so it is cheap to regenerate and nothing was tuned by feel:
 *
 *   side   In each race, the candidate with the higher v1 Federal Power
 *          stance is the Nation candidate.
 *   push   v1 deltas: federal - expansion - slavery - states, halved
 *          (rounding away from zero) and clamped to -2..+2. Market and
 *          tariff are dropped: market had no consistent partisan
 *          direction, and counting tariff made the early deck lean +20
 *          toward Nation, so Nation won ~90% of early races. Full-size
 *          pushes pinned the track at +-5 in most elections.
 *   value  v1 finance value.
 *   era    base-pack cards are early; the three late packs arrive with
 *          the crisis. The v1 key cards are gone.
 *
 * Known oddities of the formula, left for a playtest to argue with: the
 * Louisiana Purchase comes out Nation +1, and the Missouri Compromise
 * Nation +1.
 */

/** The fourteen races. Each has exactly one nation and one states candidate. */
function vg2_elections() {
  return [
    ['space' => 1, 'year' => 1796, 'historical_winner' => 'nation',
     'note' => 'The first contested election. Adams carried New England and the commercial seaboard.',
     'nation' => ['key' => 'adams_1796', 'name' => 'John Adams', 'party' => 'Federalist',
       'note' => 'Atlantic commerce, a funded debt, and a strong executive.'],
     'states' => ['key' => 'jefferson_1796', 'name' => 'Thomas Jefferson', 'party' => 'Democratic-Republican',
       'note' => 'An agrarian republic, free trade, and a government of enumerated powers.'],
    ],
    ['space' => 2, 'year' => 1800, 'historical_winner' => 'states',
     'note' => 'The Revolution of 1800. A tie with Burr threw it to the House for thirty-six ballots.',
     'nation' => ['key' => 'adams_1800', 'name' => 'John Adams', 'party' => 'Federalist',
       'note' => 'Peace with France cost him his own party.'],
     'states' => ['key' => 'jefferson_1800', 'name' => 'Thomas Jefferson', 'party' => 'Democratic-Republican',
       'note' => 'Ran against the Alien and Sedition Acts and the standing army that paid for them.'],
    ],
    ['space' => 3, 'year' => 1808, 'historical_winner' => 'states',
     'note' => 'Fought over the Embargo, which had wrecked New England shipping.',
     'nation' => ['key' => 'pinckney_1808', 'name' => 'Charles C. Pinckney', 'party' => 'Federalist',
       'note' => 'A South Carolina Federalist carrying a New England grievance.'],
     'states' => ['key' => 'madison_1808', 'name' => 'James Madison', 'party' => 'Democratic-Republican',
       'note' => 'Architect of commercial coercion as an alternative to war.'],
    ],
    ['space' => 4, 'year' => 1812, 'historical_winner' => 'states',
     'note' => 'A wartime election. Clinton ran as the peace candidate on Federalist votes.',
     'nation' => ['key' => 'clinton_1812', 'name' => 'DeWitt Clinton', 'party' => 'Federalist coalition',
       'note' => 'A Republican running on Federalist money to end the war.'],
     'states' => ['key' => 'madison_1812', 'name' => 'James Madison', 'party' => 'Democratic-Republican',
       'note' => 'War with Britain as the completion of independence.'],
    ],
    ['space' => 5, 'year' => 1824, 'historical_winner' => 'nation',
     'note' => 'Jackson led the popular and electoral vote; the House chose Adams. The Corrupt Bargain.',
     'nation' => ['key' => 'jqadams_1824', 'name' => 'John Quincy Adams', 'party' => 'Democratic-Republican',
       'note' => 'Roads, canals, a national university, and the American System.'],
     'states' => ['key' => 'jackson_1824', 'name' => 'Andrew Jackson', 'party' => 'Democratic-Republican',
       'note' => 'The military hero as the outsider against a Washington arrangement.'],
    ],
    ['space' => 6, 'year' => 1828, 'historical_winner' => 'states',
     'note' => 'The first mass-participation campaign, and the dirtiest so far.',
     'nation' => ['key' => 'jqadams_1828', 'name' => 'John Quincy Adams', 'party' => 'National Republican',
       'note' => 'Defended the Tariff of Abominations he had not written.'],
     'states' => ['key' => 'jackson_1828', 'name' => 'Andrew Jackson', 'party' => 'Democrat',
       'note' => 'Rotation in office and hostility to concentrated financial power.'],
    ],
    ['space' => 7, 'year' => 1832, 'historical_winner' => 'states',
     'note' => 'A referendum on the Bank of the United States, held as South Carolina moved to nullify.',
     'nation' => ['key' => 'clay_1832', 'name' => 'Henry Clay', 'party' => 'National Republican',
       'note' => 'Forced the Bank question early and lost on it.'],
     'states' => ['key' => 'jackson_1832', 'name' => 'Andrew Jackson', 'party' => 'Democrat',
       'note' => 'Killed the Bank, then threatened to hang nullifiers.'],
    ],
    ['space' => 8, 'year' => 1836, 'historical_winner' => 'states',
     'note' => 'The Whigs ran several regional candidates at once and still lost.',
     'nation' => ['key' => 'harrison_1836', 'name' => 'William Henry Harrison', 'party' => 'Whig',
       'note' => 'Tippecanoe, tried out as a national candidate for the first time.'],
     'states' => ['key' => 'vanburen_1836', 'name' => 'Martin Van Buren', 'party' => 'Democrat',
       'note' => 'The party manager who built the machine, inheriting it.'],
    ],
    ['space' => 9, 'year' => 1840, 'historical_winner' => 'nation',
     'note' => 'Log cabins and hard cider. The Panic of 1837 did the rest.',
     'nation' => ['key' => 'harrison_1840', 'name' => 'William Henry Harrison', 'party' => 'Whig',
       'note' => 'A campaign of image with almost no platform, and it worked.'],
     'states' => ['key' => 'vanburen_1840', 'name' => 'Martin Van Buren', 'party' => 'Democrat',
       'note' => 'Van Ruin, blamed for a depression he did not cause.'],
    ],
    ['space' => 10, 'year' => 1844, 'historical_winner' => 'states',
     'note' => 'Texas annexation. Clay equivocated and lost New York by five thousand votes.',
     'nation' => ['key' => 'clay_1844', 'name' => 'Henry Clay', 'party' => 'Whig',
       'note' => 'Tried to hold North and South together on annexation and satisfied neither.'],
     'states' => ['key' => 'polk_1844', 'name' => 'James K. Polk', 'party' => 'Democrat',
       'note' => 'The first dark horse: Texas, Oregon, and a lower tariff.'],
    ],
    ['space' => 11, 'year' => 1848, 'historical_winner' => 'nation',
     'note' => 'Free Soil took enough of New York to decide it.',
     'nation' => ['key' => 'taylor_1848', 'name' => 'Zachary Taylor', 'party' => 'Whig',
       'note' => 'A Louisiana slaveholder who turned out to be an unbending Unionist.'],
     'states' => ['key' => 'cass_1848', 'name' => 'Lewis Cass', 'party' => 'Democrat',
       'note' => 'Invented popular sovereignty as a way of not answering the question.'],
    ],
    ['space' => 12, 'year' => 1852, 'historical_winner' => 'states',
     'note' => 'The Whig party won four states and never contested another election.',
     'nation' => ['key' => 'scott_1852', 'name' => 'Winfield Scott', 'party' => 'Whig',
       'note' => 'A general the southern Whigs would not run on.'],
     'states' => ['key' => 'pierce_1852', 'name' => 'Franklin Pierce', 'party' => 'Democrat',
       'note' => 'A northern man of southern principles, nominated on the forty-ninth ballot.'],
    ],
    ['space' => 13, 'year' => 1856, 'historical_winner' => 'states',
     'note' => 'Bleeding Kansas. A party four years old came second.',
     'nation' => ['key' => 'fremont_1856', 'name' => 'John C. Fremont', 'party' => 'Republican',
       'note' => 'Free soil, free men, and no southern ballot access at all.'],
     'states' => ['key' => 'buchanan_1856', 'name' => 'James Buchanan', 'party' => 'Democrat',
       'note' => 'Chosen largely because he had been abroad during Kansas-Nebraska.'],
    ],
    ['space' => 14, 'year' => 1860, 'historical_winner' => 'nation',
     'note' => 'Actually a four-way race; the board reduces it to the two candidates who defined the question.',
     'nation' => ['key' => 'lincoln_1860', 'name' => 'Abraham Lincoln', 'party' => 'Republican',
       'note' => 'Not one southern state placed him on the ballot.'],
     'states' => ['key' => 'douglas_1860', 'name' => 'Stephen A. Douglas', 'party' => 'Democrat',
       'note' => 'Campaigned in the South against secession once he knew he had lost.'],
    ],
  ];
}

/** The deck. push is + for Nation, - for States. */
function vg2_cards() {
  return [
    'jay_treaty' => ['name' => 'The Jay Treaty', 'year' => 1795, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'Peace with Britain, bought with the carrying trade. Burned in effigy from Boston to Charleston.'],
    'xyz_affair' => ['name' => 'The XYZ Affair', 'year' => 1798, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'Millions for defence, not one cent for tribute. The best copy the Federalists ever had.'],
    'alien_sedition' => ['name' => 'The Alien and Sedition Acts', 'year' => 1798, 'era' => 'early', 'value' => 3, 'push' => 2,
      'flavor' => 'Twenty-five arrests, and most of them printers. The press learned what it was worth.'],
    'kentucky_resolutions' => ['name' => 'The Kentucky and Virginia Resolutions', 'year' => 1798, 'era' => 'early', 'value' => 3, 'push' => -2,
      'flavor' => 'Jefferson and Madison, anonymously, arguing that a state might judge the compact for itself.'],
    'report_manufactures' => ['name' => 'The Report on Manufactures', 'year' => 1791, 'era' => 'early', 'value' => 5, 'push' => 1,
      'flavor' => 'Hamilton on why a farming republic should build factories it does not yet want.'],
    'louisiana_purchase' => ['name' => 'The Louisiana Purchase', 'year' => 1803, 'era' => 'early', 'value' => 6, 'push' => 1,
      'flavor' => 'Fifteen million dollars, and a strict constructionist who could not find the clause.'],
    'marbury' => ['name' => 'Marbury v. Madison', 'year' => 1803, 'era' => 'early', 'value' => 3, 'push' => 1,
      'flavor' => 'Marshall gave up a commission and took the power to void an act of Congress.'],
    'embargo_act' => ['name' => 'The Embargo Act', 'year' => 1807, 'era' => 'early', 'value' => 3, 'push' => 0,
      'flavor' => 'Grass grew on the wharves of Salem. New England read it as a southern war on shipping.'],
    'chesapeake_affair' => ['name' => 'The Chesapeake Affair', 'year' => 1807, 'era' => 'early', 'value' => 4, 'push' => 0,
      'flavor' => 'A British broadside into an American frigate in American water. Impressment made personal.'],
    'war_of_1812' => ['name' => 'Mr. Madison\'s War', 'year' => 1812, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'The second war of independence, or a Virginian adventure at New England expense.'],
    'hartford_convention' => ['name' => 'The Hartford Convention', 'year' => 1814, 'era' => 'early', 'value' => 3, 'push' => -2,
      'flavor' => 'New England Federalists met in secret to discuss leaving, and destroyed their own party.'],
    'treaty_of_ghent' => ['name' => 'The Treaty of Ghent', 'year' => 1814, 'era' => 'early', 'value' => 5, 'push' => 0,
      'flavor' => 'Status quo ante bellum. Everyone claimed to have won.'],
    'second_bank' => ['name' => 'The Second Bank of the United States', 'year' => 1816, 'era' => 'early', 'value' => 6, 'push' => 1,
      'flavor' => 'A national currency, and a monster, depending on which paper you took.'],
    'tariff_of_1816' => ['name' => 'The Tariff of 1816', 'year' => 1816, 'era' => 'early', 'value' => 4, 'push' => 0,
      'flavor' => 'The first protective tariff, passed by southerners who had learned what a blockade does.'],
    'panic_of_1819' => ['name' => 'The Panic of 1819', 'year' => 1819, 'era' => 'early', 'value' => 5, 'push' => -1,
      'flavor' => 'The Bank called in its loans and the west discovered what credit was.'],
    'missouri_compromise' => ['name' => 'The Missouri Compromise', 'year' => 1820, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'A fire bell in the night, muffled for thirty years by a line at 36 degrees 30 minutes.'],
    'monroe_doctrine' => ['name' => 'The Monroe Doctrine', 'year' => 1823, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'A hemisphere closed to European colonisation, announced by a nation that could not enforce it.'],
    'erie_canal' => ['name' => 'The Erie Canal', 'year' => 1825, 'era' => 'early', 'value' => 6, 'push' => 1,
      'flavor' => 'Three hundred and sixty-three miles, and the price of flour in New York fell by half.'],
    'american_system' => ['name' => 'The American System', 'year' => 1824, 'era' => 'early', 'value' => 5, 'push' => 1,
      'flavor' => 'Clay wanted tariffs, a bank and roads, and to be remembered for all three.'],
    'tariff_abominations' => ['name' => 'The Tariff of Abominations', 'year' => 1828, 'era' => 'early', 'value' => 4, 'push' => 0,
      'flavor' => 'Written to be so bad it would fail, and passed anyway.'],
    'sc_exposition' => ['name' => 'The South Carolina Exposition', 'year' => 1828, 'era' => 'early', 'value' => 3, 'push' => -2,
      'flavor' => 'Calhoun, anonymously and while Vice President, on the right of a state to say no.'],
    'webster_hayne' => ['name' => 'The Webster-Hayne Debate', 'year' => 1830, 'era' => 'early', 'value' => 4, 'push' => 1,
      'flavor' => 'Liberty and Union, now and forever, one and inseparable. It sold very well in print.'],
    'bank_war' => ['name' => 'The Bank War', 'year' => 1832, 'era' => 'early', 'value' => 5, 'push' => -2,
      'flavor' => 'The Bank tried to make itself the election. Jackson was delighted to oblige.'],
    'force_bill' => ['name' => 'The Force Bill', 'year' => 1833, 'era' => 'early', 'value' => 3, 'push' => 2,
      'flavor' => 'Authority to collect the tariff by arms, signed the same day as the compromise that made it moot.'],
    'specie_circular' => ['name' => 'The Specie Circular', 'year' => 1836, 'era' => 'early', 'value' => 4, 'push' => -1,
      'flavor' => 'Public land for gold and silver only. The paper economy discovered how thin it was.'],
    'panic_of_1837' => ['name' => 'The Panic of 1837', 'year' => 1837, 'era' => 'early', 'value' => 5, 'push' => -1,
      'flavor' => 'Six years of depression, and a president renamed Van Ruin for the duration.'],
    'penny_press' => ['name' => 'The Penny Press', 'year' => 1833, 'era' => 'early', 'value' => 3, 'push' => 0,
      'flavor' => 'A cent a copy, sold in the street rather than by subscription. Your own trade, industrialised.'],
    'lowell_mills' => ['name' => 'The Lowell Mills', 'year' => 1826, 'era' => 'early', 'value' => 6, 'push' => 0,
      'flavor' => 'Textile capital with a moral architecture attached, and a constituency for protection.'],
    'cotton_boom' => ['name' => 'The Cotton Boom', 'year' => 1830, 'era' => 'early', 'value' => 7, 'push' => 0,
      'flavor' => 'Two thirds of American exports, sold to Liverpool. Independence was never that simple.'],
    'gag_rule' => ['name' => 'The Gag Rule', 'year' => 1836, 'era' => 'early', 'value' => 3, 'push' => 1,
      'flavor' => 'Antislavery petitions tabled unread, which turned the right of petition into the argument.'],
    'texas_annexation' => ['name' => 'The Annexation of Texas', 'year' => 1845, 'era' => 'crisis', 'value' => 5, 'push' => -2,
      'flavor' => 'Admitted by joint resolution because a treaty could not get two thirds.'],
    'oregon_treaty' => ['name' => 'Fifty-four Forty', 'year' => 1846, 'era' => 'crisis', 'value' => 4, 'push' => -1,
      'flavor' => 'Fifty-four forty or fight, settled quietly at forty-nine.'],
    'mexican_war' => ['name' => 'The Mexican War', 'year' => 1846, 'era' => 'crisis', 'value' => 5, 'push' => -2,
      'flavor' => 'American blood shed on American soil, if you accepted where the soil began.'],
    'gold_rush' => ['name' => 'The Gold Rush', 'year' => 1849, 'era' => 'crisis', 'value' => 8, 'push' => -1,
      'flavor' => 'Ninety thousand people crossed a continent in a year, and California wanted in at once.'],
    'gadsden_purchase' => ['name' => 'The Gadsden Purchase', 'year' => 1854, 'era' => 'crisis', 'value' => 5, 'push' => -1,
      'flavor' => 'Ten million dollars of desert, bought for a southern railroad route.'],
    'ostend_manifesto' => ['name' => 'The Ostend Manifesto', 'year' => 1854, 'era' => 'crisis', 'value' => 4, 'push' => -2,
      'flavor' => 'Three ministers abroad proposed simply taking Cuba, and someone leaked it.'],
    'filibusters' => ['name' => 'The Filibusters', 'year' => 1856, 'era' => 'crisis', 'value' => 4, 'push' => -2,
      'flavor' => 'Walker took Nicaragua with fifty-eight men and reinstated slavery there.'],
    'transcontinental_railroad' => ['name' => 'The Pacific Railroad Surveys', 'year' => 1853, 'era' => 'crisis', 'value' => 6, 'push' => -1,
      'flavor' => 'Every proposed route was an argument about which section would own the future.'],
    'wilmot_proviso' => ['name' => 'The Wilmot Proviso', 'year' => 1846, 'era' => 'crisis', 'value' => 3, 'push' => 2,
      'flavor' => 'Neither slavery nor involuntary servitude in any territory taken from Mexico. It passed the House eight times.'],
    'compromise_1850' => ['name' => 'The Compromise of 1850', 'year' => 1850, 'era' => 'crisis', 'value' => 4, 'push' => -1,
      'flavor' => 'Five bills passed separately because no majority existed for all five together.'],
    'fugitive_slave_act' => ['name' => 'The Fugitive Slave Act', 'year' => 1850, 'era' => 'crisis', 'value' => 4, 'push' => -1,
      'flavor' => 'Federal power used against the North on behalf of the South, and it radicalised both.'],
    'uncle_toms_cabin' => ['name' => 'Uncle Tom\'s Cabin', 'year' => 1852, 'era' => 'crisis', 'value' => 6, 'push' => 2,
      'flavor' => 'Three hundred thousand copies in a year. The best-selling argument any of you ever printed.'],
    'kansas_nebraska' => ['name' => 'The Kansas-Nebraska Act', 'year' => 1854, 'era' => 'crisis', 'value' => 4, 'push' => -2,
      'flavor' => 'Douglas repealed the Missouri line to get his railroad, and detonated the party system.'],
    'bleeding_kansas' => ['name' => 'Bleeding Kansas', 'year' => 1856, 'era' => 'crisis', 'value' => 5, 'push' => -1,
      'flavor' => 'Two territorial governments, two constitutions, and a body count. Circulation soared.'],
    'sumner_caning' => ['name' => 'The Caning of Charles Sumner', 'year' => 1856, 'era' => 'crisis', 'value' => 5, 'push' => 1,
      'flavor' => 'Beaten unconscious on the Senate floor. The South sent Brooks replacement canes.'],
    'dred_scott' => ['name' => 'Dred Scott v. Sandford', 'year' => 1857, 'era' => 'crisis', 'value' => 4, 'push' => -2,
      'flavor' => 'No rights which the white man was bound to respect, and Congress powerless in the territories.'],
    'lincoln_douglas' => ['name' => 'The Lincoln-Douglas Debates', 'year' => 1858, 'era' => 'crisis', 'value' => 5, 'push' => 1,
      'flavor' => 'Seven towns, three hours each, printed verbatim in every paper in the country. Including yours.'],
    'john_browns_raid' => ['name' => 'John Brown at Harpers Ferry', 'year' => 1859, 'era' => 'crisis', 'value' => 5, 'push' => -1,
      'flavor' => 'A martyr or a murderer, and no paper in the country could avoid choosing.'],
    'personal_liberty_laws' => ['name' => 'The Personal Liberty Laws', 'year' => 1855, 'era' => 'crisis', 'value' => 4, 'push' => -1,
      'flavor' => 'Northern states nullifying a federal statute, which the South noticed with interest.'],
    'ableman_v_booth' => ['name' => 'Ableman v. Booth', 'year' => 1859, 'era' => 'crisis', 'value' => 3, 'push' => 2,
      'flavor' => 'Taney told Wisconsin that no state court could overrule a federal one.'],
    'georgia_platform' => ['name' => 'The Georgia Platform', 'year' => 1850, 'era' => 'crisis', 'value' => 3, 'push' => -1,
      'flavor' => 'Georgia would accept the Compromise, and named the conditions under which it would not.'],
    'nashville_convention' => ['name' => 'The Nashville Convention', 'year' => 1850, 'era' => 'crisis', 'value' => 3, 'push' => -2,
      'flavor' => 'Nine states met to consider what the South would do if the territories were closed.'],
    'lecompton_constitution' => ['name' => 'The Lecompton Constitution', 'year' => 1857, 'era' => 'crisis', 'value' => 4, 'push' => -2,
      'flavor' => 'A proslavery constitution written by a minority, which split the Democratic party in two.'],
    'freeport_doctrine' => ['name' => 'The Freeport Doctrine', 'year' => 1858, 'era' => 'crisis', 'value' => 4, 'push' => -1,
      'flavor' => 'Douglas held that a territory could exclude slavery by simply declining to police it. It cost him the South.'],
  ];
}

/** One race by space (1-based), or null. */
function vg2_election_at($space) {
  foreach (vg2_elections() as $e) {
    if ((int) $e['space'] === (int) $space) return $e;
  }
  return null;
}

/** One card by key, or null. */
function vg2_card($key) {
  $cards = vg2_cards();
  return isset($cards[$key]) ? $cards[$key] : null;
}

/** Card keys of one era. */
function vg2_cards_in_era($era) {
  $out = [];
  foreach (vg2_cards() as $key => $card) {
    if ($card['era'] === $era) $out[] = $key;
  }
  return $out;
}
