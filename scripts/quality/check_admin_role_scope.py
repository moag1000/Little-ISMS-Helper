#!/usr/bin/env python3
"""
check_admin_role_scope.py — Admin controllers declare consistent role-scope guard.

Phase-7 CI gate of the Role-Scope Architecture rollout
(`docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).

THE CONTRACT
------------
Every Admin controller — anything under `src/Controller/Admin/` plus the
top-level `src/Controller/Admin*Controller.php` files — MUST declare a
class-level `#[IsGranted(...)]` attribute whose argument is ONE of:

  - `'ROLE_ADMIN'`                              legacy baseline (acceptable
                                                until Phase 4 sweeps it)
  - `'ROLE_SUPER_ADMIN'`                        global-only controllers
  - `TenantScopedAdminVoter::ADMIN_OWN_TENANT`  preferred (own-tenant admin)
  - `TenantScopedAdminVoter::ADMIN_ANY_TENANT`  super-admin cross-tenant ops
  - `TenantScopedAdminVoter::ADMIN_GLOBAL_OP`   super-admin global operations
  - `TenantScopedAdminVoter::ADMIN_HOLDING_READ` holding-tree read access

Equivalent string literals are also accepted (e.g. `'ADMIN_OWN_TENANT'`).

Failure modes:
  - Class lacks ANY class-level `#[IsGranted]`                       → FAIL
  - Class-level attribute is none of the accepted set
    (e.g. `ROLE_MANAGER`, `ROLE_USER`, permission-strings like
    `'COMPLIANCE_VIEW'`)                                             → FAIL

Method-level `#[IsGranted]` is allowed regardless of value — this gate
only checks the class-level guard. A future tightening might also lint
method-level `ROLE_ADMIN` as a redundancy warning; for now we just
record the class-level state.

HOW TO ADD A NEW ADMIN CONTROLLER
---------------------------------
Add a class-level attribute. The most common case is:

    use App\\Security\\Voter\\TenantScopedAdminVoter;
    use Symfony\\Component\\Security\\Http\\Attribute\\IsGranted;

    #[Route('/admin/foo')]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    final class FooController extends AbstractController { ... }

For genuinely global operations (license, schema-reconcile, tour-content,
setup-wizard), use:

    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]

HOW TO MIGRATE OFF THE BASELINE
-------------------------------
The baseline file
`scripts/quality/baselines/admin_role_scope.txt` captures the pre-sweep
state. To shrink it during Phase 4:

  1. Pick a controller off the baseline (or a brand-new one).
  2. Swap class-level `ROLE_ADMIN` for the appropriate
     `TenantScopedAdminVoter::ADMIN_*` attribute.
  3. Remove that file's line(s) from the baseline.
  4. Run `python3 scripts/quality/check_admin_role_scope.py` locally —
     should exit 0 cleanly.
  5. Ship the PR.

CLI
---
    --baseline <path>          known-violation file (`exit 0` if all match)
    --write-baseline <path>    regenerate baseline from current state
    --quiet                    one-line success output

Exit codes: 0 clean / baselined, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"
ADMIN_SUBDIR = CONTROLLER_DIR / "Admin"

# Accepted class-level IsGranted argument values (normalized — quotes stripped,
# constant prefixes stripped). The voter class constants resolve to the bare
# attribute strings.
ACCEPTED_ATTRIBUTES = frozenset({
    "ROLE_ADMIN",
    "ROLE_SUPER_ADMIN",
    "ADMIN_OWN_TENANT",
    "ADMIN_ANY_TENANT",
    "ADMIN_GLOBAL_OP",
    "ADMIN_HOLDING_READ",
})

# Match a top-level class declaration. PHP convention places top-level
# `class`/`final class`/`abstract class` at column 0; inner-block matches
# (anonymous classes, comments mentioning the word "class") are excluded
# by anchoring to start-of-line.
RE_CLASS_TOP = re.compile(
    r"^(?:final\s+|abstract\s+)?class\s+(\w+)",
    re.MULTILINE,
)

RE_ISGRANTED = re.compile(r"#\[\s*IsGranted\s*\(\s*([^)]*?)\s*\)\s*\]")


def admin_controller_files() -> list[Path]:
    """All Admin controller PHP files in scope."""
    out: list[Path] = []
    if ADMIN_SUBDIR.is_dir():
        out.extend(sorted(ADMIN_SUBDIR.rglob("*.php")))
    # Top-level `src/Controller/Admin*Controller.php` (e.g. AdminBackupController)
    if CONTROLLER_DIR.is_dir():
        for f in sorted(CONTROLLER_DIR.glob("Admin*Controller.php")):
            if f.is_file():
                out.append(f)
    # Dedupe + keep deterministic order
    seen: set[Path] = set()
    deduped: list[Path] = []
    for f in out:
        rp = f.resolve()
        if rp in seen:
            continue
        seen.add(rp)
        deduped.append(f)
    return deduped


def normalize_attr_arg(raw: str) -> str:
    """Reduce an IsGranted argument to its bare attribute string.

    Inputs we have to handle:
      'ROLE_ADMIN'                              → ROLE_ADMIN
      "ROLE_ADMIN"                              → ROLE_ADMIN
      TenantScopedAdminVoter::ADMIN_OWN_TENANT  → ADMIN_OWN_TENANT
      \\App\\Security\\Voter\\TenantScopedAdminVoter::ADMIN_GLOBAL_OP
                                                → ADMIN_GLOBAL_OP
      'attribute: ADMIN_OWN_TENANT'             → ADMIN_OWN_TENANT  (named-arg form)
    Anything else: returned trimmed (will fail the accepted-set check).
    """
    s = raw.strip()
    # Drop any leading `attribute:` named-arg (Symfony 7+)
    s = re.sub(r"^attribute\s*:\s*", "", s)
    # Strip outer quotes (single or double)
    if (s.startswith("'") and s.endswith("'")) or (s.startswith('"') and s.endswith('"')):
        s = s[1:-1].strip()
    # Strip class-constant prefix `Foo::` (and namespace path before it)
    if "::" in s:
        s = s.rsplit("::", 1)[1].strip()
    return s


def _preamble_start(text: str, class_offset: int) -> int:
    """Return the offset where the class-level attribute window begins.

    The window starts at the position right after the previous statement
    boundary (`;`, `}`, `*/`) or the start of the file. Anything between
    that boundary and the class keyword is the attribute block.
    """
    # Look for the latest of `;`, `}`, or `*/` (end of doc-block) before
    # the class. Returns the offset *just after* it.
    best = 0
    for needle in (";", "}", "*/"):
        idx = text.rfind(needle, 0, class_offset)
        if idx > best:
            best = idx + len(needle)
    return best


def find_class_level_isgranted(text: str) -> list[tuple[str, int]]:
    """Return list of (normalized_attribute, line_number_of_class_keyword)
    for every top-level class declaration in the file.

    If a class has NO class-level IsGranted, returns ("__NONE__", line).
    """
    results: list[tuple[str, int]] = []
    for m in RE_CLASS_TOP.finditer(text):
        class_kw_offset = m.start()
        line_no = text.count("\n", 0, class_kw_offset) + 1

        preamble = text[_preamble_start(text, class_kw_offset):class_kw_offset]
        attrs = RE_ISGRANTED.findall(preamble)
        if not attrs:
            results.append(("__NONE__", line_no))
            continue
        for raw in attrs:
            results.append((normalize_attr_arg(raw), line_no))
    return results


def scan(path: Path) -> list[tuple[int, str, str]]:
    """Return list of (line, kind, snippet) violations for one file.

    Kinds:
      - 'missing'  no class-level IsGranted at all
      - 'wrong:X'  class-level IsGranted argument X not in accepted set
    """
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []

    out: list[tuple[int, str, str]] = []
    for attr, ln in find_class_level_isgranted(text):
        if attr == "__NONE__":
            out.append((ln, "missing", "no class-level #[IsGranted]"))
            continue
        if attr not in ACCEPTED_ATTRIBUTES:
            out.append((ln, f"wrong:{attr}", f"class-level #[IsGranted({attr!r})] not in accepted set"))
    return out


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def _rel(p: Path) -> Path:
    try:
        return p.relative_to(ROOT)
    except ValueError:
        return Path(p.name)


def main() -> int:
    ap = argparse.ArgumentParser(description="Admin controllers must declare a "
                                              "consistent role-scope guard "
                                              "(Phase-7 CI gate).")
    ap.add_argument("--baseline", type=Path, default=None,
                    help="path to baseline file (violations listed here pass)")
    ap.add_argument("--write-baseline", type=Path, default=None,
                    help="regenerate baseline from current state and exit 0")
    ap.add_argument("--quiet", action="store_true",
                    help="one-line success output")
    args = ap.parse_args()

    if not CONTROLLER_DIR.is_dir():
        print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
        return 2

    files = admin_controller_files()
    if not files:
        print("check_admin_role_scope: no admin controllers found — OK")
        return 0

    violations: list[tuple[Path, int, str, str]] = []
    for f in files:
        for ln, kind, snip in scan(f):
            violations.append((f, ln, kind, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_admin_role_scope.py baseline\n")
            fh.write("# Format: <relative-path>:<line>:<kind>\n")
            fh.write("# Drop a line and ship the PR after migrating that "
                     "controller to a TenantScopedAdminVoter attribute.\n")
            for path, ln, kind, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}:{kind}\n")
        print(f"check_admin_role_scope: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}:{v[2]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_admin_role_scope: OK — {len(files)} controllers scanned, "
                  f"{total} violation(s), {baselined} baselined.")
        else:
            print(f"check_admin_role_scope: OK ({total} total, all baselined)")
        return 0

    print("check_admin_role_scope: VIOLATIONS\n")
    for path, ln, kind, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: [{kind}] {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_admin_role_scope: {len(new)} new violation(s) "
          f"({baselined} baselined, {total} total).")
    print("Fix: add a class-level #[IsGranted(...)] with one of:")
    print("  'ROLE_ADMIN' | 'ROLE_SUPER_ADMIN' |")
    print("  TenantScopedAdminVoter::{ADMIN_OWN_TENANT,ADMIN_ANY_TENANT,"
          "ADMIN_GLOBAL_OP,ADMIN_HOLDING_READ}")
    print("See docs/superpowers/specs/2026-05-18-role-scope-architecture.md §5 phase 7.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
