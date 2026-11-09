"""Self-test for Gate 93, config part (check_sso_claim_policy.py): T3 mutations.

Run: python3 -m unittest discover -s scripts/quality/tests
"""
import importlib.util
import tempfile
import unittest
from pathlib import Path

QUALITY = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("check_sso_claim_policy", QUALITY / "check_sso_claim_policy.py")
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


def run(services: str = "", env: str = "") -> list[str]:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        (root / "config").mkdir()
        (root / "config/services.yaml").write_text(services, encoding="utf-8")
        (root / ".env").write_text(env, encoding="utf-8")
        return gate.check(root)


class LinkingDefaultTest(unittest.TestCase):
    def test_true_spellings_fail(self):
        for value in ("true", "TRUE", "'true'", '"true"', "1", "yes", "On"):
            self.assertTrue(run(env=f"AZURE_ALLOW_EMAIL_LINKING={value}\n"), value)
            self.assertTrue(run(services=f"parameters:\n    app.azure_allow_email_linking_default: {value}\n"), value)

    def test_off_passes(self):
        self.assertEqual([], run(env="AZURE_ALLOW_EMAIL_LINKING=false\n# AZURE_ALLOW_EMAIL_LINKING=true is wrong\n"))
        self.assertEqual([], run(services="parameters:\n    app.azure_allow_email_linking_default: false\n"))


if __name__ == "__main__":
    unittest.main()
