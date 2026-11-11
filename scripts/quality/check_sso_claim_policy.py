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
  * config/services.yaml sets `app.azure_allow_email_linking_default` to anything but the literal `false`
    (an env expression or `!php/const` could flip it), or
  * any committed .env* file in the repo root, or an `environment:` entry / `${…:-true}` default in
    docker-compose*.yml / compose*.yaml (root and docker/), sets `AZURE_ALLOW_EMAIL_LINKING` to a true value
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
# The parameter must be the literal `false` (T4: `%env(bool:default::X)%` or `!php/const true` are not literal).
SERVICES_PARAM = re.compile(r"^\s*app\.azure_allow_email_linking_default\s*:(.*)$", re.M)
SERVICES_FALSE = re.compile(r"""^\s*false\s*(?:#.*)?$""")
ENV = re.compile(r"^\s*(?:export\s+)?AZURE_ALLOW_EMAIL_LINKING\s*=" + TRUE_VALUE, re.M | re.I)
# docker-compose `environment:` as list (`- X=true`) or map (`X: "true"`), and a `${X:-true}` default.
COMPOSE = re.compile(r"""^\s*(?:-\s*)?["']?AZURE_ALLOW_EMAIL_LINKING["']?\s*[:=]""" + TRUE_VALUE
                     + r"""|\$\{AZURE_ALLOW_EMAIL_LINKING:?-\s*(?:true|1|yes|on)\s*\}""", re.M | re.I)


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def env_files(root: Path) -> list[Path]:
    """Every committed `.env*` file of the repo root (`.env.local`, `.env.*.local` are machine-local)."""
    return sorted(p for p in root.glob(".env*") if p.is_file() and not p.name.endswith(".local"))


def compose_files(root: Path) -> list[Path]:
    found: list[Path] = []
    for base in (root, root / "docker"):
        for pattern in ("docker-compose*.yml", "docker-compose*.yaml", "compose*.yml", "compose*.yaml"):
            found += [p for p in base.glob(pattern) if p.is_file()]
    return sorted(set(found))


def check(root: Path = ROOT) -> list[str]:
    failures: list[str] = []
    services = root / "config/services.yaml"
    if services.is_file():
        text = services.read_text(encoding="utf-8")
        for m in SERVICES_PARAM.finditer(text):
            if not SERVICES_FALSE.match(m.group(1)):
                failures.append(f"config/services.yaml:{line_of(text, m.start())}: "
                                "app.azure_allow_email_linking_default must be the literal `false`")
    for path, pattern in [(p, ENV) for p in env_files(root)] + [(p, COMPOSE) for p in compose_files(root)]:
        text = path.read_text(encoding="utf-8", errors="replace")
        for m in pattern.finditer(text):
            failures.append(f"{path.relative_to(root).as_posix()}:{line_of(text, m.start())}: "
                            "e-mail linking on by default")
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
