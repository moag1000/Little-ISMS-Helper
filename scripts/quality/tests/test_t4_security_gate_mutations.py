"""T4 mutation counter-check (welle 10, section B-1): Gates 82, 84, 90, 92 and 93 turn red on the mutations
that used to stay green. One test per mutation id; a correct spelling next to it stays green.

Run: python3 -m unittest discover -s scripts/quality/tests
"""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

QUALITY = Path(__file__).resolve().parents[1]
REPO = QUALITY.parents[1]
sys.path.insert(0, str(QUALITY))


def load(name):
    spec = importlib.util.spec_from_file_location(f"t4_{name}", QUALITY / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


roles = load("check_workflow_roles")
jobs = load("check_job_id_access")
raw = load("check_raw_output")
sso = load("check_sso_claim_policy")
csv = load("check_csv_formula_injection")


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
    def workflow(role: str, wrapper: str = "") -> str:
        body = ("framework:\n    workflows:\n        zz:\n            type: state_machine\n"
                "            places: [a, b]\n            transitions:\n"
                f"                go: {{ from: a, to: b, metadata: {{ roles: [{role}] }} }}\n")
        if wrapper:
            return f"when@{wrapper}:\n" + "".join("    " + line + "\n" for line in body.splitlines())
        return body

    def test_b7_workflow_block_outside_config_workflows(self):
        self.assertTrue(self.run_gate({"config/packages/zz.yaml": self.workflow("ROLE_USER")}))
        self.assertTrue(self.run_gate({"config/packages/zz.yaml": self.workflow("ROLE_MADE_UP")}))
        self.assertTrue(self.run_gate({"config/packages/zz.yaml": self.workflow("ROLE_USER", "prod")}))
        self.assertEqual([], self.run_gate({"config/packages/zz.yaml": self.workflow("ROLE_MANAGER")}))
        self.assertEqual([], self.run_gate({"config/packages/other.yaml": "parameters:\n    workflows: x\n"}))

    def seeder(self, call: str, extra: str = "") -> list[str]:
        php = ("<?php\nnamespace App\\Service;\nuse App\\Entity\\WorkflowStep;\nclass S\n{\n" + extra
               + "    public function seed(): void\n    {\n        $s = new WorkflowStep();\n        " + call
               + "\n    }\n}\n")
        return self.run_gate({"src/Service/S.php": php})

    def test_b8_set_approver_role_anywhere_in_src(self):
        self.assertTrue(self.seeder("$s->setApproverRole('ROLE_USER');"))
        self.assertTrue(self.seeder("$s->setApproverRole('ROLE_MADE_UP');"))
        self.assertEqual([], self.seeder("$s->setApproverRole('ROLE_MANAGER');"))

    def test_b9_set_approver_role_with_class_constant(self):
        self.assertTrue(self.seeder("$s->setApproverRole(self::R);", "    private const R = 'ROLE_USER';\n"))
        self.assertTrue(self.seeder("$s->setApproverRole(self::R);", "    private const R = 'ROLE_MADE_UP';\n"))
        self.assertEqual([], self.seeder("$s->setApproverRole(self::R);", "    private const R = 'ROLE_MANAGER';\n"))

    def lookup(self, call: str, params: str = "$r, $t", marker: str = "") -> list[str]:
        php = ("<?php\nnamespace App\\Service;\nclass L\n{\n    public function go(" + params + "): array\n    {\n"
               + marker + "        return " + call + ";\n    }\n}\n")
        return self.run_gate({"src/Service/L.php": php})

    def test_b10_unresolvable_lookup_role_fails(self):
        self.assertTrue(self.lookup("$this->repo->findByRoleInTenant($r, $t)"))
        self.assertTrue(self.lookup("$this->repo->findUsersByRoles([$r], $t)"))
        self.assertTrue(self.lookup("$this->repo->findUsersByRoles(['ROLE_MADE_UP'], $t)"))
        # a literal, a constant-free literal list and a reviewed marker with a reason stay green
        self.assertEqual([], self.lookup("$this->repo->findByRoleInTenant('ROLE_MANAGER', $t)"))
        self.assertEqual([], self.lookup("$this->repo->findUsersByRoles(['ROLE_MANAGER', 'ROLE_ADMIN'], $t)"))
        self.assertEqual([], self.lookup("$this->repo->findByRoleInTenant($r, $t)",
                                         marker="        // @role-lookup-ok: role comes from a checked YAML step\n"))
        # a marker without a reason does not count
        self.assertTrue(self.lookup("$this->repo->findByRoleInTenant($r, $t)", marker="        // @role-lookup-ok:\n"))

    def test_b10_foreach_over_literal_roles_is_resolved(self):
        php = "foreach (['ROLE_CISO', 'ROLE_DPO'] as $r) { $x = $this->repo->findByRoleInTenant($r, $t); }"
        self.assertEqual([], self.lookup(php))
        self.assertTrue(self.lookup(php.replace("ROLE_DPO", "ROLE_MADE_UP")))


class Gate90Test(unittest.TestCase):
    def problems(self, members: str, props: str = "    public function __construct(private JobStatusService $jobStatusService) {}\n") -> list[str]:
        text = ("<?php\nnamespace App\\Controller;\nuse App\\Service\\Job\\JobStatusService;\n"
                "final class C\n{\n" + props + members + "\n}\n")
        return jobs.problems_in("src/Controller/C.php", text)[0]

    GUARD = "    #[IsGranted(AsyncJobVoter::ACCESS, subject: 'id')]\n"

    def test_b5_job_id_from_superglobal(self):
        for source in ("$_GET['id']", "$_REQUEST['id']", "$_POST['id']"):
            body = "    #[Route('/x')]\n    public function a() { $id = " + source + "; return $this->jobStatusService->read($id); }"
            self.assertTrue(self.problems(body), source)
            body = "    #[Route('/x')]\n    public function a() { return $this->jobStatusService->read(" + source + "); }"
            self.assertTrue(self.problems(body), source)
        # a route argument with its guard stays green
        ok = "    #[Route('/x/{id}')]\n" + self.GUARD + "    public function a(string $id) { return $this->jobStatusService->read($id); }"
        self.assertEqual([], self.problems(ok))

    def test_b6_dynamic_method_and_new_instance(self):
        route = "    #[Route('/x/{id}')]\n%s    public function a(string $id, string $f) { %s }"
        for call in ("return $this->jobStatusService->$f($id);",
                     "return (new JobStatusService())->read($id);",
                     "$s = new JobStatusService(); return $s->read($id);"):
            self.assertTrue(self.problems(route % ("", call)), call)
            self.assertEqual([], self.problems(route % (self.GUARD, call)), call)


class Gate92Test(unittest.TestCase):
    def test_b1_every_map_literal_of_trans_parameters_is_checked(self):
        bad = "'k'|trans({'%n%': record.title}|merge({'%m%': x|e}), 'd')"
        self.assertFalse(raw.trans_params_safe(bad)[0])
        self.assertFalse(raw.trans_params_safe("'k'|trans({'%n%': x|e}|merge({'%m%': record.title}), 'd')")[0])
        self.assertFalse(raw.trans_params_safe("'k'|trans({'%n%': x|e}|merge(extra), 'd')")[0])
        self.assertTrue(raw.trans_params_safe("'k'|trans({'%n%': x|e}|merge({'%m%': y|e}), 'd')")[0])
        self.assertTrue(raw.trans_params_safe("'k'|trans({'%n%': x|e, '%m%': 3}, 'd')")[0])


class Gate93Test(unittest.TestCase):
    def run_gate(self, files: dict[str, str]) -> list[str]:
        with Tree(files) as root:
            return sso.check(root)

    def test_b24_any_env_file(self):
        for name in (".env.docker", ".env.minimal", ".env.dev", ".env.test"):
            self.assertTrue(self.run_gate({name: "AZURE_ALLOW_EMAIL_LINKING=true\n"}), name)
            self.assertEqual([], self.run_gate({name: "AZURE_ALLOW_EMAIL_LINKING=false\n"}), name)
        # machine-local files are not committed and not scanned
        self.assertEqual([], self.run_gate({".env.local": "AZURE_ALLOW_EMAIL_LINKING=true\n"}))
        self.assertEqual([], self.run_gate({".env.prod.local": "AZURE_ALLOW_EMAIL_LINKING=true\n"}))

    def test_b25_compose_files(self):
        on = ("services:\n  app:\n    environment:\n      - AZURE_ALLOW_EMAIL_LINKING=true\n",
              "services:\n  app:\n    environment:\n      AZURE_ALLOW_EMAIL_LINKING: \"true\"\n",
              "services:\n  app:\n    environment:\n      - AZURE_ALLOW_EMAIL_LINKING=${AZURE_ALLOW_EMAIL_LINKING:-true}\n")
        for where in ("docker-compose.yml", "docker-compose.prod.yml", "compose.yaml", "docker/docker-compose.dev.yml"):
            for text in on:
                self.assertTrue(self.run_gate({where: text}), (where, text))
        off = "services:\n  app:\n    environment:\n      - AZURE_ALLOW_EMAIL_LINKING=false\n"
        self.assertEqual([], self.run_gate({"docker-compose.yml": off}))

    def test_b26_services_parameter_is_the_literal_false(self):
        param = "parameters:\n    app.azure_allow_email_linking_default: %s\n"
        for value in ("'%env(bool:default::AZURE_X)%'", "!php/const true", "'%env(bool:AZURE_ALLOW_EMAIL_LINKING)%'", "true"):
            self.assertTrue(self.run_gate({"config/services.yaml": param % value}), value)
        self.assertEqual([], self.run_gate({"config/services.yaml": param % "false"}))
        self.assertEqual([], self.run_gate({"config/services.yaml": param % "false # off since S9"}))


class Gate84Test(unittest.TestCase):
    def problems(self, body: str, imports: str = "") -> list[str]:
        php = ("<?php\nnamespace App\\Service;\n" + imports + "class E\n{\n    public function out($h, $s): void\n    {\n"
               + body + "\n    }\n}\n")
        csv._TEXT_GETTERS = None
        try:
            return csv.check_file("src/Service/E.php", php)[0]
        finally:
            csv._TEXT_GETTERS = None

    def test_b21_callable_and_variable_function(self):
        self.assertTrue(self.problems("        $cb = fputcsv(...);\n        $cb($h, [$s->getName()]);"))
        self.assertTrue(self.problems("        $f = 'fputcsv';\n        $f($h, [$s->getName()]);"))
        self.assertTrue(self.problems("        array_map('fputcsv', $rows);"))
        self.assertTrue(self.problems("        $cb = fputcsv(...); // @csv-sanitize-exempt:\n"))
        self.assertEqual([], self.problems("        fputcsv($h, [CsvSanitizer::sanitize($s->getName())]);"))
        self.assertEqual([], self.problems("        $cb = fputcsv(...); // @csv-sanitize-exempt: counters only\n"))

    def test_b22_marker_needs_a_reason(self):
        site = "        // @csv-sanitize-exempt:%s\n        fputcsv($h, [$s->getName()]);"
        self.assertTrue(self.problems(site % ""))
        self.assertTrue(self.problems(site % "   "))
        self.assertEqual([], self.problems(site % " fixed label, no record text"))

    def test_b23_cell_object_in_a_variable(self):
        imports = "use PhpOffice\\PhpSpreadsheet\\Spreadsheet;\n"
        body = "        $w = $sheet->getCell('A1');\n        $w->setValue(%s);"
        self.assertTrue(self.problems(body % "$s->getName()", imports))
        self.assertEqual([], self.problems(body % "CsvSanitizer::sanitize($s->getName())", imports))
        self.assertEqual([], self.problems(body % "$s->getId()", imports))
        # without PhpSpreadsheet in the file, setValue() is Doctrine / PropertyAccessor vocabulary
        self.assertEqual([], self.problems(body % "$s->getName()"))


if __name__ == "__main__":
    unittest.main()
