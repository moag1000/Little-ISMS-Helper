#!/usr/bin/env python3
r"""
check_enum_to_json_unwrap.py — Forbid raw backed-enum in JsonResponse arrays.

When a PHP backed enum is assigned directly to a JSON array element,
`json_encode` returns false and the response body becomes ``null`` → HTTP 500.
The fix is to append ``?->value`` to extract the backing string/int.

    // BAD — json_encode blows up on the enum instance
    return $this->json(['status' => $risk->getStatus()]);

    // GOOD — serialize the backing value
    return $this->json(['status' => $risk->getStatus()?->value]);

Detection scope: ``src/Controller/`` and ``src/Service/``.

Strategy
--------
1. Scan ``src/Entity/*.php`` for methods whose return type hint is a known
   backed-enum class name (i.e. a class that appears as ``enum Foo: string``
   or ``enum Foo: int`` anywhere in ``src/``).  Build a set of
   ``getter_name`` strings.
2. In each Controller/Service file look for array-element assignments:
       ``'<key>' => $var->getter()``
   where ``getter`` is in the set from step 1, AND the line sits inside a
   ``->json([...])`` or ``new JsonResponse([...])`` call site (heuristic:
   look back ≤30 lines).
3. Safe forms already excluded: ``?->value``, ``->value``, ``(string)``,
   chained further method call (``->getter()->method()``), and a same-line
   ``// @allow-raw-enum`` annotation.

Fixed in PR #705 and PR #712.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC_DIR = ROOT / "src"

# ── Step 1: discover backed-enum class names ──────────────────────────────

RE_BACKED_ENUM_DECL = re.compile(r"^enum\s+([A-Z][A-Za-z0-9_]+)\s*:\s*(?:string|int)", re.MULTILINE)


def discover_backed_enum_names() -> set[str]:
    """Return set of short class names that are PHP backed enums."""
    names: set[str] = set()
    for path in SRC_DIR.rglob("*.php"):
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        for m in RE_BACKED_ENUM_DECL.finditer(text):
            names.add(m.group(1))
    return names


# ── Step 2: discover getter methods that return backed enum instances ─────

# Match:  public function getFoo(): ?SomeEnum  or  ): SomeEnum
RE_GETTER_RETURN = re.compile(
    r"public\s+function\s+(get[A-Za-z0-9_]+)\s*\([^)]*\)\s*:\s*\??\\?([A-Z][A-Za-z0-9_]+)"
)


def discover_enum_getters(backed_enum_names: set[str]) -> set[str]:
    """
    Scan entity files for public getter methods whose return type is a known
    backed enum.  Returns getter method names (e.g. ``getStatus``).
    """
    entity_dir = SRC_DIR / "Entity"
    getters: set[str] = set()
    for path in entity_dir.rglob("*.php"):
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        for m in RE_GETTER_RETURN.finditer(text):
            method_name = m.group(1)
            return_type = m.group(2)
            if return_type in backed_enum_names:
                getters.add(method_name)
    return getters


# ── Step 3: scan Controller/Service for violations ────────────────────────

RE_JSON_OPEN = re.compile(r"(?:->json\s*\(|new\s+\\?JsonResponse\s*\()")
RE_SAFE_SUFFIX = re.compile(r"(?:\?->value|->value\b)")
RE_ALLOW = re.compile(r"//\s*@allow-raw-enum")
# Chained further call: ->getter()->anotherMethod()
RE_CHAINED = re.compile(r"->[A-Za-z_][A-Za-z0-9_]*\s*\(\s*\)\s*(?:\?->|->)[A-Za-z_]")


def _build_elem_pattern(getters: set[str]) -> re.Pattern[str]:
    """
    Match:  '<key>' => $var->getter()  or  "<key>" => $var?->getter()
    where getter is one of the known enum-returning methods.
    Does NOT match if followed immediately by ->something (chained call).
    """
    joined = "|".join(re.escape(g) for g in sorted(getters))
    # The pattern ends at ')' — we use a negative lookahead to skip chained calls
    return re.compile(
        r"""['"]\w+['"]\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*(?:\?->|->)(?:"""
        + joined
        + r""")\s*\(\s*\)(?!\s*(?:\?->|->))"""
    )


def _is_in_json_context(lines: list[str], line_idx: int) -> bool:
    """
    Heuristic: walk back up to 30 lines looking for ->json( or new JsonResponse(.
    Stop early on a ``return`` that is clearly not a JSON return.
    """
    for back in range(0, min(30, line_idx + 1)):
        candidate = lines[line_idx - back]
        if RE_JSON_OPEN.search(candidate):
            return True
        if back > 0:
            stripped = candidate.strip()
            # A bare return (not JSON) closes the search scope
            if (
                stripped.startswith("return ")
                and "json" not in stripped.lower()
                and "JsonResponse" not in stripped
                and "array_map" not in stripped
            ):
                break
    return False


def scan_file(path: Path, re_elem: re.Pattern[str]) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []

    if "json" not in text.lower() and "JsonResponse" not in text:
        return []

    lines = text.splitlines()
    violations: list[tuple[int, str]] = []

    for idx, line in enumerate(lines):
        stripped = line.lstrip()
        # Skip pure comment / doc-block lines
        if stripped.startswith("//") or stripped.startswith("*") or stripped.startswith("#"):
            continue

        if not re_elem.search(line):
            continue

        if RE_ALLOW.search(line):
            continue

        if RE_SAFE_SUFFIX.search(line):
            continue

        # Also skip if there is another method chained after getter()
        if RE_CHAINED.search(line):
            continue

        if not _is_in_json_context(lines, idx):
            continue

        line_no = idx + 1
        violations.append((line_no, line.strip()[:160]))

    return violations


def walk(dirs: list[Path]) -> list[Path]:
    files: list[Path] = []
    for d in dirs:
        if d.is_dir():
            files.extend(sorted(p for p in d.rglob("*.php") if p.is_file()))
    return files


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
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    scan_dirs = [SRC_DIR / "Controller", SRC_DIR / "Service"]
    for d in scan_dirs:
        if not d.is_dir():
            print(f"ERROR: {d} not found", file=sys.stderr)
            return 2

    backed_enum_names = discover_backed_enum_names()
    if not backed_enum_names:
        if not args.quiet:
            print("check_enum_to_json_unwrap: no backed enums found — skipping.")
        return 0

    enum_getters = discover_enum_getters(backed_enum_names)
    if not enum_getters:
        if not args.quiet:
            print("check_enum_to_json_unwrap: no enum-returning getters found — skipping.")
        return 0

    re_elem = _build_elem_pattern(enum_getters)

    all_violations: list[tuple[Path, int, str]] = []
    for f in walk(scan_dirs):
        for ln, snip in scan_file(f, re_elem):
            all_violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_enum_to_json_unwrap.py baseline\n")
            fh.write("# Format: <relative-path>:<line>\n")
            for path, ln, _snip in all_violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(
            f"check_enum_to_json_unwrap: wrote {len(all_violations)} entries"
            f" to {args.write_baseline}"
        )
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in all_violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(all_violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(
                f"check_enum_to_json_unwrap: OK — {total} occurrence(s),"
                f" {baselined} baselined."
            )
        return 0

    print("check_enum_to_json_unwrap: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(
        f"\ncheck_enum_to_json_unwrap: {len(new)} new violation(s)"
        f" ({baselined} baselined, {total} total)."
    )
    print(
        "Fix: append `?->value` to extract the enum's backing string value,"
        " e.g. `'status' => $entity->getStatus()?->value`."
        " Or add `// @allow-raw-enum` on the same line if truly intentional."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
