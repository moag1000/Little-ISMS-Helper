#!/usr/bin/env python3
"""
check_tenant_pin_restore.py — Gate 97: an organisation pinned in TenantContext is put back.

S23 (2026-11): the Messenger worker runs scheduled commands and async jobs in ONE
container with one TenantContext and one EntityManager. Scheduled reports, the KPI
snapshot and the async-job handler pinned an organisation and never released it, so
the next command ran with `tenant_id = <that org>` ANDed onto every query — the
nightly instance backup held one organisation, reminders and SLA watchers missed
the others. Scoped work now goes through `TenantContext::runAs()` /
`runWithoutOrganisation()`, which restore the previous state.

The gate fails on every call of
    ->setCurrentTenant(  ->setCurrentTenantById(  ->setNoOrganisation(
in src/ that
  * is not inside a `try { … }` of the same function whose `finally { … }` puts the
    context back (mentions `tenantContext`, `TenantContext`, `$restore…(` or
    `TenantFilterState::restore`), and
  * is not itself the restore (inside such a `finally`), and
  * carries no `// @tenant-pin-ok: <reason>` on its line or the line above.

No baseline: the existing pins were converted in S23.

    python3 scripts/quality/check_tenant_pin_restore.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PIN = re.compile(r"->(setCurrentTenant|setCurrentTenantById|setNoOrganisation)\s*\(")
MARKER = re.compile(r"@tenant-pin-ok:[ \t]*\S")
RESTORE = re.compile(r"tenantContext|TenantContext|\$restore\w*\s*\(|TenantFilterState::restore")
FUNCTION_OPENER = re.compile(r"\bfunction\b[^{;]*$|\bfn\b[^{;]*$")


def strip_comments_and_strings(text: str) -> str:
    """Blank out comments and string literals (same length, newlines kept)."""
    out = []
    i, n = 0, len(text)
    while i < n:
        c = text[i]
        two = text[i:i + 2]
        if two == "//" or c == "#" and text[i:i + 2] != "#[":
            j = text.find("\n", i)
            j = n if j == -1 else j
            out.append(" " * (j - i))
            i = j
        elif two == "/*":
            j = text.find("*/", i + 2)
            j = n if j == -1 else j + 2
            out.append(re.sub(r"[^\n]", " ", text[i:j]))
            i = j
        elif c in "'\"":
            j = i + 1
            while j < n and text[j] != c:
                j += 2 if text[j] == "\\" else 1
            j = min(j + 1, n)
            out.append(c + re.sub(r"[^\n]", " ", text[i + 1:j - 1]) + (c if j - 1 > i else ""))
            i = j
        else:
            out.append(c)
            i += 1
    return "".join(out)


def blocks(code: str) -> list[tuple[int, int, str]]:
    """(open, close, kind) for every {...}; kind = try | finally | function | other."""
    result: list[tuple[int, int, str]] = []
    stack: list[tuple[int, str]] = []
    for pos, ch in enumerate(code):
        if ch == "{":
            head = code[max(0, pos - 300):pos]
            line_head = head.rsplit(";", 1)[-1].rsplit("}", 1)[-1]
            if re.search(r"\btry\s*$", head):
                kind = "try"
            elif re.search(r"\bfinally\s*$", head):
                kind = "finally"
            elif FUNCTION_OPENER.search(line_head):
                kind = "function"
            else:
                kind = "other"
            stack.append((pos, kind))
        elif ch == "}" and stack:
            start, kind = stack.pop()
            result.append((start, pos, kind))
    return result


def finally_after(code: str, close: int, all_blocks: list[tuple[int, int, str]]) -> tuple[int, int] | None:
    """The finally block that belongs to the try ending at `close` (catch blocks skipped)."""
    pos = close + 1
    by_open = {b[0]: b for b in all_blocks}
    while True:
        m = re.match(r"\s*catch\s*\([^)]*\)\s*", code[pos:])
        if not m:
            break
        brace = pos + m.end()
        if brace >= len(code) or code[brace] != "{" or brace not in by_open:
            return None
        pos = by_open[brace][1] + 1
    m = re.match(r"\s*finally\s*", code[pos:])
    if not m:
        return None
    brace = pos + m.end()
    block = by_open.get(brace)
    return (block[0], block[1]) if block else None


def is_restored(code: str, pos: int, all_blocks: list[tuple[int, int, str]]) -> bool:
    enclosing = sorted((b for b in all_blocks if b[0] < pos < b[1]), key=lambda b: b[0], reverse=True)
    for start, end, kind in enclosing:
        if kind == "function":
            return False
        if kind == "finally":
            return True  # the restore itself
        if kind == "try":
            fin = finally_after(code, end, all_blocks)
            if fin and RESTORE.search(code[fin[0]:fin[1]]):
                return True
    return False


def scan_text(text: str) -> list[tuple[int, str]]:
    """(line, method) of every pin without restore or marker."""
    if not PIN.search(text):
        return []
    code = strip_comments_and_strings(text)
    lines = text.split("\n")
    all_blocks = blocks(code)
    found: list[tuple[int, str]] = []
    for m in PIN.finditer(code):
        line = code.count("\n", 0, m.start())
        context = lines[line] + "\n" + (lines[line - 1] if line > 0 else "")
        if MARKER.search(context) or is_restored(code, m.start(), all_blocks):
            continue
        found.append((line + 1, m.group(1)))
    return found


def check(paths: list[Path]) -> list[str]:
    failures: list[str] = []
    for path in paths:
        for line, method in scan_text(path.read_text(encoding="utf-8", errors="replace")):
            failures.append(
                f"{path.relative_to(ROOT)}:{line}: ->{method}() without restore — use TenantContext::runAs()/"
                "runWithoutOrganisation(), or try/finally that puts the context back, "
                "or `// @tenant-pin-ok: <reason>`"
            )
    return failures


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--quiet", action="store_true")
    parser.add_argument("paths", nargs="*", type=Path)
    args = parser.parse_args()
    paths = [p.resolve() for p in args.paths] or sorted((ROOT / "src").rglob("*.php"))
    failures = check(paths)
    if failures:
        print("Gate 97 — organisation pinned without restore (S23):")
        for f in failures:
            print("  " + f)
        return 1
    if not args.quiet:
        print("Gate 97 OK — every organisation pin is restored or justified.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
