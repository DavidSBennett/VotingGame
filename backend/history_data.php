<?php
/**
 * history_data.php — the board: 14 presidential spaces, two candidates each.
 *
 * CONTENT, not rules. The engine reads this; it never writes it. Kept in
 * code rather than the database on purpose — the board is authored
 * material that should move with the commit that changed it, and a
 * candidate stance is a design decision worth showing up in a diff.
 *
 * ---------------------------------------------------------------------
 * WHY 14 SPACES AND NOT 17
 *
 * 1796 to 1860 inclusive is seventeen presidential elections. The brief
 * asks for fourteen spaces, so three come out. The three dropped are the
 * three that were not contests:
 *
 *   1804  Jefferson over C.C. Pinckney, 162-14
 *   1816  Monroe over Rufus King, 183-34
 *   1820  Monroe unopposed (a single dissenting electoral vote)
 *
 * That keeps both endpoints named in the brief — 1796 Adams/Jefferson and
 * 1860 — and every remaining space is a race a media apparatus could
 * plausibly have swung. The alternative cuts (trimming the 1850s, or
 * starting at 1808) either lose the founding contest or lose the
 * sectional crisis the endgame is built around.
 * ---------------------------------------------------------------------
 *
 * STANCES ARE A FIRST DRAFT. Every number below is an interpretation, and
 * interpretations are the part of this file most worth arguing with.
 * They are deliberately coarse — a seven-point scale, not a model — so
 * that changing one is cheap and no single number carries much weight.
 *
 * Two triples per candidate, because the transition cards can fire at any
 * point and an election has to resolve under whichever regime is live:
 *
 *   stance_early  market  -3 bound to European commerce   +3 self-sufficiency
 *                 tariff  -3 revenue only                 +3 high protection
 *                 federal -3 strict construction          +3 broad national power
 *
 *   stance_late   expansion -3 continental restraint      +3 Manifest Destiny
 *                 slavery -3 restriction                  +3 protection and expansion
 *                 states  -3 national supremacy           +3 state sovereignty
 */

/** The three issue tracks, before and after the transition. */
function vg_issue_tracks() {
  return [
    'early' => [
      'market'  => ['name' => 'Independence from European Markets',
                    'low'  => 'Bound to Atlantic commerce',
                    'high' => 'Economic self-sufficiency'],
      'tariff'  => ['name' => 'Tariffs',
                    'low'  => 'Revenue only',
                    'high' => 'High protection'],
      'federal' => ['name' => 'Federal Power',
                    'low'  => 'Strict construction',
                    'high' => 'Broad national authority'],
    ],
    'late' => [
      'expansion' => ['name' => 'Expansion',
                    'low'  => 'Continental restraint',
                    'high' => 'Manifest Destiny'],
      'slavery' => ['name' => 'Slavery',
                    'low'  => 'Restriction',
                    'high' => 'Protection and expansion'],
      'states'  => ['name' => 'States Rights',
                    'low'  => 'National supremacy',
                    'high' => 'State sovereignty'],
    ],
  ];
}

/**
 * The fourteen spaces, in order. space 1 is the first election played.
 *
 * historical_winner is recorded for flavour and for the playtest report
 * ("the board elected Clay in 1844") — it confers nothing mechanically.
 * The whole premise is that these outcomes were contingent.
 */
function vg_elections() {
  return [

    ['space' => 1, 'year' => 1796, 'historical_winner' => 'adams_1796',
     'margin' => '71-68',
     'note' => 'The first contested election. Adams carried New England and the commercial seaboard.',
     'candidates' => [
       ['key' => 'adams_1796', 'name' => 'John Adams', 'party' => 'Federalist',
        'stance_early' => ['market' => -2, 'tariff' => 1, 'federal' => 3],
        'stance_late'  => ['expansion' => -2, 'slavery' => -1, 'states' => -3],
        'note' => 'Atlantic commerce, a funded debt, and a strong executive.'],
       ['key' => 'jefferson_1796', 'name' => 'Thomas Jefferson', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 2, 'tariff' => -2, 'federal' => -3],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 3],
        'note' => 'An agrarian republic, free trade, and a government of enumerated powers.'],
     ]],

    ['space' => 2, 'year' => 1800, 'historical_winner' => 'jefferson_1800',
     'margin' => '73-65',
     'note' => 'The Revolution of 1800. A tie with Burr threw it to the House for thirty-six ballots.',
     'candidates' => [
       ['key' => 'jefferson_1800', 'name' => 'Thomas Jefferson', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 2, 'tariff' => -2, 'federal' => -3],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 3],
        'note' => 'Ran against the Alien and Sedition Acts and the standing army that paid for them.'],
       ['key' => 'adams_1800', 'name' => 'John Adams', 'party' => 'Federalist',
        'stance_early' => ['market' => -2, 'tariff' => 1, 'federal' => 3],
        'stance_late'  => ['expansion' => -2, 'slavery' => -1, 'states' => -3],
        'note' => 'Peace with France cost him his own party.'],
     ]],

    ['space' => 3, 'year' => 1808, 'historical_winner' => 'madison_1808',
     'margin' => '122-47',
     'note' => 'Fought over the Embargo, which had wrecked New England shipping.',
     'candidates' => [
       ['key' => 'madison_1808', 'name' => 'James Madison', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 3, 'tariff' => -1, 'federal' => -2],
        'stance_late'  => ['expansion' => 2, 'slavery' => 1, 'states' => 1],
        'note' => 'Architect of commercial coercion as an alternative to war.'],
       ['key' => 'pinckney_1808', 'name' => 'Charles C. Pinckney', 'party' => 'Federalist',
        'stance_early' => ['market' => -3, 'tariff' => 1, 'federal' => 2],
        'stance_late'  => ['expansion' => -2, 'slavery' => 1, 'states' => -2],
        'note' => 'A South Carolina Federalist carrying a New England grievance.'],
     ]],

    ['space' => 4, 'year' => 1812, 'historical_winner' => 'madison_1812',
     'margin' => '128-89',
     'note' => 'A wartime election. Clinton ran as the peace candidate on Federalist votes.',
     'candidates' => [
       ['key' => 'madison_1812', 'name' => 'James Madison', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 3, 'tariff' => -1, 'federal' => -1],
        'stance_late'  => ['expansion' => 3, 'slavery' => 1, 'states' => 1],
        'note' => 'War with Britain as the completion of independence.'],
       ['key' => 'clinton_1812', 'name' => 'DeWitt Clinton', 'party' => 'Federalist coalition',
        'stance_early' => ['market' => -3, 'tariff' => 0, 'federal' => 1],
        'stance_late'  => ['expansion' => -2, 'slavery' => -1, 'states' => -1],
        'note' => 'A Republican running on Federalist money to end the war.'],
     ]],

    ['space' => 5, 'year' => 1824, 'historical_winner' => 'jqadams_1824',
     'margin' => 'House, 13 states',
     'note' => 'Jackson led the popular and electoral vote; the House chose Adams. The Corrupt Bargain.',
     'candidates' => [
       ['key' => 'jqadams_1824', 'name' => 'John Quincy Adams', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 0, 'tariff' => 2, 'federal' => 3],
        'stance_late'  => ['expansion' => 2, 'slavery' => -3, 'states' => -3],
        'note' => 'Roads, canals, a national university, and the American System.'],
       ['key' => 'jackson_1824', 'name' => 'Andrew Jackson', 'party' => 'Democratic-Republican',
        'stance_early' => ['market' => 1, 'tariff' => 0, 'federal' => -1],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 1],
        'note' => 'The military hero as the outsider against a Washington arrangement.'],
     ]],

    ['space' => 6, 'year' => 1828, 'historical_winner' => 'jackson_1828',
     'margin' => '178-83',
     'note' => 'The first mass-participation campaign, and the dirtiest so far.',
     'candidates' => [
       ['key' => 'jackson_1828', 'name' => 'Andrew Jackson', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -1, 'federal' => -1],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 1],
        'note' => 'Rotation in office and hostility to concentrated financial power.'],
       ['key' => 'jqadams_1828', 'name' => 'John Quincy Adams', 'party' => 'National Republican',
        'stance_early' => ['market' => 0, 'tariff' => 3, 'federal' => 3],
        'stance_late'  => ['expansion' => 1, 'slavery' => -3, 'states' => -3],
        'note' => 'Defended the Tariff of Abominations he had not written.'],
     ]],

    ['space' => 7, 'year' => 1832, 'historical_winner' => 'jackson_1832',
     'margin' => '219-49',
     'note' => 'A referendum on the Bank of the United States, held as South Carolina moved to nullify.',
     'candidates' => [
       ['key' => 'jackson_1832', 'name' => 'Andrew Jackson', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -1, 'federal' => 0],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 0],
        'note' => 'Killed the Bank, then threatened to hang nullifiers.'],
       ['key' => 'clay_1832', 'name' => 'Henry Clay', 'party' => 'National Republican',
        'stance_early' => ['market' => -1, 'tariff' => 3, 'federal' => 3],
        'stance_late'  => ['expansion' => -1, 'slavery' => -1, 'states' => -1],
        'note' => 'Forced the Bank question early and lost on it.'],
     ]],

    ['space' => 8, 'year' => 1836, 'historical_winner' => 'vanburen_1836',
     'margin' => '170-73',
     'note' => 'The Whigs ran several regional candidates at once and still lost.',
     'candidates' => [
       ['key' => 'vanburen_1836', 'name' => 'Martin Van Buren', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -2, 'federal' => -2],
        'stance_late'  => ['expansion' => 0, 'slavery' => 0, 'states' => -1],
        'note' => 'The party manager who built the machine, inheriting it.'],
       ['key' => 'harrison_1836', 'name' => 'William Henry Harrison', 'party' => 'Whig',
        'stance_early' => ['market' => -1, 'tariff' => 2, 'federal' => 2],
        'stance_late'  => ['expansion' => 1, 'slavery' => 0, 'states' => 0],
        'note' => 'Tippecanoe, tried out as a national candidate for the first time.'],
     ]],

    ['space' => 9, 'year' => 1840, 'historical_winner' => 'harrison_1840',
     'margin' => '234-60',
     'note' => 'Log cabins and hard cider. The Panic of 1837 did the rest.',
     'candidates' => [
       ['key' => 'harrison_1840', 'name' => 'William Henry Harrison', 'party' => 'Whig',
        'stance_early' => ['market' => -1, 'tariff' => 2, 'federal' => 2],
        'stance_late'  => ['expansion' => 1, 'slavery' => 0, 'states' => 0],
        'note' => 'A campaign of image with almost no platform, and it worked.'],
       ['key' => 'vanburen_1840', 'name' => 'Martin Van Buren', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -2, 'federal' => -2],
        'stance_late'  => ['expansion' => 0, 'slavery' => 0, 'states' => -1],
        'note' => 'Van Ruin, blamed for a depression he did not cause.'],
     ]],

    ['space' => 10, 'year' => 1844, 'historical_winner' => 'polk_1844',
     'margin' => '170-105',
     'note' => 'Texas annexation. Clay equivocated and lost New York by five thousand votes.',
     'candidates' => [
       ['key' => 'polk_1844', 'name' => 'James K. Polk', 'party' => 'Democrat',
        'stance_early' => ['market' => 2, 'tariff' => -3, 'federal' => -2],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 1],
        'note' => 'The first dark horse: Texas, Oregon, and a lower tariff.'],
       ['key' => 'clay_1844', 'name' => 'Henry Clay', 'party' => 'Whig',
        'stance_early' => ['market' => -1, 'tariff' => 3, 'federal' => 3],
        'stance_late'  => ['expansion' => -2, 'slavery' => -1, 'states' => -1],
        'note' => 'Tried to hold North and South together on annexation and satisfied neither.'],
     ]],

    ['space' => 11, 'year' => 1848, 'historical_winner' => 'taylor_1848',
     'margin' => '163-127',
     'note' => 'Free Soil took enough of New York to decide it.',
     'candidates' => [
       ['key' => 'taylor_1848', 'name' => 'Zachary Taylor', 'party' => 'Whig',
        'stance_early' => ['market' => 0, 'tariff' => 1, 'federal' => 1],
        'stance_late'  => ['expansion' => 1, 'slavery' => 1, 'states' => -1],
        'note' => 'A Louisiana slaveholder who turned out to be an unbending Unionist.'],
       ['key' => 'cass_1848', 'name' => 'Lewis Cass', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -2, 'federal' => -2],
        'stance_late'  => ['expansion' => 3, 'slavery' => 1, 'states' => 1],
        'note' => 'Invented popular sovereignty as a way of not answering the question.'],
     ]],

    ['space' => 12, 'year' => 1852, 'historical_winner' => 'pierce_1852',
     'margin' => '254-42',
     'note' => 'The Whig party won four states and never contested another election.',
     'candidates' => [
       ['key' => 'pierce_1852', 'name' => 'Franklin Pierce', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -2, 'federal' => -2],
        'stance_late'  => ['expansion' => 3, 'slavery' => 2, 'states' => 2],
        'note' => 'A northern man of southern principles, nominated on the forty-ninth ballot.'],
       ['key' => 'scott_1852', 'name' => 'Winfield Scott', 'party' => 'Whig',
        'stance_early' => ['market' => -1, 'tariff' => 2, 'federal' => 2],
        'stance_late'  => ['expansion' => 0, 'slavery' => -1, 'states' => -1],
        'note' => 'A general the southern Whigs would not run on.'],
     ]],

    ['space' => 13, 'year' => 1856, 'historical_winner' => 'buchanan_1856',
     'margin' => '174-114',
     'note' => 'Bleeding Kansas. A party four years old came second.',
     'candidates' => [
       ['key' => 'buchanan_1856', 'name' => 'James Buchanan', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -2, 'federal' => -1],
        'stance_late'  => ['expansion' => 3, 'slavery' => 3, 'states' => 2],
        'note' => 'Chosen largely because he had been abroad during Kansas-Nebraska.'],
       ['key' => 'fremont_1856', 'name' => 'John C. Fremont', 'party' => 'Republican',
        'stance_early' => ['market' => 0, 'tariff' => 2, 'federal' => 2],
        'stance_late'  => ['expansion' => 2, 'slavery' => -3, 'states' => -3],
        'note' => 'Free soil, free men, and no southern ballot access at all.'],
     ]],

    ['space' => 14, 'year' => 1860, 'historical_winner' => 'lincoln_1860',
     'margin' => '180-72-39-12',
     'note' => 'Actually a four-way race; the board reduces it to the two candidates who defined the question.',
     'candidates' => [
       ['key' => 'lincoln_1860', 'name' => 'Abraham Lincoln', 'party' => 'Republican',
        'stance_early' => ['market' => 0, 'tariff' => 3, 'federal' => 3],
        'stance_late'  => ['expansion' => 0, 'slavery' => -3, 'states' => -3],
        'note' => 'Not one southern state placed him on the ballot.'],
       ['key' => 'douglas_1860', 'name' => 'Stephen A. Douglas', 'party' => 'Democrat',
        'stance_early' => ['market' => 1, 'tariff' => -1, 'federal' => -1],
        'stance_late'  => ['expansion' => 3, 'slavery' => 1, 'states' => 1],
        'note' => 'Campaigned in the South against secession once he knew he had lost.'],
     ]],
  ];
}

/** One space by index (1-based), or null. */
function vg_election_at($space) {
  foreach (vg_elections() as $e) {
    if ((int) $e['space'] === (int) $space) return $e;
  }
  return null;
}

/** One candidate by key, or null. */
function vg_candidate($key) {
  foreach (vg_elections() as $e) {
    foreach ($e['candidates'] as $c) {
      if ($c['key'] === $key) return $c;
    }
  }
  return null;
}
