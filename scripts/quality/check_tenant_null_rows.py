#!/usr/bin/env python3
"""
check_tenant_null_rows.py — Gate 72: rows without a tenant that the TenantFilter hides.

Two defects share one cause. TenantFilter adds `tenant_id = <current>` to every
query on a tenant-owned entity. A row whose tenant is NULL is therefore
invisible to every organisation, unless the entity implements
SharedTenantRowsInterface (then the filter adds `OR tenant_id IS NULL`).

  shared-query   A query asks for "own OR global" rows (`x.tenant = :t OR
                 x.tenant IS NULL`) on an entity without the interface. Under
                 the filter the global branch never matches: either the
                 entity really has shared rows (implement the interface and
                 check the voter) or the branch is dead (drop it).

  new-no-tenant  A controller or service creates a tenant-owned entity
                 (`new X()`) and the method never calls setTenant(). The row
                 is saved without a tenant and disappears for its own
                 organisation (seen with WorkflowInstance, RiskAppetite).

Findings are identified as `<file>::<rule>::<detail>` so line shifts never
invalidate the baseline. A baseline entry needs a reason after `#`.
Stale baseline entries are reported but do not fail.

Usage: check_tenant_null_rows.py [--baseline FILE] [--write-baseline FILE] [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"

# "own OR global" in every spelling: DQL/SQL string (any case, parentheses, tenant_id, IDENTITY(x.tenant)),
# QueryBuilder `->orWhere('x.tenant IS NULL')` and `orX(…, $expr->isNull('x.tenant'))`.
SHARED_OR_NULL = re.compile(
    r"(?:IDENTITY\(\s*)?(\w+)\.tenant(?:_id)?\s*\)?\s*=\s*:\w+[\s)]+OR[\s(]+(?:IDENTITY\(\s*)?\1\.tenant(?:_id)?\s*\)?\s+IS\s+NULL"
    r"|(?:IDENTITY\(\s*)?(\w+)\.tenant(?:_id)?\s*\)?\s+IS\s+NULL[\s)]+OR[\s(]+(?:IDENTITY\(\s*)?\2\.tenant(?:_id)?\s*\)?\s*=\s*:\w+"
    r"|orWhere\(\s*['\"]\w+\.tenant\s+IS\s+NULL"
    r"|orX\([^;]*?isNull\(\s*['\"]\w+\.tenant['\"]",
    re.I | re.S,
)
# Code that is not run: tenant calls named in comments do not count.
RE_COMMENTS = re.compile(r"/\*.*?\*/|(?<![:\"'])//[^\n]*", re.S)
# Not application code that creates records for a request (entities, console, fixtures, restore, migrations).
SKIP_NEW = ("src/Entity/", "src/Command/", "src/DataFixtures/", "src/Service/Restore/", "src/Migrations/")
# `new Risk()`, `new Risk;`, `new \\App\\Entity\\Risk()`
NEW_CLASS = re.compile(r"\bnew\s+\\?(?:\w+\\)*(\w+)\s*[(;]")
ASSIGNED_NEW = re.compile(r"(\$\w+(?:->\w+)?)\s*=\s*new\s+\\?(?:\w+\\)*(\w+)\s*[(;]")


def entity_classes() -> tuple[set[str], set[str]]:
    """(tenant-owned entities without the interface, entities with it)."""
    owned: set[str] = set()
    shared: set[str] = set()
    for path in (SRC / "Entity").rglob("*.php"):
        text = path.read_text(encoding="utf-8", errors="ignore")
        # TenantFilter never filters User (see TenantFilter::addFilterConstraint).
        if path.stem == "User" or not re.search(r"private \?Tenant \$tenant\b", text):
            continue
        if "SharedTenantRowsInterface" in text:
            shared.add(path.stem)
        else:
            owned.add(path.stem)
    return owned, shared


def method_bodies(text: str):
    for match in re.finditer(r"function (\w+)\([^)]*\)[^{;]*\{", text):
        start = match.end()
        depth, i = 1, start
        while depth and i < len(text):
            if text[i] == "{":
                depth += 1
            elif text[i] == "}":
                depth -= 1
            i += 1
        yield match.group(1), text[start:i]


def find() -> list[str]:
    owned, shared = entity_classes()
    found: list[str] = []
    for path in sorted(SRC.rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="ignore")

        if SHARED_OR_NULL.search(text):
            repo = re.match(r"(\w+)Repository$", path.stem)
            entity = repo.group(1) if repo and "/Repository/" in rel else None
            if entity is None or entity not in shared:
                found.append(f"{rel}::shared-query::{entity or 'unknown-entity'}")

        if rel.startswith(SKIP_NEW):
            continue
        for method, body in method_bodies(RE_COMMENTS.sub("", text)):
            # per variable: `$a = new Risk(); $b = new Asset(); $a->setTenant()` leaves $b without tenant
            assigned = {}
            for var, cls in ASSIGNED_NEW.findall(body):
                assigned.setdefault(cls, []).append(var)
            for cls in sorted(set(NEW_CLASS.findall(body))):
                if cls not in owned:
                    continue
                vars_ = assigned.get(cls, [])
                if vars_:
                    ok = all(re.search(re.escape(v) + r"(?!\w)[^;]*?->\s*setTenant\(", body) for v in vars_)
                else:
                    ok = "setTenant(" in body
                if not ok:
                    found.append(f"{rel}::new-no-tenant::{method}::{cls}")
    return sorted(set(found))


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        line.split("#", 1)[0].strip()
        for line in path.read_text(encoding="utf-8").splitlines()
        if line.split("#", 1)[0].strip()
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--write-baseline", type=Path)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    found = find()
    if args.write_baseline:
        old = {}
        if args.write_baseline.exists():
            for line in args.write_baseline.read_text(encoding="utf-8").splitlines():
                key = line.split("#", 1)[0].strip()
                if key:
                    old[key] = line
        lines = [old.get(f, f"{f}  # TODO reason") for f in found]
        args.write_baseline.write_text(
            "# Gate 72 baseline — check_tenant_null_rows.py. One entry per line, reason after '#'.\n"
            + "\n".join(lines) + "\n",
            encoding="utf-8",
        )
        print(f"Wrote {len(found)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [f for f in found if f not in baseline]
    stale = sorted(baseline - set(found))
    if stale:
        print("Stale baseline entries (fixed or removed — drop them):")
        for entry in stale:
            print(f"  {entry}")
    if new:
        print("Rows without a tenant that the TenantFilter hides:")
        for entry in new:
            print(f"  {entry}")
        print(
            "\nSet the tenant on new tenant-owned records; for 'own OR global' queries either"
            "\nimplement SharedTenantRowsInterface (and check the voter) or drop the dead branch."
        )
        return 1
    if not args.quiet:
        print(f"OK — {len(found)} baselined, no new tenant-NULL row patterns.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
