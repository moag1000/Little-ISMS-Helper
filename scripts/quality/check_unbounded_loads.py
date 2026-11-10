#!/usr/bin/env python3
"""
check_unbounded_loads.py — unbounded loads on the request path (CI Gate 101, S31).

A large organisation has 20 000 assets and 5 000 risks. Code on the request path
(controllers, deadline sources, exports, report services, Twig, MCP tools) that loads
a whole table and filters or pages it in PHP holds a PHP worker for seconds per call,
and a handful of parallel calls take the whole pool. This gate counts, per file:

  findAll      `->findAll()` on any repository
  findBy       `->findBy([... 'tenant' ...])` without a limit argument (one or two args)
  fromRequest  `ListPage::fromRequest(` — a list paged in PHP (controllers only);
               `ListPage::fromSlice()` pages in SQL

The counts are a one-way ratchet against scripts/quality/baselines/unbounded_loads.txt
(`<path> findAll=N findBy=N fromRequest=N`):

  * a file not in the baseline with any count fails;
  * a count above its baseline entry fails;
  * --strict-shrink: a count below its entry (or a baselined file without loads)
    fails until the entry is lowered — headroom cannot be spent later;
  * --ratchet-ref <git-ref>: the baseline may not grow against the baseline at
    <git-ref> (no added entry, no raised count). Engages once that baseline exists.

`--write-baseline <file>` rewrites the baseline from the tree. A load that is bounded by
nature (a global catalogue of a few dozen rows) carries `// @bounded-load: <reason>` on
its line or the line above and is not counted.
"""
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FORMAT_HEADER = "# format: 1"
KINDS = ("findAll", "findBy", "fromRequest")

# Request-path code: (path prefix, file name regex or None for every file)
SCOPES: tuple[tuple[str, str | None], ...] = (
    ("src/Controller/", None),
    ("src/Service/Deadline/", None),
    ("src/Service/Planning/Source/", None),
    ("src/Service/Export/", None),
    ("src/Twig/", None),
    ("src/Mcp/", None),
    ("src/Service/", r"(Report|Export|Dashboard|Analytics)[A-Za-z]*\.php$"),
)

MARKER = "@bounded-load:"
FIND_ALL = re.compile(r"->findAll\(\s*\)")
FIND_BY = re.compile(r"->findBy\(")
FROM_REQUEST = re.compile(r"ListPage::fromRequest\(")


def in_scope(rel: str) -> bool:
    for prefix, name in SCOPES:
        if rel.startswith(prefix) and (name is None or re.search(name, rel.rsplit("/", 1)[-1])):
            return True
    return False


def _call_args(text: str, open_paren: int) -> str | None:
    """Text between the parentheses starting at open_paren (balanced), or None."""
    depth = 0
    for i in range(open_paren, len(text)):
        ch = text[i]
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
            if depth == 0:
                return text[open_paren + 1:i]
    return None


def _top_level_args(args: str) -> int:
    depth, count, seen = 0, 0, False
    for ch in args:
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == "," and depth == 0:
            count += 1
            continue
        if not ch.isspace():
            seen = True
    if not seen:
        return 0
    # a trailing comma does not open another argument
    return count + 1 - (1 if args.rstrip().endswith(",") else 0)


def _marked(lines: list[str], line_no: int) -> bool:
    here = lines[line_no - 1] if 0 < line_no <= len(lines) else ""
    above = lines[line_no - 2] if line_no >= 2 else ""
    return MARKER in here or MARKER in above


def count_text(text: str, controller: bool) -> dict[str, int]:
    lines = text.splitlines()
    counts = dict.fromkeys(KINDS, 0)

    def line_of(pos: int) -> int:
        return text.count("\n", 0, pos) + 1

    for m in FIND_ALL.finditer(text):
        if not _marked(lines, line_of(m.start())):
            counts["findAll"] += 1
    for m in FIND_BY.finditer(text):
        args = _call_args(text, m.end() - 1)
        if args is None or "'tenant'" not in args and '"tenant"' not in args:
            continue
        if _top_level_args(args) <= 2 and not _marked(lines, line_of(m.start())):
            counts["findBy"] += 1
    if controller:
        for m in FROM_REQUEST.finditer(text):
            if not _marked(lines, line_of(m.start())):
                counts["fromRequest"] += 1
    return counts


def scan(root: Path) -> dict[str, dict[str, int]]:
    result: dict[str, dict[str, int]] = {}
    for path in sorted((root / "src").rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        if not in_scope(rel):
            continue
        counts = count_text(path.read_text(encoding="utf-8", errors="replace"), rel.startswith("src/Controller/"))
        if any(counts.values()):
            result[rel] = counts
    return result


def parse_baseline(text: str) -> dict[str, dict[str, int]]:
    entries: dict[str, dict[str, int]] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        path, *fields = line.split()
        counts = dict.fromkeys(KINDS, 0)
        for field in fields:
            key, _, value = field.partition("=")
            if key in counts:
                counts[key] = int(value)
        entries[path] = counts
    return entries


def format_baseline(entries: dict[str, dict[str, int]]) -> str:
    out = [
        FORMAT_HEADER,
        "# Gate 101 (S31): unbounded loads on the request path — may only shrink.",
        "# Lower with: python3 scripts/quality/check_unbounded_loads.py --write-baseline scripts/quality/baselines/unbounded_loads.txt",
    ]
    for path in sorted(entries):
        counts = entries[path]
        out.append(path + " " + " ".join(f"{k}={counts[k]}" for k in KINDS))
    return "\n".join(out) + "\n"


def violations(current: dict[str, dict[str, int]], baseline: dict[str, dict[str, int]], strict_shrink: bool) -> list[str]:
    msgs: list[str] = []
    for path, counts in sorted(current.items()):
        base = baseline.get(path)
        for kind in KINDS:
            allowed = base[kind] if base else 0
            if counts[kind] > allowed:
                msgs.append(f"{path}: {kind}={counts[kind]} (allowed {allowed}) — load in SQL with a limit, "
                            "page with ListPage::fromSlice(), or mark a bounded catalogue with // @bounded-load: <reason>")
    if strict_shrink:
        for path, base in sorted(baseline.items()):
            counts = current.get(path, dict.fromkeys(KINDS, 0))
            for kind in KINDS:
                if counts[kind] < base[kind]:
                    msgs.append(f"{path}: {kind}={counts[kind]} below its baseline {base[kind]} — lower the entry "
                                "(--write-baseline)")
    return msgs


def ratchet_violations(baseline: dict[str, dict[str, int]], reference: dict[str, dict[str, int]]) -> list[str]:
    msgs: list[str] = []
    for path, counts in sorted(baseline.items()):
        ref = reference.get(path)
        if ref is None:
            if any(counts.values()):
                msgs.append(f"baseline entry ADDED for {path} — new unbounded loads are not baselined, fix them")
            continue
        for kind in KINDS:
            if counts[kind] > ref[kind]:
                msgs.append(f"baseline entry RAISED for {path}: {kind} {ref[kind]} → {counts[kind]}")
    return msgs


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--baseline", default="scripts/quality/baselines/unbounded_loads.txt")
    parser.add_argument("--write-baseline", metavar="FILE")
    parser.add_argument("--strict-shrink", action="store_true")
    parser.add_argument("--ratchet-ref", metavar="GIT_REF")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args(argv)

    current = scan(ROOT)
    if args.write_baseline:
        (ROOT / args.write_baseline).write_text(format_baseline(current), encoding="utf-8")
        print(f"Wrote {len(current)} entries to {args.write_baseline}")
        return 0

    baseline_path = ROOT / args.baseline
    baseline = parse_baseline(baseline_path.read_text(encoding="utf-8")) if baseline_path.exists() else {}
    msgs = violations(current, baseline, args.strict_shrink)

    if args.ratchet_ref:
        shown = subprocess.run(["git", "show", f"{args.ratchet_ref}:{args.baseline}"], cwd=ROOT,
                               capture_output=True, text=True)
        if shown.returncode == 0 and FORMAT_HEADER in shown.stdout:
            msgs += ratchet_violations(baseline, parse_baseline(shown.stdout))
        elif not args.quiet:
            print(f"Gate 101: no baseline at {args.ratchet_ref} yet — ratchet not engaged (bootstrap).")

    if msgs:
        print("Gate 101 — unbounded loads on the request path (S31):")
        for msg in msgs:
            print("  " + msg)
        return 1
    if not args.quiet:
        total = {k: sum(c[k] for c in current.values()) for k in KINDS}
        print("Gate 101 OK — " + ", ".join(f"{k}={v}" for k, v in total.items()))
    return 0


if __name__ == "__main__":
    sys.exit(main())
