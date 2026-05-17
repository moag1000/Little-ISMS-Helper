#!/usr/bin/env python3
"""
check_bool_accessor_usage.py — Bool-property accessor mismatch detector.

When an Entity has `private bool $approved` and exposes ONLY `isApproved()`,
calling `->getApproved()` from a Service/Controller is a runtime error. The
inverse (only `getApproved()`, call site uses `->isApproved()`) is also a bug.

Doctrine generates both forms via the metadata layer, but autocomplete /
static analysis treats them as distinct — we lint the literal call sites.

Twig dynamic property access `{{ entity.approved }}` works for both, so the
gate only inspects PHP call sites.

Output: FAIL one line per bad call site, anchored to the call (not the
entity definition).
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"
SEARCH_DIRS = [ROOT / "src", ROOT / "templates"]
SKIP_DIRS = {"vendor", "node_modules", "var", ".claude", "tests/Fixtures", "migrations"}

RE_BOOL_PROP = re.compile(
    r"private\s+(?:readonly\s+)?\??bool\s+\$(\w+)\b",
)
RE_GET_METHOD = re.compile(r"public\s+function\s+get([A-Z]\w*)\s*\(")
RE_IS_METHOD = re.compile(r"public\s+function\s+is([A-Z]\w*)\s*\(")
RE_HAS_METHOD = re.compile(r"public\s+function\s+has([A-Z]\w*)\s*\(")


def cap(s: str) -> str:
    return s[:1].upper() + s[1:]


def parse_entity(path: Path) -> dict[str, dict[str, bool]]:
    """Return {Cap(prop): {'is': bool, 'get': bool, 'has': bool}} for each bool prop."""
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return {}
    bool_props = set(RE_BOOL_PROP.findall(text))
    if not bool_props:
        return {}
    get_methods = {m.group(1) for m in RE_GET_METHOD.finditer(text)}
    is_methods = {m.group(1) for m in RE_IS_METHOD.finditer(text)}
    has_methods = {m.group(1) for m in RE_HAS_METHOD.finditer(text)}
    out: dict[str, dict[str, bool]] = {}
    for prop in bool_props:
        capped = cap(prop)
        out[capped] = {
            "is": capped in is_methods,
            "get": capped in get_methods,
            "has": capped in has_methods,
        }
    return out


def is_skipped(path: Path) -> bool:
    parts = path.relative_to(ROOT).parts
    for skip in SKIP_DIRS:
        if "/" in skip:
            seg = tuple(skip.split("/"))
            if any(parts[i:i + len(seg)] == seg for i in range(len(parts))):
                return True
        elif skip in parts:
            return True
    return False


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if not ENTITY_DIR.is_dir():
        print(f"check_bool_accessor_usage: {ENTITY_DIR} not found — SKIP", file=sys.stderr)
        return 0

    # Build a global registry: property-cap-name -> available accessors (union)
    # If ANY entity has the prop with both is+get, we can't say which one is
    # canonical for that name — skip. Only flag mismatches for unambiguous cases.
    accessor_universe: dict[str, dict[str, set[Path]]] = {}
    # accessor_universe[CapName]['is'/'get'/'has'] = set of entity files
    for entity_path in sorted(ENTITY_DIR.rglob("*.php")):
        for capped, accessors in parse_entity(entity_path).items():
            registry = accessor_universe.setdefault(capped, {"is": set(), "get": set(), "has": set()})
            for kind, present in accessors.items():
                if present:
                    registry[kind].add(entity_path)

    # Build forbidden-method sets:
    #   getX is forbidden if NO entity has getX accessor but SOME entity has isX
    #   isX  is forbidden if NO entity has isX  accessor but SOME entity has getX
    forbid_get: set[str] = set()
    forbid_is: set[str] = set()
    for capped, reg in accessor_universe.items():
        if reg["is"] and not reg["get"] and not reg["has"]:
            forbid_get.add(capped)
        if reg["get"] and not reg["is"] and not reg["has"]:
            forbid_is.add(capped)

    re_bad_get = re.compile(
        r"->get(" + "|".join(re.escape(p) for p in sorted(forbid_get)) + r")\s*\("
    ) if forbid_get else None
    re_bad_is = re.compile(
        r"->is(" + "|".join(re.escape(p) for p in sorted(forbid_is)) + r")\s*\("
    ) if forbid_is else None

    violations: list[tuple[Path, int, str]] = []
    if re_bad_get or re_bad_is:
        for search_root in SEARCH_DIRS:
            for f in sorted(search_root.rglob("*.php")):
                if is_skipped(f):
                    continue
                # Skip Entity defining files (they declare the methods themselves)
                if f.is_relative_to(ENTITY_DIR):
                    continue
                try:
                    text = f.read_text(encoding="utf-8", errors="ignore")
                except OSError:
                    continue
                for idx, raw in enumerate(text.splitlines(), start=1):
                    s = raw.lstrip()
                    if s.startswith("//") or s.startswith("*") or s.startswith("#"):
                        continue
                    if re_bad_get:
                        for m in re_bad_get.finditer(raw):
                            violations.append(
                                (f, idx, f"->get{m.group(1)}() — entity only exposes is{m.group(1)}()")
                            )
                    if re_bad_is:
                        for m in re_bad_is.finditer(raw):
                            violations.append(
                                (f, idx, f"->is{m.group(1)}() — entity only exposes get{m.group(1)}()")
                            )

    def _rel(p: Path) -> Path:
        try:
            return p.relative_to(ROOT)
        except ValueError:
            return Path(p.name)

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_bool_accessor_usage.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_bool_accessor_usage: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline_path = args.baseline
    baseline: set[str] = set()
    if baseline_path and baseline_path.exists():
        for raw in baseline_path.read_text(encoding="utf-8").splitlines():
            s = raw.strip()
            if s and not s.startswith("#"):
                baseline.add(s)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_bool_accessor_usage: OK — {total} mismatch(es), {baselined} baselined.")
        else:
            print(f"check_bool_accessor_usage: OK ({total}, all baselined)")
        return 0

    print("check_bool_accessor_usage: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_bool_accessor_usage: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    return 1


if __name__ == "__main__":
    sys.exit(main())
