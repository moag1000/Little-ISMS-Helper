"""Self-test for Gate 92 (check_raw_output.py): raw output, component slots, DOCX, Markup.

Run: python3 -m unittest discover -s scripts/quality/tests
"""
import importlib.util
import tempfile
import unittest
from pathlib import Path

QUALITY = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, QUALITY / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


gate = load("check_raw_output")


def r1(twig: str, allow: dict | None = None) -> list[str]:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        f = root / "templates" / "x.html.twig"
        f.parent.mkdir(parents=True)
        f.write_text(twig, encoding="utf-8")
        old = gate.ROOT
        gate.ROOT = root
        try:
            return gate.check_r1([f], allow or {}, set())
        finally:
            gate.ROOT = old


def r2(twig: str, html_keys: set[str] | None = None) -> list[str]:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        f = root / "templates" / "x.html.twig"
        f.parent.mkdir(parents=True)
        f.write_text(twig, encoding="utf-8")
        old = gate.ROOT
        gate.ROOT = root
        try:
            return gate.check_r2([f], html_keys or set(), {}, set())
        finally:
            gate.ROOT = old


ALERT = "{% import '_components/_fa_alert.html.twig' as _fa_alert %}"


class RawOutputTest(unittest.TestCase):
    def test_component_slot_with_raw_fails(self):
        self.assertTrue(r1("<p>{{ props.body|raw }}</p>"))

    def test_trans_with_record_parameter_fails_until_escaped(self):
        bad = "{{ 'user.delete'|trans({'%name%': user.firstName ~ ' ' ~ user.lastName}, 'user')|raw }}"
        self.assertIn("not escaped", r1(bad)[0])
        good = "{{ 'user.delete'|trans({'%name%': (user.firstName ~ ' ' ~ user.lastName)|e}, 'user')|raw }}"
        self.assertEqual([], r1(good))

    def test_trans_without_parameters_and_counts_pass(self):
        self.assertEqual([], r1("{{ 'a.b'|trans({}, 'x')|raw }}"))
        self.assertEqual([], r1("{{ 'a.b'|trans({'%count%': items|length}, 'x')|raw }}"))
        self.assertEqual([], r1("{{ 'a.b'|trans({'%t%': '<strong>' ~ name|e ~ '</strong>'}, 'x')|raw }}"))

    def test_dynamic_translation_key_needs_allowlist(self):
        self.assertTrue(r1("{{ ('a.' ~ x)|trans|raw }}"))

    def test_json_encode_raw_points_to_fa_json(self):
        self.assertIn("fa_json", r1("<script>const d = {{ rows|json_encode|raw }};</script>")[0])

    def test_raw_on_macro_output_is_redundant(self):
        self.assertIn("macro output", r1("{% import 'm.html.twig' as m %}{{ m.render({a: 1})|raw }}")[0])

    def test_allowlisted_expression_passes(self):
        self.assertEqual([], r1("<title>{{ _page_title|raw }}</title>", {("templates/x.html.twig", "_page_title"): "why"}))

    def test_fa_html_is_treated_like_raw(self):
        self.assertTrue(r1("{{ x.body|fa_html }}"))

    def test_comments_are_ignored(self):
        self.assertEqual([], r1("{# {{ body|raw }} #}"))


class ComponentSlotTest(unittest.TestCase):
    def test_html_literal_in_slot_fails(self):
        twig = ALERT + "{{ _fa_alert.render({ body: '<strong>' ~ 'a.b'|trans ~ '</strong> ' ~ breach.reason }) }}"
        self.assertIn("HTML string literal", r2(twig)[0])

    def test_capture_passes(self):
        twig = ALERT + "{% set b %}<strong>{{ x }}</strong>{% endset %}{{ _fa_alert.render({ body: b }) }}"
        self.assertEqual([], r2(twig))

    def test_html_translation_needs_fa_html(self):
        twig = ALERT + "{{ _fa_alert.render({ body: 'help.hint'|trans({}, 'help') }) }}"
        self.assertIn("contains HTML", r2(twig, {"help.hint"})[0])
        ok = ALERT + "{{ _fa_alert.render({ body: 'help.hint'|trans({}, 'help')|fa_html }) }}"
        self.assertEqual([], r2(ok, {"help.hint"}))

    def test_string_variable_with_markup_fails(self):
        twig = ALERT + "{% set b = '<p>' ~ x ~ '</p>' %}{{ _fa_alert.render({ body: b }) }}"
        self.assertIn("variable b", r2(twig)[0])

    def test_rows_built_with_markup_fail(self):
        twig = ("{% import '_components/_fa_report_doc.html.twig' as d %}{% set rows = [] %}"
                "{% for r in rs %}{% set rows = rows|merge([[ '<code>' ~ r.id ~ '</code>' ]]) %}{% endfor %}"
                "{{ d.render({ sections: [{ table: { rows: rows } }] }) }}")
        self.assertTrue(r2(twig))

    def test_pre_escaped_value_is_double_escaped(self):
        twig = ALERT + "{{ _fa_alert.render({ body: message|fa_flash|e }) }}"
        self.assertIn("escapes it again", r2(twig)[0])

    def test_attribute_maps_are_not_slots(self):
        twig = "{% import '_components/_fa_filter_chip.html.twig' as c %}{{ c.render({ attrs: { 'data-x': '<y>' } }) }}"
        self.assertEqual([], r2(twig))

    def test_backslash_string_does_not_confuse_the_parser(self):
        twig = "{% import '_macros/workflow.html.twig' as w %}{{ w.modal(1, (t|split('\\\\')|last) ~ ' #' ~ id) }}"
        self.assertEqual([], r2(twig))


class PhpRulesTest(unittest.TestCase):
    def run_r3(self, php: str, name: str = "src/Service/X.php") -> list[str]:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            f = root / name
            f.parent.mkdir(parents=True)
            f.write_text(php, encoding="utf-8")
            old = gate.ROOT
            gate.ROOT = root
            try:
                return gate.check_r3()
            finally:
                gate.ROOT = old

    def test_phpword_outside_factory_fails(self):
        self.assertTrue(self.run_r3("<?php $w = new PhpWord();"))
        self.assertEqual([], self.run_r3("<?php return new PhpWord();", gate.DOCX_FACTORY))

    def test_escaping_off_and_template_processor_fail(self):
        self.assertTrue(self.run_r3("<?php Settings::setOutputEscapingEnabled(false);"))
        self.assertTrue(self.run_r3("<?php $t = new TemplateProcessor($f);"))

    def test_markup_needs_reason(self):
        self.assertTrue(self.run_r3("<?php return new Markup($html, 'UTF-8');"))
        ok = "<?php\n// @markup-safe: renderer escapes text first\nreturn new Markup($html, 'UTF-8');"
        self.assertEqual([], self.run_r3(ok))


if __name__ == "__main__":
    unittest.main()
