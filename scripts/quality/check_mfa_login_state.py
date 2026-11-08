#!/usr/bin/env python3
"""
check_mfa_login_state.py — Gate 91: the second factor has one state and no side door.

S8 (2026-11): "stay logged in", a second login in the same session and the
password re-authentication each skipped the TOTP code, because every path kept
its own session flags. Now one class holds the state of a login
(`App\\Security\\Mfa\\MfaSessionState`, bound to the person's id), every login of
the web firewall starts it (`MfaLoginListener`), and the enforcer fails closed.

The gate fails when
  1. a file in src/ other than MfaSessionState.php names a `_security.mfa_*`
     session key — a second writer or reader of the state;
  2. a file in src/ puts a security token into the token storage
     (`->setToken($x)` / `->setToken(new ...)`, not `null`) without going through
     MfaSessionState — a hand-made login that never asks for the second factor.
     A genuine exception carries `// @mfa-ok: <reason>` in the file;
  3. `'/setup'` is an always-allowed path in the MFA enforcers — after the
     installation is set up the wizard is ROLE_SUPER_ADMIN only, and a password
     alone must not reach it.

The behaviour itself is pinned by tests/Security/Mfa/ (every authenticator of
the main firewall, remember-me, second login, re-auth, fail-closed).

    python3 scripts/quality/check_mfa_login_state.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
STATE_CLASS = "src/Security/Mfa/MfaSessionState.php"
ENFORCERS = (
    "src/EventSubscriber/MfaEnforcerSubscriber.php",
    "src/EventSubscriber/MfaEnrollmentEnforcerSubscriber.php",
)
OPT_OUT = "@mfa-ok:"

SESSION_KEY = re.compile(r"""['"]_security\.mfa_\w*['"]""")
SET_TOKEN = re.compile(r"->setToken\(\s*(?!null\s*\))")
ALLOWED_LIST = re.compile(r"ALLOWED_PREFIXES\s*=\s*\[(.*?)\];", re.S)


def problems_in(rel: str, text: str) -> list[str]:
    found: list[str] = []
    if rel != STATE_CLASS:
        for m in SESSION_KEY.finditer(text):
            line = text.count("\n", 0, m.start()) + 1
            found.append(f"{rel}:{line}: session key {m.group(0)} outside MfaSessionState — use the class")
        if OPT_OUT not in text and "MfaSessionState" not in text:
            for m in SET_TOKEN.finditer(text):
                line = text.count("\n", 0, m.start()) + 1
                found.append(f"{rel}:{line}: token set by hand without MfaSessionState::begin() "
                             f"(or `// {OPT_OUT} <reason>`)")
    if rel in ENFORCERS:
        allowed = ALLOWED_LIST.search(text)
        if allowed and re.search(r"""['"]/setup['"]""", allowed.group(1)):
            line = text.count("\n", 0, allowed.start()) + 1
            found.append(f"{rel}:{line}: '/setup' always allowed while MFA is outstanding — only before setup")
    return found


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    failures: list[str] = []
    checked = 0
    for path in sorted((ROOT / "src").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        failures += problems_in(rel, path.read_text(encoding="utf-8", errors="replace"))
        checked += 1

    if failures:
        print("Gate 91 (MFA login state) failed:")
        for f in failures:
            print("  " + f)
        return 1
    if not args.quiet:
        print(f"Gate 91: {checked} files, one MFA state, no hand-made login.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
