"""Self-test for Gate 68 (EntityManager reset only via EntityManagerRecovery).
Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "cemr", Path(__file__).resolve().parents[1] / "check_entity_manager_reset.py")
cemr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cemr)


class EntityManagerResetGateTest(unittest.TestCase):
    def test_bare_call_is_flagged(self):
        src = "<?php\nclass A {\n  function f() {\n    $this->managerRegistry->resetManager();\n  }\n}\n"
        self.assertEqual(cemr.scan_text(src), [4])

    def test_call_with_manager_name_and_spacing_is_flagged(self):
        self.assertEqual(cemr.scan_text("<?php\n$registry->resetManager ('default');\n"), [2])

    def test_comments_are_ignored(self):
        src = (
            "<?php\n"
            "// never call resetManager() directly\n"
            "/**\n * see ManagerRegistry::resetManager()\n */\n"
            "# resetManager() in a hash comment\n"
            "$x = 1; // trailing resetManager() note\n"
        )
        self.assertEqual(cemr.scan_text(src), [])

    def test_attribute_line_is_not_treated_as_comment(self):
        self.assertEqual(cemr.scan_text("<?php\n#[Foo] $r->resetManager();\n"), [2])

    def test_helper_itself_is_allowed_and_repo_is_clean(self):
        self.assertIn("src/Doctrine/EntityManagerRecovery.php", cemr.ALLOWED)
        self.assertEqual(cemr.scan(cemr.ROOT), [], "no bare resetManager() in src/")


if __name__ == "__main__":
    unittest.main()
