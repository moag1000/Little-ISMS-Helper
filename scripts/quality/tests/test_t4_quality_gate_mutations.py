"""T4 mutation counter-check (B27-B40): the quality gates turn red on the mutations that used to stay green.

One test per mutation, plus a green control next to it so a gate that fails on everything cannot pass.
Each case writes the snippet to a scratch tree and runs the gate's own function against it.

Run: python3 -m unittest discover -s scripts/quality/tests
"""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

QUALITY = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(QUALITY))


def load(name):
    spec = importlib.util.spec_from_file_location(f"t4_{name}", QUALITY / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


bands = load("check_risk_band_literals")
slop = load("check_copy_slop")
legacy = load("check_aurora_legacy_markup")
lists = load("check_list_skeleton")
show = load("check_show_skeleton")
kpi = load("check_kpi_catalog")
semantics = load("check_page_semantics")
stimulus = load("check_stimulus_wiring")
zones = load("check_gdpr_transfer_zones")
sort = load("check_orm_sort_direction")

EU27 = "AT BE BG CY CZ DE DK EE ES FI FR GR HR HU IE IT LT LU LV MT NL PL PT RO SE SI SK".split()


class Tree:
    def __init__(self, files: dict[str, str]):
        self._dir = tempfile.TemporaryDirectory()
        self.root = Path(self._dir.name)
        for rel, text in files.items():
            path = self.root / rel
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8")

    def __enter__(self):
        return self.root

    def __exit__(self, *exc):
        self._dir.cleanup()


def run_quiet(fn, *args):
    import contextlib
    import io
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf):
        code = fn(*args)
    return code, buf.getvalue()


class Gate87RiskBandTest(unittest.TestCase):
    def scan(self, rel: str, text: str):
        with Tree({rel: text}) as root, mock.patch.object(bands, "ROOT", root):
            return bands.scan()

    def php(self, body: str):
        return self.scan("src/Service/X.php", "<?php\n" + body + "\n")

    def test_b27_probability_times_impact_in_a_variable(self):
        self.assertTrue(self.php("$p = $r->getProbability()*$r->getImpact();\nif ($p >= 12) {}"))
        self.assertEqual([], self.php("$p = $r->getProbability()*$r->getImpact();\nif ($p >= 3) {}"))

    def test_b28_in_array_with_band_scores(self):
        self.assertTrue(self.php("if (in_array($r->getRiskScore(), [20,25,24], true)) {}"))
        self.assertEqual([], self.php("if (in_array($r->getRiskScore(), [1,2], true)) {}"))
        self.assertEqual([], self.php("if (in_array($r->getStatus(), [20,25,24], true)) {}"))

    def test_b29_constant_with_a_neutral_name(self):
        self.assertTrue(self.php("class A { const HI = 12;\nfunction f($r) { return $r->getRiskScore() >= self::HI; } }"))
        self.assertTrue(self.php("class A { const HI = 12;\nfunction f($r) { $s = $r->getRiskScore(); return $s >= self::HI; } }"))
        self.assertEqual([], self.php("class A { const HI = 12;\nfunction f($n) { return $n->getCount() >= self::HI; } }"))

    def test_b30_cast_or_quoted_edge(self):
        self.assertTrue(self.php('if ($r->getRiskScore() >= (int) "12") {}'))
        self.assertTrue(self.php("if ($r->getRiskScore() >= intval('12')) {}"))
        self.assertEqual([], self.php('if ($r->getRiskScore() >= (int) "3") {}'))

    def test_b31_twig_range(self):
        self.assertTrue(self.scan("templates/x/a.html.twig", "{% if risk.riskScore in 12..25 %}x{% endif %}"))
        self.assertTrue(self.scan("templates/x/a.html.twig", "{% if risk.riskScore in [20, 25] %}x{% endif %}"))
        self.assertEqual([], self.scan("templates/x/a.html.twig", "{% if risk.riskScore in 1..3 %}x{% endif %}"))
        self.assertEqual([], self.scan("templates/x/a.html.twig", "{% if x.count in 12..25 %}x{% endif %}"))


class Gate71CopySlopTest(unittest.TestCase):
    def found(self, files: dict[str, str]):
        with Tree(files) as root, mock.patch.object(slop, "ROOT", root):
            return slop.find()

    def twig(self, body: str):
        return self.found({"templates/x/a.html.twig": body})

    def test_b32_literal_text_in_a_template(self):
        self.assertTrue(self.twig("<p>Saved successfully!</p>"))
        self.assertTrue(self.twig("<p>Gespeichert!</p>"))
        self.assertTrue(self.twig("<p>Der Export war erfolgreich.</p>"))
        self.assertEqual([], self.twig("<p>Gespeichert.</p>{# Saved successfully! #}<!-- Done! -->"))
        self.assertEqual([], self.twig("<style>.a { color: red !important; }</style><a href=\"https://x.test/a!b\">Link</a>"))

    def test_b32_literal_before_trans_filter(self):
        self.assertTrue(self.twig("{{ \"Gespeichert!\"|trans }}"))
        self.assertEqual([], self.twig("{{ 'common.saved'|trans({}, 'messages') }}"))

    def test_b32_attributes(self):
        self.assertTrue(self.twig('<button title="Saved successfully!">x</button>'))
        self.assertEqual([], self.twig('<button title="Speichern">x</button>'))

    def test_b32_js_string_literals(self):
        self.assertTrue(self.found({"assets/controllers/x_controller.js": 'faToast("Saved successfully!");\n'}))
        self.assertTrue(self.found({"assets/controllers/x_controller.js": "const m = 'Gespeichert!';\n"}))
        self.assertTrue(self.found({"assets/controllers/x_controller.js": "const m = `Erfolgreich ${n}`;\n"}))
        self.assertEqual([], self.found({"assets/controllers/x_controller.js": (
            "// Saved successfully!\n/* Done! */\nconst re = /['\"]!x/;\nconst u = 'https://x.test/a!b';\n"
            "if (!ok && a !== b) { el.style.color = 'red !important'; }\nfaToast(faT('x.saved'));\n")}))

    def test_b32_soft_hyphen_and_fullwidth(self):
        self.assertTrue(self.twig("<p>Erfol\u00adgreich gespeichert</p>"))
        self.assertTrue(self.twig("<p>Gespeichert\uff01</p>"))
        self.assertTrue(self.found({"translations/x.de.yaml": "k: 'Erfol\u00adgreich gespeichert'\n"}))
        self.assertTrue(self.found({"translations/x.de.yaml": "k: 'Gespeichert\uff01'\n"}))
        self.assertEqual([], self.found({"translations/x.de.yaml": "k: 'Gespeichert.'\n"}))


class Gate62LegacyMarkupTest(unittest.TestCase):
    def counts(self, twig: str):
        with Tree({"templates/x/a.html.twig": twig}) as root, mock.patch.object(legacy, "TPL", root / "templates"):
            return legacy.scan()

    def test_b33_class_built_in_a_set(self):
        self.assertTrue(self.counts('{% set c = "btn btn-" ~ tone %}<button class="{{ c }}">x</button>'))
        self.assertTrue(self.counts("{% set c = 'btn btn-primary' %}<button class=\"{{ c }}\">x</button>"))
        self.assertTrue(self.counts("{% set c = 'alert alert-' ~ level %}<div class=\"{{ c }}\">x</div>"))
        self.assertEqual({}, dict(self.counts("{% set c = 'fa-cyber-btn' ~ (x ? ' is-on' : '') %}<button class=\"{{ c }}\">x</button>")))


class Gate73ListByContentTest(unittest.TestCase):
    def run_gate(self, files: dict[str, str]) -> int:
        with Tree(files) as root, mock.patch.object(lists, "ROOT", root), \
                mock.patch.object(lists, "TEMPLATES", root / "templates"), \
                mock.patch.object(lists, "NOT_LISTS_FILE", root / "none.txt"), mock.patch.object(sys, "argv", ["x"]):
            return run_quiet(lists.main)[0]

    PAGE = ("{% extends 'base.html.twig' %}{% block body %}<table>{% for r in rows %}<tr><td>{{ r.name }}</td></tr>"
            "{% endfor %}</table>{% endblock %}")

    def test_b34_list_page_with_another_name(self):
        self.assertEqual(1, self.run_gate({"templates/zz/overview.html.twig": self.PAGE}))
        self.assertEqual(0, self.run_gate({"templates/zz/overview.html.twig": "{% extends 'base.html.twig' %}<p>hub</p>"}))
        self.assertEqual(0, self.run_gate({"templates/zz/_rows.html.twig": self.PAGE}))
        self.assertEqual(0, self.run_gate({"templates/zz/show.html.twig": self.PAGE}))
        self.assertEqual(0, self.run_gate({"templates/zz/overview.html.twig": self.PAGE.replace("base.html.twig", "pdf/_base_document.html.twig")}))


class Gate75DetailByContentTest(unittest.TestCase):
    def run_gate(self, files: dict[str, str]) -> int:
        with Tree(files) as root, mock.patch.object(show, "ROOT", root), \
                mock.patch.object(show, "TEMPLATES", root / "templates"), mock.patch.object(sys, "argv", ["x"]):
            return run_quiet(show.main)[0]

    RECORD = ("{% extends 'base.html.twig' %}{% block body %}<h2>{{ risk.title }}</h2><p>{{ risk.description }}</p>"
              "<p>{{ risk.owner }}</p>{% endblock %}")

    def test_b35_detail_page_with_another_name(self):
        self.assertEqual(1, self.run_gate({"templates/zz/record.html.twig": self.RECORD}))
        self.assertEqual(1, self.run_gate({"templates/zz/record.html.twig": (
            "{% extends 'base.html.twig' %}{% include '_components/_isms_comment_thread.html.twig' %}")}))
        self.assertEqual(0, self.run_gate({"templates/zz/record.html.twig": "{% extends 'base.html.twig' %}<p>{{ a.b }}</p>"}))
        self.assertEqual(0, self.run_gate({"templates/zz/record.html.twig": self.RECORD.replace("{% block", "<form></form>{% block")}))
        self.assertEqual(0, self.run_gate({"templates/zz/_part.html.twig": self.RECORD}))
        self.assertEqual(0, self.run_gate({"templates/zz/record.html.twig": self.RECORD.replace("risk.", "loopvar.").replace(
            "{% block body %}", "{% block body %}{% for loopvar in x %}").replace("{% endblock %}", "{% endfor %}{% endblock %}")}))

    def test_b35_listed_page_is_skipped_with_a_reason(self):
        files = {"templates/zz/record.html.twig": self.RECORD,
                 "scripts/quality/baselines/show_skeleton_not_details.txt":
                     "templates/zz/record.html.twig # aggregate dashboard, not one record\n"}
        self.assertEqual(0, self.run_gate(files))


class Gate74KpiCatalogTest(unittest.TestCase):
    def run_gate(self, rel: str, text: str) -> int:
        with Tree({rel: text}) as root, mock.patch.object(kpi, "ROOT", root), mock.patch.object(sys, "argv", ["x"]):
            return run_quiet(kpi.main)[0]

    def test_b36_call_across_lines(self):
        self.assertEqual(1, self.run_gate("src/A.php", "<?php\n$x = $this->dash\n   ->\n   getManagementKPIs();\n"))
        self.assertEqual(1, self.run_gate("src/A.php", "<?php\n$x = $this->dash?->\n   getManagementKPIs();\n"))
        self.assertEqual(1, self.run_gate("src/A.php", "<?php\n$x = $dash->/* why */getManagementKPIs();\n"))
        self.assertEqual(0, self.run_gate("src/A.php", "<?php\n$x = $this\n  ->getManagementKPIs();\n"))
        self.assertEqual(0, self.run_gate("src/A.php", "<?php\n$x = $this->kpis->managementKpis();\n"))


class Gate78PageSemanticsTest(unittest.TestCase):
    def findings(self, twig: str):
        with Tree({"templates/x/page.html.twig": twig}) as root, \
                mock.patch.object(semantics, "ROOT", root), mock.patch.object(semantics, "TEMPLATES", root / "templates"):
            return semantics.findings()

    IMPORT = "{% import '_components/_status_pill.html.twig' as pill %}"

    def test_b37_ternary_with_matches(self):
        self.assertIn("templates/x/page.html.twig::ternary-tone", self.findings(
            "{{ pill.render(x.status, x.status matches '/^open/' ? 'danger' : (x.status starts with 'do' ? 'success' : 'neutral')) }}"))
        self.assertEqual([], self.findings("{{ pill.render(n, n < 10 ? 'warning' : 'danger') }}"))

    def test_b37_if_chain_assigning_tones(self):
        chain = ("{% if x.status == 'open' %}{% set t = 'danger' %}{% elseif x.status == 'done' %}{% set t = 'success' %}"
                 "{% else %}{% set t = 'neutral' %}{% endif %}")
        self.assertIn("templates/x/page.html.twig::if-chain-tone", self.findings(chain))
        numeric = "{% if n > 10 %}{% set t = 'danger' %}{% else %}{% set t = 'success' %}{% endif %}"
        self.assertEqual([], self.findings(numeric))
        self.assertEqual([], self.findings("{% set t = 'danger' %}{% set u = 'success' %}"))

    def test_b37_forbidden_tone_literal(self):
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(self.IMPORT + "{{ pill.render('x', 'info') }}"))
        self.assertEqual([], self.findings(self.IMPORT + "{{ pill.render('x', 'primary') }}"))

    def test_b37_any_alias(self):
        spelled = "{% import '_components/_status_pill.html.twig' as sp %}{{ sp.render('x', 'secondary') }}"
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(spelled))
        double = '{% import "_components/_status_pill.html.twig" as sp %}{{ sp.render("x", "info") }}'
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(double))
        from_import = "{% from '_components/_status_pill.html.twig' import render as sp_render %}{{ sp_render('x', 'info') }}"
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(from_import))
        named = self.IMPORT + "{{ pill.render('x', tone: 'info') }}"
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(named))
        via_var = self.IMPORT + "{% set t = 'secondary' %}{{ pill.render('x', t) }}"
        self.assertIn("templates/x/page.html.twig::pill-tone", self.findings(via_var))
        other = "{% import '_components/_status_pill.html.twig' as sp %}{{ sp.render('x', 'success') }}"
        self.assertEqual([], self.findings(other))


class Gate79StimulusTest(unittest.TestCase):
    def problems(self, twig: str, js: str = "") -> str:
        files = {"templates/x/a.html.twig": twig,
                 "assets/controllers/real_controller.js":
                     "export default class extends Controller {\n    static targets = ['box']\n    go() {}\n}\n"}
        if js:
            files["assets/controllers/real_controller.js"] = js
        with Tree(files) as root, mock.patch.object(stimulus, "ROOT", root), \
                mock.patch.object(stimulus, "CONTROLLERS", root / "assets/controllers"), \
                mock.patch.object(stimulus, "BOOTSTRAP", root / "none.js"), \
                mock.patch.object(stimulus, "SCAN_DIRS", (root / "templates", root / "src")), \
                mock.patch.object(sys, "argv", ["x"]):
            (root / "src").mkdir(exist_ok=True)
            code, out = run_quiet(stimulus.main)
            return out if code else ""

    def test_b38_dynamic_controller_name(self):
        self.assertIn("dynamic-controller", self.problems('<div data-controller="{{ ctrl }}"></div><i data-controller="real"></i>'))
        self.assertIn("dynamic-controller", self.problems("<div data-controller=\"{{ ctrl ?? 'real' }}\"></div>"))
        marker = "{# stimulus-dynamic: the macro option names a controller its callers pass literally #}\n"
        self.assertEqual("", self.problems(marker + '<div data-controller="{{ ctrl }}"></div><i data-controller="real"></i>'))
        self.assertIn("dynamic-controller", self.problems("{# stimulus-dynamic: #}\n" + '<div data-controller="{{ ctrl }}"></div>'))
        self.assertEqual("", self.problems("<div data-controller=\"real{{ on ? ' real' : '' }}\"></div>"))

    def test_b38_stimulus_controller_function(self):
        self.assertIn("unknown-controller", self.problems("<div {{ stimulus_controller('doesnotexist') }}></div>"))
        self.assertIn("dynamic-controller", self.problems("<div {{ stimulus_controller(name) }}></div>"))
        self.assertEqual("", self.problems("<div {{ stimulus_controller('real', {a: 1}) }}></div>"))
        self.assertIn("unknown-method", self.problems("<div {{ stimulus_action('real', 'nope') }}></div>"))
        self.assertEqual("", self.problems("<div {{ stimulus_controller('real') }} {{ stimulus_action('real', 'go') }}></div>"))


class Gate80TransferZonesTest(unittest.TestCase):
    CONFIG = ("as_of: '%s'\neu: [%s]\neea_extra: [IS, LI, NO]\nadequacy: [CH]\ndpf: [US]\n")

    def errors(self, files: dict[str, str]) -> str:
        import datetime
        config = self.CONFIG % (datetime.date.today().isoformat(), ", ".join(EU27))
        files = {"config/gdpr_transfer_zones.yaml": config, **files}
        with Tree(files) as root, mock.patch.object(zones, "ROOT", root), \
                mock.patch.object(zones, "CONFIG", root / "config/gdpr_transfer_zones.yaml"), \
                mock.patch.object(sys, "argv", ["x"]):
            (root / "src").mkdir(exist_ok=True)
            code, out = run_quiet(zones.main)
            return out if code else ""

    def php(self, body: str) -> str:
        return self.errors({"src/A.php": "<?php\nclass A {\n" + body + "\n}\n"})

    @staticmethod
    def lit(codes) -> str:
        return "[" + ", ".join(f"'{c}'" for c in codes) + "]"

    def test_b39_list_split_in_two_literals(self):
        halves = f"function f() {{ return array_merge({self.lit(EU27[:4])}, {self.lit(EU27[4:8])}, {self.lit(EU27[8:14])}, {self.lit(EU27[14:])}); }}"
        self.assertIn("hard-coded EU/EEA", self.php(halves))
        two = f"function f() {{ $a = {self.lit(EU27[:3])}; $b = {self.lit(EU27[3:8])}; return array_merge($a, $b); }}"
        self.assertIn("hard-coded EU/EEA", self.php(two))
        self.assertEqual("", self.php(f"function f() {{ return array_merge({self.lit(EU27[:3])}, ['CH', 'US']); }}"))

    def test_b39_country_names(self):
        names = "['Germany', 'France', 'Italy', 'Spain', 'Poland', 'Austria']"
        self.assertIn("hard-coded EU/EEA", self.php(f"function f() {{ return {names}; }}"))
        de = "['Deutschland', 'Frankreich', 'Italien', 'Spanien', 'Polen']"
        self.assertIn("hard-coded EU/EEA", self.php(f"function f() {{ return {de}; }}"))
        self.assertEqual("", self.php("function f() { return ['Germany', 'France', 'Japan']; }"))

    def test_b39_twig_set(self):
        twig = "{% set eu = " + self.lit(EU27) + " %}"
        self.assertIn("hard-coded EU/EEA", self.errors({"templates/x/a.html.twig": twig}))
        halves = "{% set a = " + self.lit(EU27[:4]) + " %}{% set b = " + self.lit(EU27[4:10]) + " %}"
        self.assertIn("hard-coded EU/EEA", self.errors({"templates/x/a.html.twig": halves}))
        self.assertEqual("", self.errors({"templates/x/a.html.twig": "{% set c = ['DE', 'CH'] %}"}))


class Gate81SortDirectionTest(unittest.TestCase):
    def out(self, body: str) -> int:
        with Tree({"src/A.php": "<?php\nclass A { function f($qb, $f) {\n" + body + "\n} }\n"}) as root, \
                mock.patch.object(sort, "ROOT", root), mock.patch.object(sys, "argv", ["x"]):
            return run_quiet(sort.main)[0]

    def test_b40_direction_in_a_variable(self):
        self.assertEqual(1, self.out("$dir = 'DESC';\n$qb->orderBy('a.x', $dir);"))
        self.assertEqual(0, self.out("$dir = \\SortDirection::Descending;\n$qb->orderBy('a.x', $dir);"))

    def test_b40_direction_inside_the_sort_string(self):
        self.assertEqual(1, self.out('$qb->orderBy("a.x DESC");'))
        self.assertEqual(1, self.out("$qb->orderBy('a.x ASC, a.y DESC');"))
        self.assertEqual(0, self.out("$qb->orderBy('a.x');"))
        self.assertEqual(0, self.out("$qb->orderBy('a.descendants');"))

    def test_b40_named_arguments(self):
        self.assertEqual(1, self.out("$qb->orderBy(sort: 'a.x', order: 'ASC');"))
        self.assertEqual(0, self.out("$qb->orderBy(sort: 'a.x', order: \\SortDirection::Ascending);"))

    def test_b40_ternary(self):
        self.assertEqual(1, self.out("$qb->orderBy('a.x', $f ? 'ASC' : 'DESC');"))
        self.assertEqual(0, self.out("$qb->orderBy('a.x', $f ? \\SortDirection::Ascending : \\SortDirection::Descending);"))

    def test_b40_existing_forms_and_dbal_marker_stay(self):
        self.assertEqual(1, self.out("$qb->orderBy('a.x', 'DESC');"))
        self.assertEqual(0, self.out("$qb->orderBy('a.x', 'DESC'); // @dbal-order"))


if __name__ == "__main__":
    unittest.main()
