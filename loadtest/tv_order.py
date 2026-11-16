"""Replays the tv-snapshots.log files of a load-test artifact set the way a TV screen would show them.

    python loadtest/tv_order.py DIR

DIR holds one folder per run (for example s-on-1) with a tv-snapshots.log inside and, when the run
wrote one, a checker.json. For each client the log is replayed through the TV's accept rule, the
same one as pickSnapshot in resources/js/lib/display-version.ts and loadtest/k6/viewer.js: a
pushed snapshot is displayed only if its version is strictly newer than the one on screen.
Reported per run and summed per cell:

- displayed: snapshots the TV would have put on screen
- stale_displayed: displayed snapshots that take the screen backwards (same rule as viewer.js): a
  code already displayed as gone is back on a counter, or the recent calls did not only gain
  entries at the head
- same_version_diff: arrivals whose version was seen before with different content
- final_mismatch / final_checked: clients whose last displayed content differs from the database
  at the end. The database state comes from checker.json ("displays", written by
  antre:loadtest-check --json) or, when the log has FINAL lines from the k6 client, from those.
  Without either, the check is skipped for the run.
- raw_regressions: informational only. Snapshots that arrived with a lower version than an
  earlier arrival, before the accept rule. Reordered delivery counts here and is harmless.
"""
import glob
import json
import os
import sys
from collections import defaultdict

KEYS = ("arrived", "displayed", "stale_displayed", "same_version_diff", "final_mismatch", "final_checked", "raw_regressions")


def load(path):
    snaps, finals = [], []
    with open(path) as f:
        for line in f:
            if line.startswith("SNAPSHOT "):
                snaps.append(json.loads(line[9:]))
            elif line.startswith("FINAL "):
                finals.append(json.loads(line[6:]))
    return snaps, finals


def content_of(counters, recent):
    return json.dumps([counters, recent])


def recent_advances(previous, new):
    if not previous:
        return True
    for k in range(len(new)):
        tail = new[k:]
        if tail == previous[: len(tail)]:
            return True
    return False


def replay(rows):
    """One client's arrivals in order. Returns its tallies and the content left on screen."""
    shown = None  # (version, content)
    seen_at = {}
    finished = set()
    prev_codes, prev_recent = [], []
    t = dict.fromkeys(KEYS, 0)
    highest = None
    for s in rows:
        content = content_of(s["counters"], s["recent"])
        t["arrived"] += 1
        if highest is not None and s["version"] < highest:
            t["raw_regressions"] += 1
        highest = s["version"] if highest is None else max(highest, s["version"])
        if s["version"] in seen_at:
            if seen_at[s["version"]] != content:
                t["same_version_diff"] += 1
        else:
            seen_at[s["version"]] = content
        if shown is None or s["version"] > shown[0]:
            codes = [c for c in s["counters"] if c]
            if shown is not None:
                if any(c in finished for c in codes) or not recent_advances(prev_recent, s["recent"]):
                    t["stale_displayed"] += 1
                finished.update(c for c in prev_codes if c not in codes)
            shown = (s["version"], content)
            prev_codes, prev_recent = codes, s["recent"]
            t["displayed"] += 1
    return t, shown


def analyse(run_dir):
    snaps, finals = load(os.path.join(run_dir, "tv-snapshots.log"))
    by_client = defaultdict(list)
    for s in snaps:
        by_client[s["vu"]].append(s)
    truth = None
    checker = os.path.join(run_dir, "checker.json")
    if os.path.exists(checker):
        with open(checker) as f:
            truth = json.load(f).get("displays")

    out = dict.fromkeys(KEYS, 0)
    out["clients"] = len(by_client)
    for vu, rows in by_client.items():
        t, shown = replay(rows)
        for k in KEYS:
            out[k] += t[k]
        if truth is not None and shown is not None:
            db = truth.get(str(rows[0]["branch"]))
            if db is not None:
                out["final_checked"] += 1
                if shown[1] != content_of(db["counters"], db["recent"]):
                    out["final_mismatch"] += 1
    if truth is None and finals:
        out["final_checked"] = len(finals)
        out["final_mismatch"] = sum(1 for f in finals if not f["same"])
    return out


def main():
    if len(sys.argv) != 2 or sys.argv[1].startswith("--"):
        sys.exit(__doc__)
    totals = defaultdict(lambda: defaultdict(int))
    for log in sorted(glob.glob(os.path.join(sys.argv[1], "*", "tv-snapshots.log"))):
        run_dir = os.path.dirname(log)
        r = analyse(run_dir)
        if not r["clients"]:
            continue
        cell = os.path.basename(run_dir).rsplit("-", 1)[0]
        for k in KEYS:
            totals[cell][k] += r[k]
        print(os.path.basename(run_dir), r)
    print()
    for cell, t in sorted(totals.items()):
        print(cell + ": " + " ".join(f"{k}={t[k]}" for k in KEYS))


if __name__ == "__main__":
    main()
