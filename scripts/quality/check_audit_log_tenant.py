#!/usr/bin/env python3
"""
check_audit_log_tenant.py — Every `new AuditLog()` must set a tenant.

ISO 27001 Cl. 7.5.3 + multi-tenant data isolation require every audit-log
row to be scoped to a tenant. Direct instantiation of AuditLog without a
following `->setTenant(...)` call in the same method risks orphan rows
that leak across tenants in cross-tenant reports.

The canonical writer `src/Service/AuditLogger.php` is exempt — it's the
only place where the tenant is injected via TenantContext on the row.

Heuristic per method block:
  - Find `$x = new AuditLog();` (or chained variants).
  - Within the same brace-balanced method body, look for `->setTenant(`
    applied to that variable OR to a chained `(new AuditLog())->setTenant(...)`.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC_DIR = ROOT / "src"
ALLOWLIST = (SRC_DIR / "Service" / "AuditLogger.php",)

# Find lines instantiating AuditLog and capture optional `$varName =` prefix.
RE_NEW_AUDITLOG = re.compile(r"(?:\$(\w+)\s*=\s*)?new\s+AuditLog\s*\(")
# Method start
RE_FUNC = re.compile(r"function\s+\w+\s*\(")


def find_method_block(text: str, start: int) -> tuple[int, int]:
    """Return (block_start_offset, block_end_offset) for the method enclosing `start`."""
    # Walk backwards to find the most recent `function ... {`
    idx = start
    while idx > 0:
        m_iter = list(RE_FUNC.finditer(text, 0, idx))
        if not m_iter:
            return 0, len(text)
        m = m_iter[-1]
        # Find opening brace after function declaration
        brace = text.find("{", m.end())
        if brace == -1:
            return 0, len(text)
        # Brace-match
        depth = 1
        i = brace + 1
        while i < len(text) and depth > 0:
            c = text[i]
            if c == "{":
                depth += 1
            elif c == "}":
                depth -= 1
            i += 1
        return brace, i
    return 0, len(text)


def scan(path: Path) -> list[tuple[int, str]]:
    if path in ALLOWLIST or path.resolve() in [p.resolve() for p in ALLOWLIST]:
        return []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "new AuditLog(" not in text:
        return []

    out: list[tuple[int, str]] = []
    for m in RE_NEW_AUDITLOG.finditer(text):
        var = m.group(1)
        start = m.start()
        block_start, block_end = find_method_block(text, start)
        block = text[block_start:block_end]
        if var:
            # look for $var->setTenant(
            pattern = re.compile(r"\$" + re.escape(var) + r"\s*->\s*setTenant\s*\(")
            if pattern.search(block):
                continue
        else:
            # chained or anonymous - search the small context window for ->setTenant(
            tail = text[start:min(start + 600, block_end)]
            if "->setTenant(" in tail:
                continue
        ln = text.count("\n", 0, start) + 1
        # snippet
        line_start = text.rfind("\n", 0, start) + 1
        line_end = text.find("\n", start)
        if line_end < 0:
            line_end = len(text)
        out.append((ln, text[line_start:line_end].strip()[:160]))
    return out


def walk(root: Path) -> list[Path]:
    return sorted(p for p in root.rglob("*.php") if p.is_file())


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
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
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    violations: list[tuple[Path, int, str]] = []
    for f in walk(SRC_DIR):
        for ln, snip in scan(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_audit_log_tenant.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_audit_log_tenant: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_audit_log_tenant: OK — {total} new-AuditLog() call(s) outside writer, {baselined} baselined.")
        else:
            print(f"check_audit_log_tenant: OK ({total}, all baselined)")
        return 0

    print("check_audit_log_tenant: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    print(f"\ncheck_audit_log_tenant: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: call `->setTenant($this->tenantContext->getTenant())` after `new AuditLog()`, OR use AuditLogger service.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
