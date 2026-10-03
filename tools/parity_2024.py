"""Compare bot games on the PHP engine (backend/engine_2024.php) with the
simulator (tools/simulate_2024.py), match-up by match-up.

    py -X utf8 tools/parity_2024.py            # 400 games per match-up
    py -X utf8 tools/parity_2024.py 1000

Needs php on PATH. The two use different random numbers, so the numbers
agree within noise, not exactly; a rule that differs shows as a gap.
"""

import json
import os
import statistics
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import simulate_2024 as sim  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HARNESS = os.path.join(ROOT, "tools", "engine_botgames_2024.php")

MATCHUPS = [
    ["balanced", "balanced"],
    ["balanced", "balanced", "balanced"],
    ["balanced", "balanced", "balanced", "balanced"],
    ["attacker", "balanced", "balanced"],
    ["trump", "harris", "balanced"],
]


def php_games(styles, games, seed):
    out = subprocess.run(["php", HARNESS, str(games), str(seed), ",".join(styles)],
                         capture_output=True, text=True, encoding="utf-8", check=True).stdout
    return [json.loads(line) for line in out.splitlines() if line.strip()]


def summarise_php(games, n):
    wins = [0.0] * n
    for g in games:
        top = max(s["score"] for s in g["seats"])
        leaders = [i for i, s in enumerate(g["seats"]) if s["score"] == top]
        for i in leaders:
            wins[i] += 1 / len(leaders)
    seats = [s for g in games for s in g["seats"]]
    return dict(
        wins=[w / len(games) for w in wins],
        rounds=statistics.mean(g["rounds"] for g in games),
        score=statistics.mean(s["score"] for s in seats),
        called=statistics.mean(s["called"] for s in seats),
        states_bought=statistics.mean(s["states_bought"] for s in seats),
        stories_bought=statistics.mean(s["bought"] - s["states_bought"] for s in seats),
        stakes=statistics.mean(s["stakes"] for s in seats),
        stakes_won=statistics.mean(s["stakes_won"] for s in seats),
        attacks=statistics.mean(s["attacks"] for s in seats),
        harris=sum(1 for g in games if g["winner_side"] == "harris") / len(games),
        unhistorical=sum(g["unhistorical"] for g in games) / max(1, sum(g["claims"] for g in games)),
        finished=sum(1 for g in games if g["ended"] == "race_called") / len(games),
    )


def summarise_sim(styles, games, seed):
    r = sim.run_matchup(list(styles), games, None, seed)
    seats = [x for s in r["stats"] for x in s]
    return dict(
        wins=[w / games for w in r["wins"]],
        rounds=statistics.mean(r["rounds"]),
        score=statistics.mean(x for v in r["vp"] for x in v),
        called=statistics.mean(x["called"] for x in seats),
        states_bought=statistics.mean(x["states_bought"] for x in seats),
        stories_bought=statistics.mean(x["bought"] - x["states_bought"] for x in seats),
        stakes=statistics.mean(x["stakes"] for x in seats),
        stakes_won=statistics.mean(x["stake_won"] for x in seats),
        attacks=statistics.mean(x["attacks"] for x in seats),
        harris=r["harris"],
        unhistorical=r["unhistorical"],
        finished=r["ended"].get("270", 0) / games,
    )


def main():
    games = int(sys.argv[1]) if len(sys.argv) > 1 else 400
    print("%d games per match-up, engine vs simulator\n" % games)
    rows = [("rounds", "%.1f"), ("score", "%.1f"), ("called", "%.2f"), ("states_bought", "%.1f"),
            ("stories_bought", "%.1f"), ("stakes", "%.2f"), ("stakes_won", "%.2f"), ("attacks", "%.1f"),
            ("harris", "%.2f"), ("unhistorical", "%.2f"), ("finished", "%.2f")]
    worst = 0
    for styles in MATCHUPS:
        e = summarise_php(php_games(styles, games, 7), len(styles))
        s = summarise_sim(styles, games, 7)
        print("  " + " vs ".join(styles))
        print("    %-15s %-28s %-28s" % ("", "engine", "simulator"))
        print("    %-15s %-28s %-28s" % ("seat wins", " / ".join("%.0f%%" % (100 * w) for w in e["wins"]),
                                         " / ".join("%.0f%%" % (100 * w) for w in s["wins"])))
        for k, fmt in rows:
            gap = abs(e[k] - s[k]) / max(1e-9, abs(s[k])) if s[k] else abs(e[k])
            flag = "   <-- %.0f%% apart" % (100 * gap) if gap > 0.10 else ""
            print("    %-15s %-28s %-28s%s" % (k, fmt % e[k], fmt % s[k], flag))
        seat_gap = max(abs(a - b) for a, b in zip(e["wins"], s["wins"]))
        worst = max(worst, seat_gap)
        print()
    print("largest seat win-rate gap: %.1f points" % (100 * worst))


if __name__ == "__main__":
    main()
