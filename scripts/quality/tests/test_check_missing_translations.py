"""Self-test for the translation-quality gate (Gate 6). Run: python3 -m unittest discover -s scripts/quality/tests"""
import contextlib
import importlib.util
import io
import os
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "cmt", Path(__file__).resolve().parents[1] / "check_missing_translations.py")
cmt = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cmt)


class MissingTranslationsTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self._tmp.name)
        self._orig = cmt.TRANSLATIONS_DIR
        cmt.TRANSLATIONS_DIR = self.dir
        self._targeted = os.environ.pop("TARGETED", None)

    def tearDown(self):
        cmt.TRANSLATIONS_DIR = self._orig
        if self._targeted is not None:
            os.environ["TARGETED"] = self._targeted
        self._tmp.cleanup()

    def write(self, name: str, body: str) -> None:
        (self.dir / name).write_text(body, encoding="utf-8")

    def run_gate(self) -> tuple[int, str]:
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = cmt.main()
        return code, out.getvalue()

    def test_identical_key_sets_pass(self):
        self.write("demo.de.yaml", "demo:\n  title: Titel\n  count: '%count% Einträge'\n")
        self.write("demo.en.yaml", "demo:\n  title: Title\n  count: '%count% entries'\n")
        code, out = self.run_gate()
        self.assertEqual(code, 0, out)

    def test_key_missing_in_either_locale_fails(self):
        self.write("demo.de.yaml", "demo:\n  only_de: Nur DE\n")
        self.write("demo.en.yaml", "demo:\n  only_en: Only EN\n")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("missing German translation key", out)
        self.assertIn("missing English translation key", out)
        self.assertIn("demo.only_en", out)
        self.assertIn("demo.only_de", out)

    def test_empty_value_in_one_locale_fails(self):
        self.write("demo.de.yaml", "demo:\n  label: ''\n")
        self.write("demo.en.yaml", "demo:\n  label: Label\n")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("empty value", out)
        self.assertIn("demo.label (de=empty, en=set)", out)

    def test_placeholder_mismatch_fails(self):
        self.write("demo.de.yaml", "demo:\n  risk: 'Stufe %level%'\n")
        self.write("demo.en.yaml", "demo:\n  risk: 'Level %probability%'\n")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("placeholder mismatch", out)

    def test_brace_placeholders_are_compared_too(self):
        self.write("demo.de.yaml", "demo:\n  hi: 'Hallo {name}'\n")
        self.write("demo.en.yaml", "demo:\n  hi: 'Hello {user}'\n")
        self.assertEqual(self.run_gate()[0], 1)

    def test_domain_file_in_one_locale_only_fails(self):
        self.write("lonely.de.yaml", "lonely:\n  a: A\n")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("only lonely.de.yaml exists", out)

    def test_extract_stub_fails(self):
        self.write("demo.de.yaml", "demo:\n  a: __demo.a\n")
        self.write("demo.en.yaml", "demo:\n  a: A\n")
        code, out = self.run_gate()
        self.assertEqual(code, 1)
        self.assertIn("extract stub", out)

    def test_messages_domain_is_no_longer_skipped(self):
        self.write("messages.de.yaml", "common:\n  a: A\n")
        self.write("messages.en.yaml", "common:\n  b: B\n")
        self.assertEqual(self.run_gate()[0], 1)


if __name__ == "__main__":
    unittest.main()
