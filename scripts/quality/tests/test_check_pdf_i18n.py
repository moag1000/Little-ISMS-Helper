"""Self-test for Gate 102 (evidence PDFs in one language, W10).
Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "cpi", Path(__file__).resolve().parents[1] / "check_pdf_i18n.py")
cpi = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cpi)


def kinds(src):
    return sorted(kind for _, kind, _ in cpi.findings(src))


class PdfI18nGateTest(unittest.TestCase):
    def test_text_node_is_flagged(self):
        self.assertEqual(kinds("<td>Organization:</td>"), ["text"])

    def test_translated_label_passes(self):
        self.assertEqual(kinds("<td>{{ 'privacy.vvt.organization'|trans({}, 'privacy') }}:</td>"), [])

    def test_neutral_tokens_pass(self):
        self.assertEqual(kinds("<td>ISO 27001 A.5.1 (NIS-2)</td><td>%</td><td>SHA-256:</td>"), [])

    def test_fixed_and_missing_lang_are_flagged(self):
        self.assertEqual(kinds('<html lang="de">'), ["lang"])
        self.assertEqual(kinds("<html>"), ["lang"])
        self.assertEqual(kinds('<html lang="{{ app.request.locale }}">'), ["lang"])
        self.assertEqual(kinds('<html lang="{{ app.locale }}">'), [])

    def test_printed_literal_is_flagged(self):
        self.assertEqual(kinds("<td>{{ a.special ? 'YES' : 'NO' }}</td>"), ["literal", "literal"])
        self.assertEqual(kinds("<td>{{ a.x ?? 'Not specified' }}</td>"), ["literal"])

    def test_keys_comparisons_and_attributes_pass(self):
        self.assertEqual(kinds("<td>{{ ('x.status.' ~ (a.s ?? 'unknown'))|trans({}, 'x') }}</td>"), [])
        self.assertEqual(kinds("<td>{{ a.s == 'Approved' ? 'x.a'|trans({}, 'x') : '—' }}</td>"), [])
        self.assertEqual(kinds('<span class="{{ a.high ? \'High risk\' : \'low\' }}">—</span>'), [])
        self.assertEqual(kinds("<td>{{ created|default('now')|fa_date }}</td>"), [])

    def test_raw_enum_is_flagged(self):
        self.assertEqual(kinds("<td>{{ a.status|upper }}</td>"), ["enum"])
        self.assertEqual(kinds("<td>{{ a.basis|replace({'_': ' '})|title }}</td>"), ["enum"])
        self.assertEqual(kinds("<td>{{ ('x.' ~ a.status)|trans({}, 'x')|upper }}</td>"), [])

    def test_page_counter_text_is_flagged(self):
        self.assertEqual(kinds('<style>@page { content: "Seite " counter(page); }</style>'), ["page"])

    def test_twig_comment_with_markup_is_ignored(self):
        self.assertEqual(kinds("{# Footer: uses an inline <script> placeholder #}\n<p>{{ x }}</p>"), [])


if __name__ == "__main__":
    unittest.main()
