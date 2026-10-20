#!/usr/bin/env python3
"""
check_json_catch_throwable.py — JSON/AJAX controller actions must catch
\\Throwable, not \\Exception.

A JSON endpoint wraps its work in try/catch so the caller always gets a JSON
envelope. `catch (\\Exception $e)` lets every \\Error through — TypeError on a
null relation, ArgumentCountError, a call to a protected method — and the
fetch() caller receives Symfony's HTML error page instead of JSON, which the
frontend then fails to parse. Catching \\Throwable keeps the contract.

Scope: public methods in src/Controller that return JsonResponse (by return
type) or build one (`new JsonResponse(` / `$this->json(`). A catch list that
names bare `Exception` / `\\Exception` is flagged; specific exception types
(`InvalidArgumentException`, `Foo\\Exception`) are fine.

Escape hatch: put `// @catch-exception-intentional: <reason>` on the line
above the catch when an \\Error must deliberately propagate.

Baseline entries are `<relative-path>:<method>` so unrelated edits that shift
line numbers do not churn the baseline.

Exit-codes: 0 clean, 1 violations, 2 I/O error.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

RE_PUBLIC_METHOD = re.compile(
    r"public\s+function\s+(\w+)\s*\((?P<params>[^{;]*?)\)\s*(?::\s*(?P<ret>[\w\\|?]+))?\s*\{"
)
RE_CATCH = re.compile(r"catch\s*\((?P<types>[^)$]*)")
RE_BARE_EXCEPTION = re.compile(r"(?<![\w\\])\\?Exception\b")
EXEMPT_MARKER = "@catch-exception-intentional"


def strip_comments(text: str) -> str:
    """Blank out comments (keeping newlines and the exemption marker lines)."""
    def keep_newlines(m: re.Match[str]) -> str:
        return re.sub(r"[^\n]", " ", m.group(0))

    text = re.sub(r"/\*.*?\*/", keep_newlines, text, flags=re.S)

    def line_comment(m: re.Match[str]) -> str:
        if EXEMPT_MARKER in m.group(0):
            return m.group(0)
        return " " * len(m.group(0))

    return re.sub(r"(?<![:\"'])//[^\n]*", line_comment, text)


def method_body_end(text: str, open_brace: int) -> int:
    depth = 0
    for i in range(open_brace, len(text)):
        c = text[i]
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return i
    return len(text)


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        raw = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "catch" not in raw:
        return []
    text = strip_comments(raw)
    out: list[tuple[int, str]] = []
    for m in RE_PUBLIC_METHOD.finditer(text):
        open_brace = m.end() - 1
        end = method_body_end(text, open_brace)
        body = text[open_brace:end]
        ret = m.group("ret") or ""
        is_json = (
            "JsonResponse" in ret
            or "new JsonResponse(" in body
            or "$this->json(" in body
        )
        if not is_json:
            continue
        for c in RE_CATCH.finditer(body):
            if not RE_BARE_EXCEPTION.search(c.group("types")):
                continue
            offset = open_brace + c.start()
            prev_end = text.rfind("\n", 0, offset)
            prev_start = text.rfind("\n", 0, prev_end) + 1 if prev_end > 0 else 0
            if EXEMPT_MARKER in text[prev_start:prev_end]:
                continue
            out.append((text.count("\n", 0, offset) + 1, m.group(1)))
    return out


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        s = raw.strip()
        if s and not s.startswith("#"):
            out.add(s)
    return out


def _rel(p: Path) -> str:
    try:
        return p.resolve().relative_to(ROOT).as_posix()
    except ValueError:
        return p.name


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--paths", type=Path, nargs="*", default=None,
                    help="Files to scan instead of src/Controller (used by tests).")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if args.paths:
        files = [p for p in args.paths if p.is_file()]
    else:
        if not CONTROLLER_DIR.is_dir():
            print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
            return 2
        files = sorted(p for p in CONTROLLER_DIR.rglob("*.php") if p.is_file())

    violations: list[tuple[str, int, str]] = []
    for f in files:
        for ln, method in scan(f):
            violations.append((_rel(f), ln, method))

    if args.write_baseline is not None:
        keys = sorted({f"{p}:{m}" for p, _ln, m in violations})
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_json_catch_throwable.py baseline\n# Format: <relative-path>:<method>\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_json_catch_throwable: wrote {len(keys)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{v[0]}:{v[2]}" not in baseline]
    total = len(violations)

    if not new:
        print(f"check_json_catch_throwable: OK ({total} occurrence(s), all baselined)")
        return 0

    print("check_json_catch_throwable: VIOLATIONS\n")
    for path, ln, method in new:
        print(f"FAIL {path}:{ln}: {method}() catches \\Exception in a JSON action")
    print(f"\ncheck_json_catch_throwable: {len(new)} new violation(s) ({total - len(new)} baselined, {total} total).")
    print("Fix: catch \\Throwable so \\Error still yields a JSON response, OR mark the catch with")
    print("`// @catch-exception-intentional: <reason>` on the line above.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
