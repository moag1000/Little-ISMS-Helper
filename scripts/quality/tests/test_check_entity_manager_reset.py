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

    def test_call_split_across_lines_is_flagged(self):
        self.assertEqual(cemr.scan_text("<?php\n$this->managerRegistry\n    ->resetManager\n    ();\n"), [3])

    def test_comment_markers_inside_strings_do_not_hide_code(self):
        self.assertEqual(cemr.scan_text("<?php\n$x = sprintf('#%d', $id); $r->resetManager();\n"), [2])
        self.assertEqual(cemr.scan_text("<?php\n$u = 'http://x.test'; $r->resetManager();\n"), [2])
        self.assertEqual(cemr.scan_text('<?php\n$u = "a // b"; $r->resetManager();\n'), [2])

    def test_strings_and_comments_mentioning_it_are_ignored(self):
        src = (
            "<?php\n"
            "// never call resetManager() directly\n"
            "/**\n * see ManagerRegistry::resetManager()\n */\n"
            "# resetManager() in a hash comment\n"
            "$msg = 'do not use resetManager() here';\n"
            "$x = 1; // trailing resetManager() note\n"
        )
        self.assertEqual(cemr.scan_text(src), [])

    def test_attribute_line_is_not_treated_as_comment(self):
        self.assertEqual(cemr.scan_text("<?php\n#[Foo] $r->resetManager();\n"), [2])

    def test_registry_reset_is_flagged(self):
        self.assertEqual(cemr.scan_text("<?php\n$this->managerRegistry->reset();\n"), [2])
        self.assertEqual(cemr.scan_text("<?php\n$this->registry?->reset();\n"), [2])
        self.assertEqual(cemr.scan_text("<?php\n$doctrine->reset();\n"), [2])
        self.assertEqual(cemr.scan_text("<?php\n$this->container->get('doctrine')->reset();\n"), [2])
        self.assertEqual(cemr.scan_text("<?php\n$this->getDoctrine()->reset();\n"), [2])

    def test_other_resets_are_fine(self):
        src = "<?php\n$this->tenantContext->reset();\n$filters->restore('tenant_filter');\n$this->listener->reset();\n"
        self.assertEqual(cemr.scan_text(src), [])

    def test_helper_itself_is_allowed_and_repo_is_clean(self):
        self.assertIn("src/Doctrine/EntityManagerRecovery.php", cemr.ALLOWED)
        self.assertEqual(cemr.scan(cemr.ROOT), [], "no bare EntityManager reset in src/")


if __name__ == "__main__":
    unittest.main()
