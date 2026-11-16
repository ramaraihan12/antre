"""python -m unittest loadtest/test_tv_order.py  (from the repo root)"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))
from tv_order import recent_advances, replay  # noqa: E402

DUP = "A-001@Loket 1:recall"


def snap(version, counters, recent):
    return {"version": version, "counters": counters, "recent": recent}


class RecentAdvances(unittest.TestCase):
    def test_new_entries_at_the_head(self):
        self.assertTrue(recent_advances(["a"], ["x", "a"]))
        self.assertTrue(recent_advances(["a", "b", "c"], ["x", "y", "a", "b", "c"]))

    def test_entry_below_the_head_is_not_an_advance(self):
        self.assertFalse(recent_advances(["a", "b", "c"], ["a", "x", "b", "c"]))
        self.assertFalse(recent_advances(["a", "b", "c"], ["b", "a", "c"]))
        self.assertFalse(recent_advances(["a", "b", "c"], ["a", "c"]))

    def test_tail_falls_off_only_when_full(self):
        self.assertTrue(recent_advances(list("abcde"), list("xabcd")))
        self.assertFalse(recent_advances(list("abc"), list("xab")))

    def test_no_overlap_needs_a_full_list(self):
        self.assertTrue(recent_advances(["a", "b"], list("vwxyz")))
        self.assertFalse(recent_advances(["a", "b"], ["v", "w"]))

    def test_second_recall_of_same_ticket(self):
        self.assertTrue(recent_advances([DUP, "b"], [DUP, DUP, "b"]))

    def test_older_duplicate_drops_off_the_tail(self):
        self.assertTrue(recent_advances([DUP, "a", "b", "c", DUP], [DUP, DUP, "a", "b", "c"]))

    def test_full_list_that_is_older_or_reordered(self):
        self.assertFalse(recent_advances(list("fedcb"), list("edcba")))
        self.assertFalse(recent_advances(list("abcde"), list("bacde")))
        self.assertFalse(recent_advances(list("abcde"), list("xbcde")))

    def test_genuinely_older_list(self):
        self.assertFalse(recent_advances([DUP, DUP, "a"], [DUP, "a"]))


class Replay(unittest.TestCase):
    def test_duplicate_recall_is_not_counted_stale(self):
        rows = [snap(1, ["A-001"], [DUP, "b"]), snap(2, ["A-001"], [DUP, DUP, "b"])]
        t, _ = replay(rows)
        self.assertEqual((t["displayed"], t["stale_displayed"]), (2, 0))

    def test_older_list_is_counted_stale(self):
        rows = [snap(1, ["A-001"], [DUP, DUP, "a"]), snap(2, ["A-001"], [DUP, "a"])]
        t, _ = replay(rows)
        self.assertEqual(t["stale_displayed"], 1)

    def test_full_lists_older_version_is_counted_stale(self):
        rows = [snap(1, ["A-001"], list("fedcb")), snap(2, ["A-001"], list("edcba"))]
        t, _ = replay(rows)
        self.assertEqual(t["stale_displayed"], 1)


if __name__ == "__main__":
    unittest.main()
