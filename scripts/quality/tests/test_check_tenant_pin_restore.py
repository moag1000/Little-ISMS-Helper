"""Self-test for Gate 97 (organisation pins are put back, S23).
Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "ctpr", Path(__file__).resolve().parents[1] / "check_tenant_pin_restore.py")
ctpr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ctpr)


def lines(src):
    return [line for line, _ in ctpr.scan_text(src)]


class TenantPinRestoreGateTest(unittest.TestCase):
    def test_bare_pin_is_flagged(self):
        src = "<?php\nclass A {\n  function f($t) {\n    $this->tenantContext->setCurrentTenantById($t);\n  }\n}\n"
        self.assertEqual(lines(src), [4])

    def test_pin_in_try_without_finally_is_flagged(self):
        src = ("<?php\nfunction f($t) {\n  try {\n    $this->tenantContext->setCurrentTenant($t);\n"
               "  } catch (\\Exception $e) {\n    log($e);\n  }\n}\n")
        self.assertEqual(lines(src), [4])

    def test_pin_in_try_with_restoring_finally_passes(self):
        src = ("<?php\nfunction f($t) {\n  $restore = $this->tenantContext->capture();\n  try {\n"
               "    foreach ($ts as $t) { $this->tenantContext->setCurrentTenant($t); }\n"
               "  } catch (\\Exception $e) {\n  } finally {\n    $restore();\n  }\n}\n")
        self.assertEqual(lines(src), [])

    def test_finally_that_restores_nothing_is_flagged(self):
        src = ("<?php\nfunction f($t) {\n  try {\n    $this->tenantContext->setNoOrganisation();\n"
               "  } finally {\n    $this->logger->info('done');\n  }\n}\n")
        self.assertEqual(lines(src), [4])

    def test_restore_inside_finally_passes(self):
        src = ("<?php\nfunction f($t) {\n  try {\n    $this->tenantContext->setCurrentTenant($t);\n"
               "  } finally {\n    $this->tenantContext->setCurrentTenant($previous);\n  }\n}\n")
        self.assertEqual(lines(src), [])

    def test_try_of_the_caller_does_not_cover_a_closure(self):
        src = ("<?php\nfunction f($t) {\n  try {\n    $fn = function () use ($t) {\n"
               "      $this->tenantContext->setCurrentTenant($t);\n    };\n"
               "  } finally {\n    $this->tenantContext->reset();\n  }\n}\n")
        self.assertEqual(lines(src), [5])

    def test_marker_on_line_or_line_above_passes(self):
        src = ("<?php\n// @tenant-pin-ok: web request only\n$this->tenantContext->setCurrentTenant($t);\n"
               "$this->tenantContext->setCurrentTenantById(1); // @tenant-pin-ok: restores a snapshot\n")
        self.assertEqual(lines(src), [])

    def test_marker_without_reason_does_not_count(self):
        self.assertEqual(lines("<?php\n$this->tenantContext->setCurrentTenant($t); // @tenant-pin-ok:\n"), [2])

    def test_comments_and_strings_are_ignored(self):
        src = "<?php\n// ->setCurrentTenant($x)\n$s = '->setNoOrganisation()';\n"
        self.assertEqual(lines(src), [])

    def test_repository_is_clean(self):
        self.assertEqual(ctpr.check(sorted((ctpr.ROOT / "src").rglob("*.php"))), [])


if __name__ == "__main__":
    unittest.main()
