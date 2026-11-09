"""Self-test for the S6 gates 84 (per write site), 85 (by name) and 90 (job owner).

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


csv = load("check_csv_formula_injection")
fulfilment = load("check_fulfillment_single_writer")
jobs = load("check_job_id_access")
module_fields = load("check_api_module_fields")


def csv_problem(php: str):
    match = csv.CALL.search(php)
    return csv.site_problem(php, match)


class CsvWriteSiteTest(unittest.TestCase):
    def test_raw_record_getter_fails_even_when_another_column_is_sanitised(self):
        php = "function x() { $s->setCellValue('A1', CsvSanitizer::sanitize($a->getName()));\n"
        self.assertIsNone(csv_problem(php))
        self.assertIn("getTransferSafeguards", csv_problem("$s->setCellValue('E1', $a->getTransferSafeguards() ?? '-');"))

    def test_numbers_dates_and_counts_pass(self):
        for value in ("$r->getId()", "$r->getProbability()", "$c->getEvidenceDocuments()->count()",
                      "$c->getReviewDate()?->format('Y-m-d') ?? ''", "$a->isActive() ? 'Ja' : 'Nein'", "'Label:'"):
            self.assertIsNone(csv_problem(f"$s->setCellValue('A1', {value});"), value)

    def test_bare_variable_needs_a_sanitising_function_or_marker(self):
        raw = "function rows($h, $row) {\n    fputcsv($h, $row);\n    }\n"
        self.assertIsNotNone(csv_problem(raw))
        clean = "function rows($h, $row) {\n    fputcsv($h, array_map(CsvSanitizer::sanitize(...), $row));\n    }\n"
        self.assertIsNone(csv_problem(clean))
        counters = "function sum($s, $d) {\n    // @csv-sanitize-exempt: counters\n    $s->setCellValue('B2', $d['total']);\n    }\n"
        self.assertIsNone(csv_problem(counters))


class FulfilmentByNameTest(unittest.TestCase):
    def scan(self, php: str):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "X.php"
            path.write_text(php)
            original = fulfilment.ROOT
            fulfilment.ROOT = Path(tmp)
            try:
                return fulfilment.scan(path)
            finally:
                fulfilment.ROOT = original

    def test_property_accessor_write_fails(self):
        self.assertEqual(len(self.scan("$accessor->setValue($f, 'fulfillmentPercentage', 80);")), 1)
        self.assertEqual(len(self.scan("$ref->setValue($fulfillment, 'status', 'x');")), 1)

    def test_raw_sql_fails(self):
        self.assertEqual(len(self.scan("$c->executeStatement('UPDATE compliance_requirement_fulfillments SET x = 1');")), 1)

    def test_unrelated_status_by_name_passes(self):
        self.assertEqual(self.scan("$accessor->setValue($risk, 'status', 'open');"), [])


class JobAccessTest(unittest.TestCase):
    def check(self, php: str) -> list:
        text = "<?php\nfinal class C\n{\n" + php + "\n}\n"
        found = []
        for name, attributes, body, _ in jobs.actions(text):
            if not jobs.ROUTE.search(attributes):
                continue
            signature = body.split("{", 1)[0]
            for variable in jobs.job_id_variables(text, body):
                if f"${variable}" in signature and variable not in jobs.GUARD.findall(attributes) and jobs.OPT_OUT not in body:
                    found.append(name)
        return found

    def test_download_without_owner_check_fails(self):
        php = ("\n    #[Route('/x/{id}')]\n    public function download(string $id): Response\n    {\n"
               "        $r = $this->jobStatusService->read($id);\n    }")
        self.assertEqual(self.check(php), ["download"])

    def test_owner_check_in_both_spellings_passes(self):
        for guard in ("AsyncJobVoter::ACCESS", "\\App\\Security\\Voter\\AsyncJobVoter::ACCESS"):
            php = (f"\n    #[Route('/x/{{id}}')]\n    #[IsGranted({guard}, subject: 'id')]\n"
                   "    public function download(string $id): Response\n    {\n"
                   "        $r = $this->jobStatusService->read($id);\n    }")
            self.assertEqual(self.check(php), [], guard)


    def test_alias_and_other_methods_are_seen(self):
        php = ("\n    #[Route('/x/{id}')]\n    public function cancel(string $id): Response\n    {\n"
               "        $s = $this->jobStatusService;\n        $s->markFailed((string) $id, 'x');\n    }")
        self.assertEqual(self.check(php), ["cancel"])

class ModuleFieldTraitTest(unittest.TestCase):
    def test_entity_properties_include_traits(self):
        # Incident takes its NIS-2 reporting fields from IncidentNis2ReportingTrait.
        self.assertIn("nis2InterimReportedAt", module_fields.entity_properties("Incident"))


if __name__ == "__main__":
    unittest.main()


mfa = load("check_mfa_login_state")


class MfaLoginStateGateTest(unittest.TestCase):
    def test_session_key_outside_the_state_class_fails(self):
        php = "$session->set('_security.mfa_verified', true);"
        self.assertTrue(mfa.problems_in("src/Controller/X.php", php))
        self.assertFalse(mfa.problems_in(mfa.STATE_CLASS, php))

    def test_hand_made_login_needs_the_state_class(self):
        raw = "$this->tokenStorage->setToken($token);"
        self.assertTrue(mfa.problems_in("src/Controller/X.php", raw))
        # T2: importing the class is not using it; begin() must run in the same method
        self.assertTrue(mfa.problems_in("src/Controller/X.php", "use App\\Security\\Mfa\\MfaSessionState;\n" + raw))
        self.assertFalse(mfa.problems_in("src/Controller/X.php", raw + "\n$this->mfaState->begin($session, $user);"))
        self.assertFalse(mfa.problems_in("src/Controller/X.php", "$this->tokenStorage->setToken(null);"))

    def test_setup_may_not_be_always_allowed(self):
        php = "private const ALLOWED_PREFIXES = [\n '/logout',\n '/setup',\n];"
        self.assertTrue(mfa.problems_in(mfa.ENFORCERS[0], php))
        self.assertFalse(mfa.problems_in(mfa.ENFORCERS[0], php.replace(" '/setup',\n", "")))
