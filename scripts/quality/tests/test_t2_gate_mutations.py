"""T2 (wave 8): every gate turns red on the mutation the sixth assessment found it missed.

Each test writes the mutated snippet to a scratch tree (or feeds it to the gate's
own function) and expects a finding; a control snippet next to it stays green so
the test cannot pass by a gate that fails on everything.

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
    spec = importlib.util.spec_from_file_location(f"t2_{name}", QUALITY / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


mfa = load("check_mfa_login_state")
raw = load("check_raw_output")
roles = load("check_workflow_roles")
tenant = load("check_tenant_null_rows")
csrf = load("check_csrf_state_changing")
gating = load("check_module_gating")
csv = load("check_csv_formula_injection")
semantics = load("check_page_semantics")
show = load("check_show_skeleton")
dates = load("date_display")
bands = load("check_risk_band_literals")
api_fields = load("check_api_module_fields")
jobs = load("check_job_id_access")


class Tree:
    """A throw-away project root with the given files."""

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


class Gate91MfaTest(unittest.TestCase):
    def problems(self, body: str):
        return mfa.problems_in("src/Controller/X.php", "<?php\nclass X {\n" + body + "\n}\n")

    def test_class_name_in_a_comment_no_longer_exempts_the_file(self):
        found = self.problems("// MfaSessionState handles this\npublic function a() { $this->ts->setToken($t); }")
        self.assertTrue(found)

    def test_space_before_the_parenthesis(self):
        self.assertTrue(self.problems("public function a() { $this->ts->setToken ($t); }"))

    def test_serialised_firewall_token_in_the_session(self):
        self.assertTrue(self.problems("public function a() { $session->set('_security_main', serialize($t)); }"))
        self.assertTrue(self.problems("public function a() { $session->set(\"_security_partner\", $s); }"))

    def test_begin_in_the_same_method_passes_and_another_method_does_not(self):
        self.assertEqual([], self.problems(
            "public function a() { $this->ts->setToken($t); $this->state->begin($s, $u); }"))
        self.assertTrue(self.problems(
            "public function a() { $this->ts->setToken($t); }\npublic function b() { $this->state->begin($s, $u); }"))
        self.assertEqual([], self.problems("public function a() { $this->ts->setToken(null); }"))


class Gate92RawOutputTest(unittest.TestCase):
    def r1(self, twig: str):
        with Tree({"templates/x.html.twig": twig}) as root, mock.patch.object(raw, "ROOT", root):
            return raw.check_r1([root / "templates/x.html.twig"], {}, set())

    def r3(self, php: str):
        with Tree({"src/X.php": php}) as root, mock.patch.object(raw, "ROOT", root):
            return raw.check_r3()

    def test_autoescape_false_block(self):
        self.assertTrue(self.r1("{% autoescape false %}{{ x }}{% endautoescape %}"))
        self.assertEqual([], self.r1("{% autoescape 'js' %}{{ x }}{% endautoescape %}"))

    def test_markup_through_an_alias(self):
        self.assertTrue(self.r3("<?php\nuse Twig\\Markup as Html;\n$a = new Html($x, 'UTF-8');\n"))
        self.assertEqual([], self.r3("<?php\nuse Twig\\Markup as Html;\n// @markup-safe: escaped above\n$a = new Html($x, 'UTF-8');\n"))

    def test_is_safe_callback(self):
        self.assertTrue(self.r3("<?php\nnew TwigFilter('x', $f, ['is_safe_callback' => $cb]);\n"))


class Gate82LookupTest(unittest.TestCase):
    def test_double_quotes_spaces_and_line_breaks(self):
        php = '<?php\n$r->findByRoleInTenant (\n    "ROLE_NOBODY_HAS", $t);\n'
        with Tree({"src/X.php": php}) as root, mock.patch.object(roles, "ROOT", root):
            self.assertTrue(roles.check_lookups({"ROLE_ADMIN"}))
            self.assertEqual([], roles.check_lookups({"ROLE_NOBODY_HAS"}))


ENTITY = "<?php\nclass Asset {\n    private ?Tenant $tenant = null;\n}\n"


class Gate72TenantNullRowsTest(unittest.TestCase):
    def find(self, files: dict[str, str]):
        files = {"src/Entity/Asset.php": ENTITY, **files}
        with Tree(files) as root, mock.patch.object(tenant, "ROOT", root), mock.patch.object(tenant, "SRC", root / "src"):
            return tenant.find()

    def test_own_or_global_with_in(self):
        found = self.find({"src/Repository/AssetRepository.php":
                           "<?php\n$qb->where('a.tenant IN (:tenants) OR a.tenant IS NULL');\n"})
        self.assertIn("src/Repository/AssetRepository.php::shared-query::Asset", found)

    def test_new_record_in_a_console_command(self):
        found = self.find({"src/Command/SeedCommand.php":
                           "<?php\nclass SeedCommand {\n    public function __invoke() { $a = new Asset(); $this->em->persist($a); }\n}\n"})
        self.assertIn("src/Command/SeedCommand.php::new-no-tenant::__invoke::Asset", found)
        ok = self.find({"src/Command/SeedCommand.php":
                        "<?php\nclass SeedCommand {\n    public function __invoke() { $a = new Asset(); $a->setTenant($t); }\n}\n"})
        self.assertEqual([], ok)


class Gate65CsrfTest(unittest.TestCase):
    def scan(self, action: str):
        php = "<?php\nclass XController {\n" + action + "\n}\n"
        with Tree({"X.php": php}) as root:
            return csrf.scan(root / "X.php")

    def test_unchecked_token_result(self):
        self.assertEqual(["save"], self.scan(
            "    #[Route('/x', methods: ['POST'])]\n    public function save(Request $r): Response\n    {\n"
            "        $ok = $this->isCsrfTokenValid('x', $r->get('_token'));\n        $this->em->flush();\n    }"))
        self.assertEqual([], self.scan(
            "    #[Route('/x', methods: ['POST'])]\n    public function save(Request $r): Response\n    {\n"
            "        $ok = $this->isCsrfTokenValid('x', $r->get('_token'));\n        if (!$ok) { throw $e; }\n    }"))
        self.assertEqual(["save"], self.scan(
            "    #[Route('/x', methods: ['POST'])]\n    public function save(Request $r): Response\n    {\n"
            "        $this->isCsrfTokenValid('x', $r->get('_token'));\n        $this->em->flush();\n    }"))

    def test_methods_as_a_string(self):
        self.assertEqual(["save"], self.scan(
            "    #[Route('/x', methods: 'POST')]\n    public function save(): Response\n    {\n        $this->em->flush();\n    }"))

    def test_form_created_but_never_handled(self):
        self.assertEqual(["save"], self.scan(
            "    #[Route('/x', methods: ['GET', 'POST'])]\n    public function save(): Response\n    {\n"
            "        $form = $this->createForm(XType::class, $x);\n        $this->em->flush();\n    }"))
        self.assertEqual([], self.scan(
            "    #[Route('/x', methods: ['GET', 'POST'])]\n    public function save(Request $r): Response\n    {\n"
            "        $form = $this->createForm(XType::class, $x);\n        $form->handleRequest($r);\n"
            "        if ($form->isSubmitted() && $form->isValid()) { $this->em->flush(); }\n    }"))


class ModuleGateTest(unittest.TestCase):
    def form(self, body: str):
        php = "<?php\nclass XType {\n    public function buildForm($builder, array $options): void\n    {\n" + body + "\n    }\n}\n"
        with Tree({"XType.php": php}) as root:
            return gating.check_file(root / "XType.php")

    def test_field_gated_by_another_module(self):
        self.assertTrue(self.form("        if ($this->isModuleActive('core')) {\n"
                                  "            $builder->add('nis2Category', TextType::class);\n        }"))
        self.assertEqual([], self.form("        if ($this->isModuleActive('nis2_dora')) {\n"
                                       "            $builder->add('nis2Category', TextType::class);\n        }"))
        self.assertTrue(self.form("        if ($this->isModuleActive('privacy')) {\n"
                                  "            $builder->add('tisaxLabel', TextType::class);\n        }"))

    def test_discarded_check_module_active(self):
        controller = ("<?php\nclass DpiaController {\n    #[Route('/dpia', name: 'app_dpia')]\n"
                      "    public function index(): Response\n    {\n        {CHECK}\n        return $this->render('x');\n    }\n}\n")
        with Tree({"bare.php": controller.replace("{CHECK}", "$this->checkModuleActive('privacy');"),
                   "used.php": controller.replace("{CHECK}", "if ($r = $this->checkModuleActive('privacy')) { return $r; }")}) as root:
            self.assertTrue(gating.check_controller_file(root / "bare.php"))
            self.assertEqual([], gating.check_controller_file(root / "used.php"))


class Gate84CsvTest(unittest.TestCase):
    def problems(self, php: str, decl: str = ""):
        csv._TEXT_GETTERS = None
        with Tree({"src/Entity/Supplier.php": decl}) as root, mock.patch.object(csv, "SRC", root / "src"):
            try:
                out = [csv.site_problem(php, m) for m in csv.CALL.finditer(php)]
                out += [csv.fwrite_problem(php, m) for m in csv.FWRITE.finditer(php)]
                return [p for p in out if p]
            finally:
                csv._TEXT_GETTERS = None

    def test_number_getter_that_is_free_text(self):
        php = "function x() { fputcsv($h, [$b->getAuthorityReferenceNumber()]);\n    }\n"
        self.assertTrue(self.problems(php, "<?php\npublic function getAuthorityReferenceNumber(): ?string {}\n"))
        self.assertEqual([], self.problems(php, "<?php\npublic function getAuthorityReferenceNumber(): ?int {}\n"))

    def test_hand_built_csv_line(self):
        self.assertTrue(self.problems("function x() { fwrite($h, implode(';', [$s->getName()]) . \"\\n\");\n    }\n"))
        self.assertTrue(self.problems("function x() { $line = implode(',', [$s->getName()]);\n fwrite($h, $line);\n    }\n"))
        self.assertEqual([], self.problems(
            "function x() { fwrite($h, implode(';', [CsvSanitizer::sanitize($s->getName())]));\n    }\n"))


class Gate78PageSemanticsTest(unittest.TestCase):
    def findings(self, twig: str):
        with Tree({"templates/x/show.html.twig": twig}) as root, \
                mock.patch.object(semantics, "ROOT", root), mock.patch.object(semantics, "TEMPLATES", root / "templates"):
            return semantics.findings()

    def test_ternary_tone_chain_across_lines(self):
        twig = ("{{ pill.render(x.status, x.status == 'open'\n    ? 'danger'\n"
                "    : (x.status == 'done' ? 'success' : 'neutral')) }}")
        self.assertIn("templates/x/show.html.twig::ternary-tone", self.findings(twig))
        self.assertEqual([], self.findings("{{ pill.render(n, n < 10 ? 'warning' : 'danger') }}"))

    def test_info_and_secondary_are_no_pill_tones(self):
        twig = "{% import '_components/_status_pill.html.twig' as pill %}{{ pill.render(x, 'info') }}"
        self.assertIn("templates/x/show.html.twig::pill-tone", self.findings(twig))
        ok = "{% import '_components/_status_pill.html.twig' as pill %}{{ pill.render(x, t in ['info'] ? 'primary' : t) }}"
        self.assertEqual([], self.findings(ok))


class Gate73And75FileNamesTest(unittest.TestCase):
    def run_show(self, files: dict[str, str]) -> int:
        with Tree(files) as root, mock.patch.object(show, "ROOT", root), \
                mock.patch.object(show, "TEMPLATES", root / "templates"), mock.patch.object(sys, "argv", ["x"]):
            return show.main()

    def test_detail_template_is_a_detail_page(self):
        self.assertEqual(1, self.run_show({"templates/x/detail.html.twig": "<h2>{{ x.name }}</h2>"}))

    def test_marker_on_a_record_page(self):
        marker = "{# show-skeleton: no record — a summary of several sessions #}"
        self.assertEqual(1, self.run_show({"templates/x/show.html.twig":
                                           marker + "{% include '_components/_isms_comment_thread.html.twig' %}"}))
        self.assertEqual(0, self.run_show({"templates/x/show.html.twig": marker + "<p>sum</p>"}))

    def test_list_template_is_a_list_page(self):
        lists = load("check_list_skeleton")
        with Tree({"templates/x/list.html.twig": "<table></table>"}) as root, \
                mock.patch.object(lists, "TEMPLATES", root / "templates"), mock.patch.object(lists, "ROOT", root), \
                mock.patch.object(lists, "NOT_LISTS_FILE", root / "none.txt"):
            self.assertEqual(["list.html.twig"], [p.name for p in lists.list_templates()])


class LowGatesTest(unittest.TestCase):
    def test_gate77_textarea_content_is_text(self):
        self.assertTrue(list(dates.display_dates("<textarea>{{ d|date('d.m.Y') }}</textarea>")))

    def test_gate87_rating_variable(self):
        self.assertTrue(bands.PATTERN.search("if ($rating >= 20) {"))

    def test_gate89_module_key_as_another_class_constant(self):
        with Tree({"src/Module/Modules.php": "<?php\nfinal class Modules { public const PRIVACY = 'privacy'; }\n"}) as root, \
                mock.patch.object(api_fields, "ROOT", root):
            self.assertEqual({"privacy"}, api_fields.modules_in("if ($this->isModuleActive(Modules::PRIVACY))", ""))

    def test_gate90_direct_job_file_access(self):
        with Tree({"src/Service/Peek.php": "<?php\n$f = $dir . '/var/jobs/' . $id . '.json';\n",
                   "src/Controller/.keep": ""}) as root, \
                mock.patch.object(jobs, "ROOT", root), mock.patch.object(jobs, "CONTROLLERS", root / "src/Controller"), \
                mock.patch.object(sys, "argv", ["x", "--quiet"]):
            self.assertEqual(1, jobs.main())


if __name__ == "__main__":
    unittest.main()
