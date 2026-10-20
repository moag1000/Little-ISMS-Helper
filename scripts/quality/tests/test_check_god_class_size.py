"""Self-test for the god-class ratchet. Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "gcs", Path(__file__).resolve().parents[1] / "check_god_class_size.py")
gcs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gcs)

REF = {"src/Service/Old.php": (1700, 3)}


class RatchetTest(unittest.TestCase):
    def test_delete_plus_new_file_swap_is_rejected(self):
        # old file deleted, unrelated new file of similar size: no rename reported
        msgs = gcs.ratchet_violations({"src/Service/New.php": (1650, 3)}, REF, {})
        self.assertEqual(len(msgs), 1)
        self.assertIn("ADDED", msgs[0])

    def test_real_rename_not_larger_is_accepted(self):
        renames = {"src/Service/New.php": "src/Service/Old.php"}
        self.assertEqual(gcs.ratchet_violations({"src/Service/New.php": (1650, 3)}, REF, renames), [])

    def test_rename_but_larger_is_rejected(self):
        renames = {"src/Service/New.php": "src/Service/Old.php"}
        self.assertEqual(len(gcs.ratchet_violations({"src/Service/New.php": (1800, 3)}, REF, renames)), 1)

    def test_entry_can_be_inherited_only_once(self):
        renames = {"src/Service/A.php": "src/Service/Old.php", "src/Service/B.php": "src/Service/Old.php"}
        cur = {"src/Service/A.php": (1600, 3), "src/Service/B.php": (1600, 3)}
        self.assertEqual(len(gcs.ratchet_violations(cur, REF, renames)), 1)

    def test_copy_with_old_entry_kept_is_rejected(self):
        renames = {"src/Service/Copy.php": "src/Service/Old.php"}
        cur = {"src/Service/Old.php": (1700, 3), "src/Service/Copy.php": (1700, 3)}
        self.assertEqual(len(gcs.ratchet_violations(cur, REF, renames)), 1)

    def test_raised_entry_rejected(self):
        self.assertEqual(len(gcs.ratchet_violations({"src/Service/Old.php": (1701, 3)}, REF, {})), 1)

    def test_parse_renames(self):
        out = "R087\tsrc/A.php\tsrc/B.php\nM\tsrc/C.php\nC100\tsrc/D.php\tsrc/E.php\nD\tsrc/F.php\n"
        self.assertEqual(gcs.parse_renames(out), {"src/B.php": "src/A.php", "src/E.php": "src/D.php"})


if __name__ == "__main__":
    unittest.main()
