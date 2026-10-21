"""Self-test for the design-token sync gate. Run: python3 -m unittest discover -s scripts/quality/tests"""
import contextlib
import importlib.util
import io
import sys
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "dts", Path(__file__).resolve().parents[1] / "check_design_tokens_sync.py")
dts = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dts)


class ParsingTest(unittest.TestCase):
    def test_selector_list_defines_every_theme_it_names(self):
        self.assertEqual(dts.themes_of(':root, [data-theme="dark"]'), {"light", "dark"})
        self.assertEqual(dts.themes_of('html[data-theme="dark"]'), {"dark"})
        self.assertEqual(dts.themes_of(".card"), set())
        self.assertEqual(dts.themes_of("@media print"), set())

    def test_normalize_treats_rem_as_px_and_ignores_spacing(self):
        self.assertEqual(dts.normalize("0.25rem"), "4px")
        self.assertEqual(dts.normalize("rgba( 1 , 2,3 )"), "rgba(1,2,3)")
        self.assertEqual(dts.normalize("#ABCDEF"), "#abcdef")

    def test_parse_ignores_comments_and_keeps_first_definition(self):
        css = "/* :root { --a: 9px; } */ :root { --a: 1px; } :root { --a: 2px; } [data-theme=\"dark\"] { --a: 3px; }"
        self.assertEqual(dts.parse(css), {"light": {"--a": "1px"}, "dark": {"--a": "3px"}})


class GateTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        root = Path(self._tmp.name)
        self.snap = root / "artifact"
        self.styles = root / "styles"
        self.snap.mkdir()
        self.styles.mkdir()
        self._orig = (dts.SNAPSHOT_FILES, dts.STYLES)
        dts.SNAPSHOT_FILES = [self.snap / "tokens.css", self.snap / "fairy-aurora.css"]
        dts.STYLES = self.styles
        (self.snap / "fairy-aurora.css").write_text("", encoding="utf-8")

    def tearDown(self):
        dts.SNAPSHOT_FILES, dts.STYLES = self._orig
        self._tmp.cleanup()

    def artifact(self, css: str) -> None:
        (self.snap / "tokens.css").write_text(css, encoding="utf-8")

    def style(self, name: str, css: str) -> None:
        (self.styles / name).write_text(css, encoding="utf-8")

    def run_gate(self, *argv: str) -> tuple[int, str]:
        out = io.StringIO()
        old_argv = sys.argv
        sys.argv = ["check_design_tokens_sync.py", *argv]
        try:
            with contextlib.redirect_stdout(out):
                code = dts.main()
        finally:
            sys.argv = old_argv
        return code, out.getvalue()

    def test_matching_tokens_pass(self):
        self.artifact(":root { --space: 0.25rem; }")
        self.style("fairy-aurora.css", ":root { --space: 4px; }")
        self.assertEqual(self.run_gate()[0], 0)

    def test_missing_and_differing_tokens_fail(self):
        self.artifact(":root { --a: 1px; --b: 2px; }")
        self.style("fairy-aurora.css", ":root { --a: 9px; }")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("--a: differs", out)
        self.assertIn("--b: missing", out)

    def test_stale_second_definition_does_not_satisfy_the_canon_file(self):
        self.artifact(":root { --a: 1px; }")
        self.style("fairy-aurora.css", ":root { --a: 9px; }")
        self.style("other.css", ":root { --a: 1px; }")
        self.assertEqual(self.run_gate()[0], 1)

    def test_token_outside_the_canon_file_may_match_any_stylesheet(self):
        self.artifact(":root { --a: 1px; }")
        self.style("fairy-aurora.css", ":root { --x: 0; }")
        self.style("other.css", ":root { --a: 1px; }")
        self.assertEqual(self.run_gate()[0], 0)

    def test_print_stylesheet_is_never_a_theme_definition(self):
        self.artifact(":root { --a: 1px; }")
        self.style("fairy-aurora-print.css", ':root, html[data-theme="dark"] { --a: 1px; }')
        self.assertEqual(self.run_gate()[0], 1)

    def test_dark_inherits_light_when_the_app_has_no_dark_value(self):
        self.artifact(':root { --a: 1px; } [data-theme="dark"] { --a: 1px; --b: 5px; }')
        self.style("fairy-aurora.css", ":root { --a: 1px; --b: 2px; }")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("[dark] --b: differs", out)
        self.assertNotIn("--a", out)

    def test_baseline_tolerates_known_deviation(self):
        self.artifact(":root { --a: 1px; }")
        self.style("fairy-aurora.css", ":root { --a: 9px; }")
        baseline = Path(self._tmp.name) / "baseline.txt"
        baseline.write_text("light:--a:differs\n", encoding="utf-8")
        self.assertEqual(self.run_gate("--baseline", str(baseline))[0], 0)

    def test_missing_snapshot_is_an_io_error(self):
        (self.snap / "fairy-aurora.css").unlink()
        self.artifact(":root { --a: 1px; }")
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            self.assertEqual(self.run_gate()[0], 2)


if __name__ == "__main__":
    unittest.main()
