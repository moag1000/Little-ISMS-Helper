"""W9 mutation counter-check: Gates 82, 84, 87 and 90 turn red on the mutations that used to stay green.

Each case writes the mutated snippet to a scratch tree (or feeds the gate's own function) and expects a
finding; a control next to it stays green so a gate that fails on everything cannot pass.

Run: python3 -m unittest discover -s scripts/quality/tests
"""
import importlib.util
import re
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

QUALITY = Path(__file__).resolve().parents[1]
REPO = QUALITY.parents[1]
sys.path.insert(0, str(QUALITY))


def load(name):
    spec = importlib.util.spec_from_file_location(f"w9_{name}", QUALITY / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


roles = load("check_workflow_roles")
csv = load("check_csv_formula_injection")
bands = load("check_risk_band_literals")
jobs = load("check_job_id_access")
php_source = load("php_source")


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


class PhpSourceTest(unittest.TestCase):
    def test_comments_go_strings_stay(self):
        code = php_source.strip_comments("<?php $a = 'x // y'; // gone\n/* c */ #[Route('/a#b')]\n# hash\n")
        self.assertIn("'x // y'", code)
        self.assertIn("#[Route('/a#b')]", code)
        self.assertNotIn("gone", code)
        self.assertNotIn("hash", code)

    def test_use_aliases_plain_and_group(self):
        found = php_source.use_aliases("use A\\B\\C as D;\nuse A\\{E, F as G};\nuse H\\I;\n")
        self.assertEqual({"D": "A\\B\\C", "E": "A\\E", "G": "A\\F", "I": "H\\I"}, found)

    def test_methods_any_visibility_and_tabs(self):
        _, methods = php_source.class_methods("<?php class X {\n\t#[Route('/a')]\n\tpublic function a($id)\n\t{ return 1; }\n"
                                              "\tprivate static function b(): ?int { return 2; }\n}\n")
        self.assertEqual(["a", "b"], [m.name for m in methods])
        self.assertIn("#[Route('/a')]", methods[0].attributes)


class Gate82Test(unittest.TestCase):
    SECURITY = (REPO / "config/packages/security.yaml").read_text(encoding="utf-8")

    def run_gate(self, files: dict[str, str]) -> list[str]:
        files = {"config/packages/security.yaml": self.SECURITY, **files}
        with Tree(files) as root, mock.patch.object(roles, "ROOT", root), \
                mock.patch.object(roles, "SECURITY", root / "config/packages/security.yaml"), \
                mock.patch.object(roles, "WORKFLOWS", root / "config/workflows"), \
                mock.patch.object(roles, "REGULATORY", root / "config/workflows/regulatory"), \
                mock.patch.object(roles, "COMMANDS", root / "src/Command"), \
                mock.patch.object(roles, "KNOWN_SEEDER_ROLE_USER", {}), \
                mock.patch.object(roles, "_CONSTS", None):
            known = roles.known_roles()
            return roles.check_yaml(known) + roles.check_seeders(known) + roles.check_lookups(known)

    @staticmethod
    def regulatory(step: str) -> str:
        return ("framework:\n    workflows:\n        w:\n            type: state_machine\n"
                "            supports: [App\\Entity\\WorkflowInstance]\n            initial_marking: a\n"
                "            places: [a, b]\n            transitions:\n"
                "                go: { from: a, to: b, metadata: { roles: [ROLE_MANAGER] } }\n"
                "            metadata:\n                regulatory_metadata: { title: T, entity_type: Risk }\n"
                f"                steps:\n                    - {{ name: \"One\", order: 1, {step} }}\n")

    def yaml_problems(self, step: str) -> list[str]:
        return self.run_gate({"config/workflows/regulatory/w.yaml": self.regulatory(step)})

    def test_step_type_other_than_notification_is_an_approval(self):
        self.assertEqual([], self.yaml_problems("approver_role: ROLE_MANAGER, step_type: approval"))
        self.assertEqual([], self.yaml_problems("approver_role: ROLE_USER, step_type: notification"))
        self.assertTrue(self.yaml_problems("approver_role: ROLE_USER, step_type: review"))
        self.assertTrue(self.yaml_problems("approver_role: ROLE_USER"))
        self.assertTrue(self.yaml_problems("approver_role: ROLE_MANAGER, step_type: review"))  # unknown value

    def test_when_wrapper_is_read(self):
        wrapped = ("when@prod:\n    framework:\n        workflows:\n            w:\n                type: state_machine\n"
                   "                places: [a, b]\n                transitions:\n"
                   "                    approve: { from: a, to: b, metadata: { roles: [ROLE_USER] } }\n")
        self.assertTrue(self.run_gate({"config/workflows/x.yaml": wrapped}))

    def test_place_decided_by_a_role_transition_without_decision_prefix(self):
        wf = ("framework:\n    workflows:\n        w:\n            type: state_machine\n"
              "            places: [draft, review, done]\n            transitions:\n"
              "                submit: { from: draft, to: %s, metadata: { roles: [ROLE_USER] } }\n"
              "                finalise: { from: review, to: done, metadata: { roles: [ROLE_MANAGER] } }\n"
              "                reopen: { from: done, to: review, metadata: { roles: [ROLE_MANAGER] } }\n")
        self.assertTrue(self.run_gate({"config/workflows/x.yaml": wf % "done"}))
        self.assertEqual([], self.run_gate({"config/workflows/x.yaml": wf % "review"}))

    def lookup(self, call: str, pre: str = "") -> list[str]:
        php = ("<?php\nfinal class L\n{\n    private const R = 'ROLE_BOGUS';\n    public function go($tenant, $role)\n"
               "    {\n" + pre + "        return " + call + ";\n    }\n}\n")
        return self.run_gate({"src/Service/L.php": php})

    def test_lookup_forms(self):
        self.assertEqual([], self.lookup("$r->findByRoleInTenant('ROLE_ADMIN', $tenant)"))
        self.assertTrue(self.lookup("$r->findByRoleInTenant(self::R, $tenant)"))
        self.assertTrue(self.lookup("$r->findByRoleInTenant($role, $tenant)", pre="        $role = 'ROLE_BOGUS';\n"))
        self.assertTrue(self.lookup("$r->findByRoleInTenant(role: 'ROLE_BOGUS', tenant: $tenant)"))
        self.assertTrue(self.lookup("$r->findByRoleInTenant(tenant: $tenant, role: 'ROLE_BOGUS')"))
        self.assertTrue(self.lookup("$r->findByRoleInTenant(\n  \"ROLE_BOGUS\", $tenant)"))

    def seeder(self, role: str, step_type: str = "'stepType' => 'approval', ") -> list[str]:
        php = ("<?php\nuse App\\Entity\\WorkflowStep;\nfinal class SeedMutCommand\n{\n    const S = [\n"
               f"        ['name' => 'a', {step_type}'approverRole' => {role}],\n    ];\n}}\n")
        return self.run_gate({"src/Command/Deep/SeedMutCommand.php": php})

    def test_seeders_found_by_rglob_with_either_quote(self):
        self.assertEqual([], self.seeder("'ROLE_MANAGER'"))
        self.assertTrue(self.seeder("'ROLE_BOGUS'"))
        self.assertTrue(self.seeder('"ROLE_BOGUS"'))
        self.assertTrue(self.seeder("'ROLE_USER'"))
        self.assertTrue(self.seeder("'ROLE_USER'", "'stepType' => 'auto_action', "))
        self.assertEqual([], self.seeder("'ROLE_USER'", "'stepType' => 'notification', "))


class Gate84Test(unittest.TestCase):
    def problems(self, body: str, decl: str = "", sanitized_ok: bool = False) -> list[str]:
        php = ("<?php\nfinal class X\n{\n    public function go($h, $s, $sheet, $resp, array $rows)\n    {\n"
               "        $resp->headers->set('Content-Type', 'text/csv');\n" + body + "\n    }\n}\n")
        csv._TEXT_GETTERS = None
        with Tree({"src/Entity/Supplier.php": decl}) as root, mock.patch.object(csv, "SRC", root / "src"):
            try:
                found = [csv.site_problem(php, m) for m in csv.CALL.finditer(php)]
                found += [csv.fwrite_problem(php, m) for m in csv.FWRITE.finditer(php)]
                return [p for p in found if p]
            finally:
                csv._TEXT_GETTERS = None

    def test_variable_route_with_semicolon_separator(self):
        self.assertTrue(self.problems("$line = implode(';', [$s->getName()]);\nfwrite($h, $line);"))
        self.assertTrue(self.problems("$line = implode(';', [$s->getName()]);\n$line .= \"\\n\";\nfwrite($h, $line);"))
        self.assertEqual([], self.problems("$line = implode(';', [CsvSanitizer::sanitize($s->getName())]);\nfwrite($h, $line);"))

    def test_property_access_counts_like_a_getter(self):
        self.assertTrue(self.problems("fputcsv($h, [$s->name]);"))
        self.assertTrue(self.problems("$sheet->setCellValue('A1', $s->title);"))
        self.assertEqual([], self.problems("fputcsv($h, [$s->id, $s->count, $s->getScore()]);"))
        self.assertTrue(self.problems("$n = $s->name;\nfputcsv($h, [CsvSanitizer::sanitize($t), $n]);"))
        self.assertEqual([], self.problems("$n = CsvSanitizer::sanitize($s->name);\nfputcsv($h, [CsvSanitizer::sanitize($t), $n]);"))

    def test_look_alike_sanitizer(self):
        self.assertTrue(self.problems("fputcsv($h, [$this->sanitizeHtml($s->getName())]);"))
        self.assertEqual([], self.problems("fputcsv($h, [\\App\\Util\\CsvSanitizer::sanitize($s->getName())]);"))

    def test_other_sinks_and_separators(self):
        for body in ("fwrite($h, $s->getName() . ';' . $s->getId());",
                     "fwrite($h, implode(', ', [$s->getName()]));",
                     "fwrite($h, implode(self::SEP, [$s->getName()]) . \"\\n\");",
                     "echo implode(';', [$s->getName()]);",
                     "file_put_contents('/tmp/x.csv', implode(';', [$s->getName()]));",
                     "$resp->setContent($s->getName() . \";\" . $s->getId());",
                     "return new Response(implode(',', [$s->getName()]));",
                     "fwrite($h, sprintf('%s;%s', $s->getName(), 1));"):
            self.assertTrue(self.problems(body), body)
        self.assertEqual([], self.problems("fwrite($h, implode(';', [CsvSanitizer::sanitize($s->getName())]));"))
        self.assertEqual([], self.problems("return new Response($this->builder->build(implode(', ', $parts)));"))

    def test_untyped_and_mixed_getters_are_text(self):
        php = "fputcsv($h, [$s->getContractNumber()]);"
        self.assertTrue(self.problems(php, "<?php\nclass S { public function getContractNumber() { return $this->c; } }\n"))
        self.assertTrue(self.problems(php, "<?php\nclass S { public function getContractNumber(): mixed {} }\n"))
        self.assertEqual([], self.problems(php, "<?php\nclass S { public function getContractNumber(): ?int {} }\n"))

    def test_get_cell_variants(self):
        self.assertTrue(self.problems("$sheet->getCellByColumnAndRow(1, 2)->setValue($s->getName());"))
        self.assertTrue(self.problems("$sheet->getCell('A1')->setValue($s->getName());"))


class Gate87Test(unittest.TestCase):
    def hits(self, php: str = "", twig: str = "", js: str = "") -> list:
        files = {}
        if php:
            files["src/Service/Mut.php"] = "<?php\nnamespace App\\Service;\n" + php
        if twig:
            files["templates/mut.html.twig"] = twig
        if js:
            files["assets/controllers/mut_controller.js"] = js
        with Tree(files) as root, mock.patch.object(bands, "ROOT", root):
            (root / "src").mkdir(exist_ok=True)
            (root / "templates").mkdir(exist_ok=True)
            (root / "assets").mkdir(exist_ok=True)
            return bands.scan() + bands.scan_without_tenant()

    def test_alias_variable(self):
        self.assertTrue(self.hits("class M { function a($r) { $s = $r->getRiskScore(); return $s >= 20; } }"))
        self.assertTrue(self.hits("class M { function a($r) { $v = $r\n->getInherentRiskLevel();\nif ($v > 11) {} } }"))
        self.assertFalse(self.hits("class M { function a($r, $x) { $n = count($r->getRisks()); return $n > 5; } }"))
        self.assertFalse(self.hits("class M { function a($r, $a) { $e = $r->getRiskScore() - $a; return $e <= 6; } }"))

    def test_edge_below(self):
        self.assertTrue(self.hits("class M { function a($r) { return $r->getRiskScore() > 19; } }"))
        self.assertTrue(self.hits("class M { function a($riskScore) { return $riskScore <= 5; } }"))
        self.assertFalse(self.hits("class M { const X = ['inherent_impact' => 5]; }"))

    def test_range_and_band_arrays(self):
        self.assertTrue(self.hits("class M { function a($riskScore) { return in_array($riskScore, range(20, 25), true); } }"))
        self.assertTrue(self.hits("class RiskBands { private const BANDS = ['critical' => 20, 'high' => 12, 'medium' => 6]; }"))

    def test_twig_set_alias(self):
        self.assertTrue(self.hits(twig="{% set v = risk.inherentRiskLevel %}{% if v >= 20 %}x{% endif %}"))
        self.assertTrue(self.hits(twig="{% set v = risk.riskScore|default(0) %}{% if v >= 12 %}x{% endif %}"))
        self.assertFalse(self.hits(twig="{% set n = risks|length %}{% if n > 5 %}x{% endif %}"))

    def test_js_alias(self):
        self.assertTrue(self.hits(js="const s = risk.score;\nif (s >= 20) { go(); }"))

    def test_aliased_thresholds_import_needs_a_tenant(self):
        head = "use App\\Risk\\RiskMatrixThresholds as T;\nclass M { function a($x, $t) { return %s; } }"
        self.assertTrue(self.hits(head % "T::classify($x)"))
        self.assertFalse(self.hits(head % "T::classify($x, $t)"))
        self.assertTrue(self.hits("use App\\Risk\\{RiskMatrixThresholds as T};\nclass M { function a($x) { return T::classify($x); } }"))


class Gate90Test(unittest.TestCase):
    def problems(self, members: str, props: str = "    public function __construct(private JobStatusService $jobStatusService) {}\n",
                 uses: str = "", head: str = "") -> list[str]:
        text = ("<?php\nnamespace App\\Controller;\nuse App\\Service\\Job\\JobStatusService;\n" + uses + head
                + "final class C\n{\n" + props + members + "\n}\n")
        return jobs.problems_in("src/Controller/C.php", text)[0]

    GUARD = "    #[IsGranted(AsyncJobVoter::ACCESS, subject: 'id')]\n"

    def test_helper_defined_after_another_public_method(self):
        helper = "    #[Route('/y')]\n    public function other() { return 1; }\n    private function helper(string $id) { return $this->jobStatusService->read($id); }\n"
        route = "    #[Route('/x/{id}')]\n%s    public function a(string $id) { return $this->helper($id); }\n"
        self.assertTrue(self.problems(helper + route % ""))
        self.assertEqual([], self.problems(helper + route % self.GUARD))

    def test_tab_indentation(self):
        self.assertTrue(self.problems("\t#[Route('/x/{id}')]\n\tpublic function a(string $id)\n\t{\n\t\treturn $this->jobStatusService->read($id);\n\t}"))

    def test_local_alias_of_the_route_argument(self):
        self.assertTrue(self.problems("    #[Route('/x/{id}')]\n    public function a(string $id) { $j = (string) $id; return $this->jobStatusService->read($j); }"))
        self.assertEqual([], self.problems("    #[Route('/x')]\n    public function a() { $j = $this->jobStatusService->create('x', []); return $this->jobStatusService->updatePayload($j, []); }"))

    def test_wrapper_service_and_alias_import(self):
        self.assertTrue(self.problems("    #[Route('/x/{id}')]\n    public function a(string $id) { return $this->results->fetch($id); }",
                                      props="    public function __construct(private JobResultService $results) {}\n"))
        self.assertTrue(self.problems("    #[Route('/x/{id}')]\n    public function a(string $id) { return $this->r->read($id); }",
                                      props="    public function __construct(private R $r) {}\n", uses="use App\\Service\\Job\\JobStatusService as R;\n"))
        self.assertTrue(self.problems("    #[Route('/x/{jobId}')]\n    public function a(string $jobId) { return $this->any->go($jobId); }", props=""))

    def test_class_level_guard_and_opt_out(self):
        route = "    #[Route('/x/{id}')]\n    public function a(string $id) { return $this->jobStatusService->read($id); }"
        self.assertEqual([], self.problems(route, head="#[IsGranted(AsyncJobVoter::ACCESS, subject: 'id')]\n"))
        self.assertEqual([], self.problems(route.replace("{ return", "{ // @job-access-ok: reason\n return")))

    def test_job_directory_spellings(self):
        for php in ("$d . '/var/jobs/' . $id", "$d . '/var/' . 'jobs/'", "Path::join($d, 'var', 'jobs')",
                    "implode('/', [$d, 'var', 'jobs'])"):
            self.assertGreaterEqual(jobs.reads_job_files("<?php\n$p = " + php + ";\n"), 0, php)
        self.assertEqual(-1, jobs.reads_job_files("<?php\n$p = $d . '/var/cache/x';\n"))
        self.assertEqual(-1, jobs.reads_job_files(php_source.strip_comments("<?php\n// var/jobs is elsewhere\n")))


if __name__ == "__main__":
    unittest.main()
