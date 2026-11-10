"""Self-test for Gate 101 (unbounded loads). Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "ul", Path(__file__).resolve().parents[1] / "check_unbounded_loads.py")
ul = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ul)


class CountTest(unittest.TestCase):
    def test_find_all_counts_unless_marked(self):
        text = "$a = $repo->findAll();\n// @bounded-load: 12 frameworks\n$b = $fw->findAll();\n"
        self.assertEqual(ul.count_text(text, False)["findAll"], 1)

    def test_tenant_find_by_without_limit_counts(self):
        self.assertEqual(ul.count_text("$r->findBy(['tenant' => $t]);", False)["findBy"], 1)
        self.assertEqual(ul.count_text("$r->findBy(['tenant' => $t], ['name' => 'ASC']);", False)["findBy"], 1)

    def test_tenant_find_by_with_limit_or_without_tenant_is_fine(self):
        self.assertEqual(ul.count_text("$r->findBy(['tenant' => $t], ['id' => 'DESC'], 10);", False)["findBy"], 0)
        self.assertEqual(ul.count_text("$r->findBy(['status' => 'open']);", False)["findBy"], 0)

    def test_from_request_only_in_controllers(self):
        text = "ListPage::fromRequest($items, $request, $fn);"
        self.assertEqual(ul.count_text(text, True)["fromRequest"], 1)
        self.assertEqual(ul.count_text(text, False)["fromRequest"], 0)
        self.assertEqual(ul.count_text("ListPage::fromSlice($rows, $total, $request);", True)["fromRequest"], 0)


class RatchetTest(unittest.TestCase):
    BASE = {"src/Controller/A.php": {"findAll": 2, "findBy": 0, "fromRequest": 1}}

    def test_new_file_and_raised_count_fail(self):
        cur = {"src/Controller/A.php": {"findAll": 3, "findBy": 0, "fromRequest": 1},
               "src/Controller/B.php": {"findAll": 1, "findBy": 0, "fromRequest": 0}}
        self.assertEqual(len(ul.violations(cur, self.BASE, False)), 2)

    def test_shrink_must_lower_the_entry(self):
        cur = {"src/Controller/A.php": {"findAll": 1, "findBy": 0, "fromRequest": 1}}
        self.assertEqual(ul.violations(cur, self.BASE, False), [])
        self.assertEqual(len(ul.violations(cur, self.BASE, True)), 1)

    def test_baseline_may_not_grow_against_the_reference(self):
        grown = {"src/Controller/A.php": {"findAll": 3, "findBy": 0, "fromRequest": 1},
                 "src/Controller/B.php": {"findAll": 1, "findBy": 0, "fromRequest": 0}}
        self.assertEqual(len(ul.ratchet_violations(grown, self.BASE)), 2)
        self.assertEqual(ul.ratchet_violations({"src/Controller/A.php": {"findAll": 1, "findBy": 0, "fromRequest": 0}}, self.BASE), [])


if __name__ == "__main__":
    unittest.main()
