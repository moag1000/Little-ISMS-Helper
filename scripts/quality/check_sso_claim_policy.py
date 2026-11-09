#!/usr/bin/env python3
"""
check_sso_claim_policy.py — Gate 93: every SSO login goes through SsoClaimPolicy.

S9 (2026-11): the three SSO paths each had their own, partly missing checks —
SAML took entity id and ACS from the Host header and accepted unsolicited or
replayed responses, env Azure OAuth never compared `tid` and linked accounts by
an unverified e-mail by default, generic OIDC accepted ID tokens without
issuer, `exp` or `nonce` and let UserInfo overwrite `sub`/`email`. Now one class
holds the rules (`App\\Service\\Sso\\SsoClaimPolicy`), SAML messages go through
`App\\Security\\SamlMessageGuard`, and `tests/Security/Sso/SsoClaimContractTest`
pins the behaviour per authenticator.

The gate fails when
  1. a custom authenticator of a firewall in config/packages/security.yaml that
     is not on NON_SSO does not use SsoClaimPolicy — itself or through a class
     it imports from App\\ (e.g. GenericOidcAuthenticator → OidcAuthenticationFlow);
  2. `->processResponse()` is called without the request id (src/) —
     InResponseTo unchecked, unsolicited responses accepted;
  3. `getSchemeAndHttpHost()` builds a URL in the SSO code (src/Security,
     src/Service/Sso, SecurityController) — the Host header is the client's;
  4. claims are combined with `array_merge(` in src/Service/Sso — later
     (unsigned UserInfo) values would overwrite signed ones;
  5. e-mail linking for the env Azure logins is on by default
     (`app.azure_allow_email_linking_default: true`, `AZURE_ALLOW_EMAIL_LINKING=true`
     in .env / .env.prod.example, or a `$allowEmailLinking = true` default).

    python3 scripts/quality/check_sso_claim_policy.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SECURITY_YAML = ROOT / "config/packages/security.yaml"
POLICY = "SsoClaimPolicy"
# Authenticators that take no identity claims from an outside IdP.
NON_SSO = {
    "App\\Security\\ApiTokenAuthenticator",
}
HOST_HEADER_SCOPES = ("src/Security/", "src/Service/Sso/", "src/Controller/SecurityController.php")

AUTH_BLOCK = re.compile(r"custom_authenticators:\s*\n((?:\s+-\s*[\w\\]+\s*\n)+)")
AUTH_ITEM = re.compile(r"-\s*([\w\\]+)")
USE_APP = re.compile(r"^use\s+(App\\[\w\\]+);", re.M)
PROCESS_RESPONSE_EMPTY = re.compile(r"->processResponse\(\s*\)")
ARRAY_MERGE = re.compile(r"\barray_merge\(")
LINKING_DEFAULT_TRUE = re.compile(r"\$allowEmailLinking\s*=\s*true")


def class_file(fqcn: str) -> Path:
    return ROOT / "src" / (fqcn.removeprefix("App\\").replace("\\", "/") + ".php")


def uses_policy(fqcn: str) -> bool:
    path = class_file(fqcn)
    if not path.is_file():
        return False
    text = path.read_text(encoding="utf-8", errors="replace")
    if POLICY in text:
        return True
    for dep in USE_APP.findall(text):
        dep_path = class_file(dep)
        if dep_path.is_file() and POLICY + "::" in dep_path.read_text(encoding="utf-8", errors="replace"):
            return True
    return False


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def check() -> tuple[list[str], int]:
    failures: list[str] = []
    checked = 0

    yaml_text = SECURITY_YAML.read_text(encoding="utf-8")
    authenticators: list[str] = []
    for block in AUTH_BLOCK.finditer(yaml_text):
        authenticators += AUTH_ITEM.findall(block.group(1))
    for fqcn in sorted(set(authenticators) - NON_SSO):
        checked += 1
        if not class_file(fqcn).is_file():
            failures.append(f"config/packages/security.yaml: authenticator {fqcn} not found in src/")
        elif not uses_policy(fqcn):
            failures.append(f"{class_file(fqcn).relative_to(ROOT).as_posix()}: SSO authenticator does not use "
                            f"{POLICY} (itself or through an App\\ class it imports)")

    for path in sorted((ROOT / "src").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="replace")
        checked += 1
        for m in PROCESS_RESPONSE_EMPTY.finditer(text):
            failures.append(f"{rel}:{line_of(text, m.start())}: processResponse() without the request id "
                            f"(SamlMessageGuard::consumeRequestId())")
        if rel.startswith(HOST_HEADER_SCOPES):
            for m in re.finditer(r"getSchemeAndHttpHost\(\)", text):
                failures.append(f"{rel}:{line_of(text, m.start())}: SSO URL from the Host header — use DEFAULT_URI "
                                f"(SamlAuthFactory::baseUrl())")
            for m in LINKING_DEFAULT_TRUE.finditer(text):
                failures.append(f"{rel}:{line_of(text, m.start())}: e-mail linking on by default")
        if rel.startswith("src/Service/Sso/"):
            for m in ARRAY_MERGE.finditer(text):
                failures.append(f"{rel}:{line_of(text, m.start())}: array_merge() on claims — use "
                                f"{POLICY}::mergeUserInfo()")

    services = (ROOT / "config/services.yaml").read_text(encoding="utf-8")
    m = re.search(r"app\.azure_allow_email_linking_default:\s*true", services)
    if m:
        failures.append(f"config/services.yaml:{line_of(services, m.start())}: e-mail linking on by default")
    for env in (".env", ".env.prod.example"):
        path = ROOT / env
        if path.is_file():
            text = path.read_text(encoding="utf-8")
            m = re.search(r"^AZURE_ALLOW_EMAIL_LINKING\s*=\s*true", text, re.M | re.I)
            if m:
                failures.append(f"{env}:{line_of(text, m.start())}: e-mail linking on by default")

    return failures, checked


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    failures, checked = check()
    if failures:
        print("Gate 93 (SSO claim policy) failed:")
        for f in failures:
            print("  " + f)
        return 1
    if not args.quiet:
        print(f"Gate 93 (SSO claim policy): OK ({checked} files/authenticators checked)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
