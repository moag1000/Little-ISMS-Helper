"""Self-test for the nested-forms gate. Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "cnf", Path(__file__).resolve().parents[1] / "check_nested_forms.py")
cnf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cnf)


def lines(src: str) -> list[int]:
    return [ln for ln, _snippet in cnf.scan_text(src)]


class NestedFormsTest(unittest.TestCase):
    def test_literal_form_inside_literal_form(self):
        self.assertEqual(lines('<form>\n<form>\n</form>\n</form>\n'), [2])

    def test_literal_form_inside_form_start_scope(self):
        src = "{{ form_start(form) }}\n{{ form_row(form.name) }}\n<form method=\"post\">\n</form>\n{{ form_end(form) }}\n"
        self.assertEqual(lines(src), [3])

    def test_form_start_inside_literal_form(self):
        self.assertEqual(lines("<form>\n{{ form_start(other) }}\n{{ form_end(other) }}\n</form>\n"), [2])

    def test_whitespace_control_and_tag_variants(self):
        self.assertEqual(lines("{{- form_start(form) -}}\n<form>\n</form>\n{{- form_end(form) -}}\n"), [2])

    def test_full_form_helper_inside_a_form(self):
        self.assertEqual(lines("{{ form_start(a) }}\n{{ form(b) }}\n{{ form_end(a) }}\n"), [2])

    def test_sibling_forms_are_fine(self):
        src = "{{ form_start(form) }}\n{{ form_end(form) }}\n<form>\n</form>\n{{ form(other) }}\n<form></form>\n"
        self.assertEqual(lines(src), [])

    def test_comments_and_verbatim_are_ignored(self):
        src = (
            "{{ form_start(form) }}\n"
            "{# <form> mentioned in a comment, form_start(x) too #}\n"
            "{% verbatim %}<form></form>{% endverbatim %}\n"
            "{{ form_end(form) }}\n"
        )
        self.assertEqual(lines(src), [])

    def test_form_start_name_inside_other_identifier_is_not_a_scope(self):
        # Only the Twig helper call opens a scope, not e.g. a variable named my_form_start.
        self.assertEqual(lines("{{ my_form_start(form) }}\n<form>\n</form>\n"), [])


if __name__ == "__main__":
    unittest.main()
