#!/usr/bin/env python3
"""
check_version_column_explicit.py — Gate 21.

Doctrine ORM `#[ORM\\Version]` columns must declare both an explicit
`type:` and a `name:` (snake_case mapping to the DB column) so the
schema-diff is reproducible across Doctrine versions and so the
column-name doesn't drift between camelCase property + snake_case DB.

Recent fix `37a3ad45 fix(entities): add explicit type/name to @Version
columns` patched this — gate prevents regression.

Detects:
  - Entity classes (`src/Entity/**/*.php`) containing `#[ORM\\Version]`
    followed by `#[ORM\\Column(...)]` where either `type:` or `name:`
    is missing.

Exit 0 = clean, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ENTITY_DIR = ROOT / "src" / "Entity"

# Match #[ORM\Version] followed (with optional intervening whitespace + comments)
# by #[ORM\Column(...)] and capture the Column attribute arg list.
RE_VERSION_COLUMN = re.compile(
    r"#\[\s*ORM\\Version\s*\]\s*"
    r"(?:#\[[^\]]*\]\s*)*?"  # tolerate intervening attributes
    r"#\[\s*ORM\\Column\s*\(([^)]*)\)\s*\]",
    re.DOTALL,
)


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    for entity in ENTITY_DIR.rglob("*.php"):
        text = entity.read_text(encoding="utf-8", errors="ignore")
        for m in RE_VERSION_COLUMN.finditer(text):
            args = m.group(1)
            missing: list[str] = []
            if not re.search(r"\btype\s*:\s*", args):
                missing.append("type:")
            if not re.search(r"\bname\s*:\s*", args):
                missing.append("name:")
            if missing:
                ln = text.count("\n", 0, m.start()) + 1
                findings.append((entity, ln, ", ".join(missing)))
    return findings


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        s.strip() for s in path.read_text(encoding="utf-8").splitlines()
        if s.strip() and not s.strip().startswith("#")
    }


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

    findings = scan()
    keys = [f"{_rel(p)}:{ln}" for p, ln, _ in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_version_column_explicit.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_version_column_explicit: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, miss) for (p, ln, miss), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_version_column_explicit: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_version_column_explicit: OK ({total}, all baselined)")
        return 0

    print("check_version_column_explicit: VIOLATIONS\n")
    for p, ln, miss in new:
        print(f"FAIL {_rel(p)}:{ln}: ORM\\Version column missing {miss}")
    print(f"\ncheck_version_column_explicit: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: declare explicit type + name on the Column attribute:")
    print("    #[ORM\\Version]")
    print("    #[ORM\\Column(name: 'lock_version', type: 'integer')]")
    print("    private int $lockVersion = 0;")
    return 1


if __name__ == "__main__":
    sys.exit(main())
