#!/usr/bin/env python3
"""
check_entity_manager_reset.py — a closed EntityManager is recovered ONLY via
App\\Doctrine\\EntityManagerRecovery (CI Gate 68).

ManagerRegistry::resetManager() gives the EntityManager a fresh state: the
tenant filter loses its tenant_id parameter (every later query reads across
all organisations) and TenantContext's cached tenant is no longer managed
(later audit rows fail). EntityManagerRecovery::recover() captures the filter
and context state first and restores both. Any other `resetManager(` call in
src/ fails this gate.

Comments (`//`, `#`, `/* … */`, docblock lines) are ignored.

Exit-codes: 0 clean / baselined, 1 violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ALLOWED = {"src/Doctrine/EntityManagerRecovery.php"}
RE_CALL = re.compile(r"\bresetManager\s*\(")
RE_BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.S)


def strip_comments(source: str) -> str:
    """Blank out comments, keeping line numbers."""
    source = RE_BLOCK_COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), source)
    out = []
    for line in source.split("\n"):
        # line comments (naive: not inside strings, good enough for PHP here)
        for marker in ("//", "#"):
            idx = line.find(marker)
            if idx != -1 and not (marker == "#" and line[idx:idx + 2] == "#["):
                line = line[:idx]
        out.append(line)
    return "\n".join(out)


def scan_text(source: str) -> list[int]:
    """Line numbers with a resetManager( call outside comments."""
    clean = strip_comments(source)
    return [i for i, line in enumerate(clean.split("\n"), 1) if RE_CALL.search(line)]


def scan(root: Path) -> list[tuple[str, int, str]]:
    hits: list[tuple[str, int, str]] = []
    for path in sorted((root / "src").rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        if rel in ALLOWED:
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        if "resetManager" not in text:
            continue
        lines = text.split("\n")
        for ln in scan_text(text):
            hits.append((rel, ln, lines[ln - 1].strip()[:160]))
    return hits


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {s.strip() for s in path.read_text(encoding="utf-8").splitlines() if s.strip() and not s.startswith("#")}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    baseline = load_baseline(args.baseline)
    hits = scan(ROOT)
    new = [h for h in hits if f"{h[0]}:{h[1]}" not in baseline]
    if not new:
        if not args.quiet:
            print(f"check_entity_manager_reset: OK — {len(hits)} occurrence(s), all baselined.")
        else:
            print("check_entity_manager_reset: OK")
        return 0

    print("check_entity_manager_reset: VIOLATIONS\n")
    for rel, ln, snippet in new:
        print(f"FAIL {rel}:{ln}: bare resetManager() — {snippet}")
    print(
        "\nFix: inject App\\Doctrine\\EntityManagerRecovery and call recover() / recoverIfClosed() —"
        " it restores the tenant filter and TenantContext after the reset."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
