#!/usr/bin/env python3
"""
check_route_trailing_slash.py — Gate 36.

Forbids `#[Route('/foo/')]` paths that END with a trailing slash
(except the root `'/'` itself). Symfony emits a 308 redirect to the
slash-less form, which breaks Turbo frame swaps and adds latency for
every authenticated request.

Recurring fix:
  - 22f72ec9 fix(megamenu): MRIS-report prefetch downloads
                          + evidence-reverification trailing slash

Exit 0 = clean / baselined, Exit 1 = new violations.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "src"

# #[Route('/foo/bar/', ...) ] or #[Route(path: '/foo/')], both quote styles
RE_ROUTE = re.compile(
    r"#\[\s*Route\s*\(\s*(?:path\s*:\s*)?(?P<q>['\"])(?P<path>[^'\"]+)(?P=q)"
)


def scan() -> list[tuple[Path, int, str]]:
    findings: list[tuple[Path, int, str]] = []
    for php in SRC.rglob("*.php"):
        text = php.read_text(encoding="utf-8", errors="ignore")
        for m in RE_ROUTE.finditer(text):
            path = m.group("path")
            if len(path) > 1 and path.endswith("/"):
                ln = text.count("\n", 0, m.start()) + 1
                findings.append((php, ln, path))
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
    keys = [f"{_rel(p)}:{ln}:{path}" for p, ln, path in findings]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        with args.write_baseline.open("w", encoding="utf-8") as fh:
            fh.write("# check_route_trailing_slash.py baseline\n")
            for k in keys:
                fh.write(k + "\n")
        print(f"check_route_trailing_slash: wrote {len(keys)} entries")
        return 0

    baseline = load_baseline(args.baseline)
    new = [(p, ln, path) for (p, ln, path), k in zip(findings, keys) if k not in baseline]
    total = len(findings)
    baselined = total - len(new)

    if not new:
        if not args.quiet:
            print(f"check_route_trailing_slash: OK — {total} legacy, {baselined} baselined.")
        else:
            print(f"check_route_trailing_slash: OK ({total}, all baselined)")
        return 0

    print("check_route_trailing_slash: VIOLATIONS\n")
    for p, ln, path in new:
        print(f"FAIL {_rel(p)}:{ln}: route path '{path}' has trailing slash → 308 redirect")
    print(f"\ncheck_route_trailing_slash: {len(new)} new ({baselined} baselined, {total} total).")
    print("Fix: drop the trailing slash from the #[Route] path.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
