#!/usr/bin/env python3
"""Gate 87: risk bands come from the organisation's risk scale.

Since P8b the band edges (medium/high/critical) belong to the organisation
(ISO 27001 6.1.2 a/b, Risikokriterien) and are read via
App\\Risk\\RiskMatrixThresholds (PHP: classify(), criticalMin()/highMin()/
mediumMin(); Twig: risk_level_band(), risk_bands(); JS: band-*-min values).
A literal ``score >= 20`` / ``>= 12`` / ``>= 6`` next to a risk score keeps
the old 20/12/6 scale alive after an organisation changed it.

The check flags comparisons of a risk-score-like expression with 20, 12 or 6
in src/, templates/ and assets/controllers/. Genuine non-band uses (another
scale that happens to use these numbers) go into the baseline as
``path:line-text`` with a reason comment. The baseline may only shrink.

Second check (since the scale follow-up): every read of the scale names the
organisation whose data it classifies. In src/ a call of
RiskMatrixThresholds::classify() needs two arguments and criticalMin()/
highMin()/mediumMin()/getBands() one; in templates/ risk_level_band() needs
two, risk_threshold() two and risk_bands() one. Without it the scale falls
back to the tenant in scope: defaults 20/12/6 in commands, cron and async
jobs, the viewer's scale in holding views. The rare call where the
organisation in scope is genuinely right goes into
``baselines/risk_scale_without_tenant.txt`` as ``path::reason``.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = ROOT / "scripts/quality/baselines/risk_band_literals.txt"
ALLOW_NO_TENANT = ROOT / "scripts/quality/baselines/risk_scale_without_tenant.txt"

# method → minimum number of arguments (the last one is the tenant).
PHP_CALLS = {"classify": 2, "criticalMin": 1, "highMin": 1, "mediumMin": 1, "getBands": 1}
TWIG_CALLS = {"risk_level_band": 2, "risk_threshold": 2, "risk_bands": 1}
PHP_CALL = re.compile(r"RiskMatrixThresholds::(" + "|".join(PHP_CALLS) + r")\s*\(")
TWIG_CALL = re.compile(r"(?<![\w.])(" + "|".join(TWIG_CALLS) + r")\s*\(")

SCAN = [("src", "*.php"), ("templates", "*.twig"), ("assets/controllers", "*.js")]
SKIP = {"src/Risk/RiskMatrixThresholds.php"}

# A risk-score-ish operand, then a comparison with a literal band edge.
PATTERN = re.compile(
    r"(?i)(risk_?score|riskscore|risklevel|risk_level|inherentrisk|residualrisk|inherent|residual"
    r"|\bscore\b|probability\s*\*\s*\w*\.?impact|\$level\b|\blevel\b)"
    r"[^;\n{}]{0,40}?(>=|<=|>|<)\s*(20|12|6)\b(?![\d.]|\s*%(?!}))"
)


def normalise(line: str) -> str:
    return re.sub(r"\s+", " ", line.strip())


def scan() -> list[tuple[str, int, str]]:
    hits = []
    for base, glob in SCAN:
        for path in sorted((ROOT / base).rglob(glob)):
            rel = path.relative_to(ROOT).as_posix()
            if rel in SKIP:
                continue
            for no, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
                stripped = line.strip()
                if stripped.startswith(("//", "*", "#", "/*", "{#")):
                    continue
                if PATTERN.search(line):
                    hits.append((rel, no, normalise(line)))
    return hits


def count_args(text: str, open_pos: int) -> int | None:
    """Top-level arguments of the call whose '(' is at open_pos; None if unbalanced."""
    depth = 0
    args = 0
    seen = False
    quote = ""
    i = open_pos
    while i < len(text):
        ch = text[i]
        if quote:
            if ch == "\\":
                i += 2
                continue
            if ch == quote:
                quote = ""
        elif ch in "'\"":
            quote = ch
            seen = seen or depth == 1
        elif ch in "([{":
            depth += 1
            if depth > 1:
                seen = True
        elif ch in ")]}":
            depth -= 1
            if depth == 0:
                return args + (1 if seen else 0)
        elif ch == "," and depth == 1:
            args += 1
            seen = False
        elif depth == 1 and not ch.isspace():
            seen = True
        i += 1
    return None


def strip_comments(text: str, twig: bool) -> str:
    """Blank out comments, keeping line numbers."""
    blank = lambda m: re.sub(r"[^\n]", " ", m.group(0))  # noqa: E731
    if twig:
        return re.sub(r"\{#.*?#\}", blank, text, flags=re.S)
    text = re.sub(r"/\*.*?\*/", blank, text, flags=re.S)
    return re.sub(r"(?m)^\s*(//|#(?!\[)).*$", blank, text)


def scan_without_tenant() -> list[tuple[str, int, str]]:
    hits = []
    for base, glob, pattern, needs in (
        ("src", "*.php", PHP_CALL, PHP_CALLS),
        ("templates", "*.twig", TWIG_CALL, TWIG_CALLS),
    ):
        for path in sorted((ROOT / base).rglob(glob)):
            rel = path.relative_to(ROOT).as_posix()
            if rel in SKIP:
                continue
            text = strip_comments(path.read_text(encoding="utf-8", errors="replace"), twig=glob == "*.twig")
            for m in pattern.finditer(text):
                if glob == "*.php" and re.search(r"function\s+$", text[max(0, m.start() - 20):m.start()]):
                    continue
                n = count_args(text, m.end() - 1)
                if n is not None and n < needs[m.group(1)]:
                    line = text.count("\n", 0, m.start()) + 1
                    hits.append((rel, line, m.group(1)))
    return hits


def load_allow_list() -> dict[str, str]:
    entries: dict[str, str] = {}
    if not ALLOW_NO_TENANT.exists():
        return entries
    for raw in ALLOW_NO_TENANT.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if raw and not raw.startswith("#"):
            path, _, reason = raw.partition("::")
            entries[path.strip()] = reason.strip()
    return entries


def check_without_tenant() -> int:
    allow = load_allow_list()
    hits = scan_without_tenant()
    errors = 0
    for path, reason in allow.items():
        if not reason:
            print(f"{ALLOW_NO_TENANT.name}: entry without reason (path::reason): {path}")
            errors += 1
    bad = [(rel, no, name) for rel, no, name in hits if rel not in allow]
    for rel, no, name in bad:
        print(f"{rel}:{no}: {name}() without the organisation of the data — pass the risk's tenant "
              f"(or the organisation whose matrix is shown); else defaults/viewer scale apply")
    stale = sorted(set(allow) - {rel for rel, _, _ in hits})
    for path in stale:
        print(f"stale entry in {ALLOW_NO_TENANT.name} (remove it): {path}")
    errors += len(bad) + len(stale)
    if errors:
        print(f"check_risk_band_literals: {len(bad)} scale read(s) without tenant, {len(stale)} stale allow entries")
        return 1
    print(f"check_risk_band_literals: every scale read names its organisation ({len(allow)} allow-listed files)")
    return 0


def load_baseline() -> set[str]:
    if not BASELINE.exists():
        return set()
    entries = set()
    for raw in BASELINE.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if raw and not raw.startswith("#"):
            entries.add(raw)
    return entries


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write-baseline", action="store_true")
    args = parser.parse_args()

    hits = scan()
    keys = {f"{rel}:{text}" for rel, _, text in hits}

    if args.write_baseline:
        BASELINE.write_text(
            "# Gate 87 — comparisons with 20/12/6 that are not risk bands (another scale).\n"
            "# Format: path:normalised line. May only shrink.\n"
            + "".join(f"{k}\n" for k in sorted(keys)),
            encoding="utf-8",
        )
        print(f"check_risk_band_literals: baseline written ({len(keys)} entries)")
        return 0

    baseline = load_baseline()
    new = [(rel, no, text) for rel, no, text in hits if f"{rel}:{text}" not in baseline]
    stale = sorted(baseline - keys)

    for rel, no, text in new:
        print(f"{rel}:{no}: literal risk band edge — use RiskMatrixThresholds / risk_level_band(): {text}")
    for entry in stale:
        print(f"stale baseline entry (remove it): {entry}")
    status = 0
    if new or stale:
        print(f"check_risk_band_literals: {len(new)} new, {len(stale)} stale")
        status = 1
    else:
        print(f"check_risk_band_literals: no literal risk band edges ({len(baseline)} baselined non-band uses)")
    return check_without_tenant() or status


if __name__ == "__main__":
    sys.exit(main())
