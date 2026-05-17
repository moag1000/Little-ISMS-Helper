#!/usr/bin/env python3
r"""
check_currentuser_test_args.py — Heuristic test/controller arg-mismatch gate.

When a controller action is declared with `#[CurrentUser] User $user` (or
similar `#[CurrentUser] ?User $user`), direct unit tests that invoke the
action method MUST supply a positional value for `$user`. Forgetting it
leads to TypeError at test-run time AND hides the production behavior.

Heuristic:
  1. For each `src/Controller/**/*.php`, find action methods with at least
     one `#[CurrentUser]` parameter. Record (Controller::action, position-
     of-user-param).
  2. Grep `tests/**/*.php` for `->action(` calls and verify the call has at
     least `position+1` positional args (count top-level commas).
  3. Fail (soft) when args < position+1.

The check is approximate — it won't catch dynamic dispatch or PHPUnit data
providers — and has a baseline.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"
TEST_DIR = ROOT / "tests"

# Action declaration: `public function name(...)` capturing the params block
RE_ACTION = re.compile(
    r"public\s+function\s+(\w+)\s*\(",
)


def find_block_end(text: str, open_paren_offset: int) -> int:
    depth = 0
    i = open_paren_offset
    in_str: str | None = None
    while i < len(text):
        c = text[i]
        if in_str:
            if c == "\\":
                i += 2
                continue
            if c == in_str:
                in_str = None
            i += 1
            continue
        if c in "\"'":
            in_str = c
            i += 1
            continue
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    return -1


def split_top_commas(text: str) -> list[str]:
    """Split by commas at depth-0 (ignoring brackets/strings)."""
    out: list[str] = []
    buf = []
    depth = 0
    in_str: str | None = None
    i = 0
    while i < len(text):
        c = text[i]
        if in_str:
            buf.append(c)
            if c == "\\":
                buf.append(text[i + 1] if i + 1 < len(text) else "")
                i += 2
                continue
            if c == in_str:
                in_str = None
            i += 1
            continue
        if c in "\"'":
            in_str = c
            buf.append(c)
            i += 1
            continue
        if c in "([{":
            depth += 1
            buf.append(c)
        elif c in ")]}":
            depth -= 1
            buf.append(c)
        elif c == "," and depth == 0:
            out.append("".join(buf).strip())
            buf = []
        else:
            buf.append(c)
        i += 1
    rest = "".join(buf).strip()
    if rest:
        out.append(rest)
    return out


def parse_controller_actions(path: Path) -> dict[str, int]:
    """Return {action_name: zero-based-position-of-CurrentUser-param}."""
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return {}
    if "#[CurrentUser]" not in text:
        return {}
    out: dict[str, int] = {}
    for m in RE_ACTION.finditer(text):
        name = m.group(1)
        open_paren = m.end() - 1
        close_paren = find_block_end(text, open_paren)
        if close_paren < 0:
            continue
        params_text = text[open_paren + 1:close_paren]
        if "#[CurrentUser]" not in params_text:
            continue
        params = split_top_commas(params_text)
        for i, p in enumerate(params):
            if "#[CurrentUser]" in p:
                out[name] = i
                break
    return out


def collect_actions() -> dict[str, int]:
    """Aggregate all action names from controllers; if the same name exists in
    multiple controllers with different positions, prefer the smallest (most
    forgiving) — heuristic only."""
    out: dict[str, int] = {}
    for f in sorted(CONTROLLER_DIR.rglob("*.php")):
        for name, pos in parse_controller_actions(f).items():
            if name not in out or pos < out[name]:
                out[name] = pos
    return out


def scan_tests(actions: dict[str, int]) -> list[tuple[Path, int, str]]:
    if not actions:
        return []
    re_call = re.compile(r"->(" + "|".join(re.escape(n) for n in actions.keys()) + r")\s*\(")
    out: list[tuple[Path, int, str]] = []
    for f in sorted(TEST_DIR.rglob("*.php")):
        try:
            text = f.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        if "->" not in text:
            continue
        # Skip lines in fixtures
        if "tests/Fixtures" in f.as_posix():
            continue
        for m in re_call.finditer(text):
            name = m.group(1)
            required = actions[name] + 1
            open_paren = m.end() - 1
            close = find_block_end(text, open_paren)
            if close < 0:
                continue
            args_text = text[open_paren + 1:close].strip()
            if not args_text:
                actual = 0
            else:
                actual = len(split_top_commas(args_text))
            if actual < required:
                ln = text.count("\n", 0, m.start()) + 1
                line_start = text.rfind("\n", 0, m.start()) + 1
                line_end = text.find("\n", m.end())
                if line_end < 0:
                    line_end = len(text)
                snip = text[line_start:line_end].strip()[:160]
                out.append((f, ln, f"->{name}() got {actual} args, need ≥{required} (#[CurrentUser] at pos {actions[name]}) | {snip}"))
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

    if not CONTROLLER_DIR.is_dir():
        print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
        return 2
    if not TEST_DIR.is_dir():
        print("check_currentuser_test_args: no tests/ dir — OK")
        return 0

    actions = collect_actions()
    violations = scan_tests(actions)

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_currentuser_test_args.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_currentuser_test_args: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_currentuser_test_args: OK — {total} mismatch(es), {baselined} baselined. ({len(actions)} actions scanned)")
        else:
            print(f"check_currentuser_test_args: OK ({total}, all baselined)")
        return 0

    print("check_currentuser_test_args: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_currentuser_test_args: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: pass a User-mock argument to the action call to satisfy the #[CurrentUser] param.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
