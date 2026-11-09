#!/usr/bin/env python3
"""
check_sso_claim_policy.py — Gate 93 (config part): SSO e-mail linking stays off by default.

S9 (2026-11): the three SSO paths each had their own, partly missing checks. Now one class
holds the rules (`App\\Service\\Sso\\SsoClaimPolicy`), SAML messages go through
`App\\Security\\SamlMessageGuard`, and `tests/Security/Sso/SsoClaimContractTest` pins the
behaviour per authenticator.

Since T3 the code part is the PHPStan rule tests/PHPStan/Rule/Gate/SsoClaimPolicyRule.php
(every authenticator class found by type and calling its listed SsoClaimPolicy checks itself,
processResponse() with the request id, no Host header in SSO code, no hand-merged claims,
no `$allowEmailLinking = true` default). The authenticators of every firewall in every
environment are checked by SsoClaimContractTest on the resolved security config.

This script keeps what PHP cannot see: e-mail linking for the env Azure logins is on by default
when
  * config/services.yaml sets `app.azure_allow_email_linking_default` to a true value, or
  * .env / .env.prod.example set `AZURE_ALLOW_EMAIL_LINKING` to a true value
(true/1/yes/on in any case, quoted or not).

    python3 scripts/quality/check_sso_claim_policy.py [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TRUE_VALUE = r"""\s*["']?\s*(?:true|1|yes|on)\s*["']?\s*(?:#.*)?$"""
SERVICES = re.compile(r"^\s*app\.azure_allow_email_linking_default\s*:" + TRUE_VALUE, re.M | re.I)
ENV = re.compile(r"^\s*(?:export\s+)?AZURE_ALLOW_EMAIL_LINKING\s*=" + TRUE_VALUE, re.M | re.I)


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def check(root: Path = ROOT) -> list[str]:
    failures: list[str] = []
    services = root / "config/services.yaml"
    if services.is_file():
        text = services.read_text(encoding="utf-8")
        for m in SERVICES.finditer(text):
            failures.append(f"config/services.yaml:{line_of(text, m.start())}: e-mail linking on by default")
    for env in (".env", ".env.prod.example"):
        path = root / env
        if path.is_file():
            text = path.read_text(encoding="utf-8")
            for m in ENV.finditer(text):
                failures.append(f"{env}:{line_of(text, m.start())}: e-mail linking on by default")
    return failures


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    failures = check()
    if failures:
        print("Gate 93 (SSO claim policy, config) failed:")
        for f in failures:
            print("  " + f)
        return 1
    if not args.quiet:
        print("Gate 93 (SSO claim policy, config): OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
