#!/usr/bin/env python3
"""
check_em_writes_in_controller.py — Forbid EntityManager writes in controllers.

Controllers should orchestrate, not persist. Direct `$em->persist()`,
`$em->flush()`, `$em->remove()`, `$em->merge()` calls inside a controller
bypass:
  - Tenant-scoping middleware
  - AuditLogger lifecycle hooks
  - The service-layer transactional boundary

Soft-fail: large existing baseline (~376 in main), CI fails only on NEW
additions.

Per-file opt-out: add `// @em-write-allowed: <reason>` in the class docblock
or anywhere in the file's top 30 lines.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

# `$em->persist(` / `$entityManager->flush(` / `$this->em->remove(` etc.
RE_EM_WRITE = re.compile(
    r"""\$
        (?:
          this\s*->\s*(?:em|entityManager|entity_manager|manager)
          |
          (?:em|entityManager|entity_manager)
        )
        \s*->\s*
        (persist|flush|remove|merge)
        \s*\(
    """,
    re.VERBOSE | re.IGNORECASE,
)


def is_opted_out(text: str) -> bool:
    head = "\n".join(text.splitlines()[:30])
    return "@em-write-allowed" in head


def scan(path: Path) -> list[tuple[int, str]]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        return []
    if "->persist(" not in text and "->flush(" not in text and "->remove(" not in text and "->merge(" not in text:
        return []
    if is_opted_out(text):
        return []
    out: list[tuple[int, str]] = []
    for idx, raw in enumerate(text.splitlines(), start=1):
        s = raw.lstrip()
        if s.startswith("//") or s.startswith("*") or s.startswith("#"):
            continue
        if RE_EM_WRITE.search(raw):
            out.append((idx, raw.strip()[:160]))
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

    if not CONTROLLER_DIR.is_dir():
        print(f"ERROR: {CONTROLLER_DIR} not found", file=sys.stderr)
        return 2

    violations: list[tuple[Path, int, str]] = []
    for f in walk(CONTROLLER_DIR):
        for ln, snip in scan(f):
            violations.append((f, ln, snip))

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_em_writes_in_controller.py baseline\n# Format: <relative-path>:<line>\n")
            for path, ln, _snip in violations:
                fh.write(f"{_rel(path)}:{ln}\n")
        print(f"check_em_writes_in_controller: wrote {len(violations)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in violations if f"{_rel(v[0])}:{v[1]}" not in baseline]
    total = len(violations)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_em_writes_in_controller: OK — {total} EM-write(s), {baselined} baselined.")
        else:
            print(f"check_em_writes_in_controller: OK ({total}, all baselined)")
        return 0

    print("check_em_writes_in_controller: VIOLATIONS\n")
    for path, ln, snip in new[:200]:
        print(f"FAIL {_rel(path)}:{ln}: {snip}")
    if len(new) > 200:
        print(f"... and {len(new) - 200} more")
    print(f"\ncheck_em_writes_in_controller: {len(new)} new violation(s) ({baselined} baselined, {total} total).")
    print("Fix: move write to a Service. OR mark the controller `// @em-write-allowed: <reason>` near the top.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
