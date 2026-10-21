#!/usr/bin/env python3
"""
check_entity_manager_reset.py — a closed EntityManager is recovered ONLY via
App\\Doctrine\\EntityManagerRecovery (CI Gate 68).

ManagerRegistry::resetManager() gives the EntityManager a fresh state: the
tenant filter loses its tenant_id parameter (every later query reads across
all organisations) and TenantContext's cached tenant is no longer managed
(later audit rows fail). EntityManagerRecovery::recover() captures the filter
and context state first and restores both. Any other reset in src/ fails:

  * `resetManager(` — also split across lines (`->resetManager` ⏎ `(`);
  * `->reset(` on the Doctrine registry (doctrine-bundle Registry::reset()
    resets a closed manager the same way): a variable / property / getter
    whose name contains `registry` or `doctrine`, or `->get('doctrine')`.

String literals and comments (`//`, `#` except `#[`, `/* … */`) are blanked
before matching (line numbers kept), so a `#` or `//` inside a string does
not hide the code after it and a string that mentions resetManager() is not
flagged. Limits: heredoc / nowdoc bodies are scanned as code; a registry
held in a variable with an unrelated name is not recognised as such.

Exit-codes: 0 clean / baselined, 1 violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ALLOWED = {"src/Doctrine/EntityManagerRecovery.php"}
KEEP_STRINGS = {"doctrine"}  # ->get('doctrine')->reset() must stay recognisable
RE_RESET_MANAGER = re.compile(r"\bresetManager\s*\(")
RE_REGISTRY_RESET = re.compile(
    r"(?:\$\w*(?:registry|doctrine)\w*|\??->\s*\w*(?:registry|doctrine)\w*(?:\s*\(\s*\))?"
    r"|\??->\s*get\s*\(\s*['\"]doctrine['\"]\s*\)|::\s*\w*(?:registry|doctrine)\w*\s*\(\s*\))"
    r"\s*\??->\s*reset\s*\(",
    re.IGNORECASE,
)


def strip_code(source: str) -> str:
    """Blank out string literals and comments, keeping offsets and newlines."""
    out = list(source)
    i, n = 0, len(source)

    def blank(start: int, end: int) -> None:
        for k in range(start, end):
            if out[k] != "\n":
                out[k] = " "

    while i < n:
        ch = source[i]
        nxt = source[i + 1] if i + 1 < n else ""
        if ch in ("'", '"'):
            j = i + 1
            while j < n and source[j] != ch:
                j += 2 if source[j] == "\\" else 1
            end = min(j + 1, n)
            if source[i + 1:j].lower() not in KEEP_STRINGS:
                blank(i + 1, j if j < n else n)
            i = end
        elif ch == "/" and nxt == "*":
            j = source.find("*/", i + 2)
            j = n if j == -1 else j + 2
            blank(i, j)
            i = j
        elif (ch == "/" and nxt == "/") or (ch == "#" and nxt != "["):
            j = source.find("\n", i)
            j = n if j == -1 else j
            blank(i, j)
            i = j
        else:
            i += 1
    return "".join(out)


def scan_text(source: str) -> list[int]:
    """Line numbers of bare EntityManager resets (sorted, unique)."""
    clean = strip_code(source)
    lines: set[int] = set()
    for regex in (RE_RESET_MANAGER, RE_REGISTRY_RESET):
        for m in regex.finditer(clean):
            lines.add(clean.count("\n", 0, m.start()) + 1)
    return sorted(lines)


def scan(root: Path) -> list[tuple[str, int, str]]:
    hits: list[tuple[str, int, str]] = []
    for path in sorted((root / "src").rglob("*.php")):
        rel = path.relative_to(root).as_posix()
        if rel in ALLOWED:
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        if "resetManager" not in text and "reset" not in text:
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
        print(f"FAIL {rel}:{ln}: bare EntityManager reset — {snippet}")
    print(
        "\nFix: inject App\\Doctrine\\EntityManagerRecovery and call recover() / recoverIfClosed() —"
        " it restores the tenant filter and TenantContext after the reset."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
