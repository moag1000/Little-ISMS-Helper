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
sys.path.insert(0, str(Path(__file__).resolve().parent))
from php_source import strip_comments as strip_php_comments, use_aliases  # noqa: E402

BASELINE = ROOT / "scripts/quality/baselines/risk_band_literals.txt"
ALLOW_NO_TENANT = ROOT / "scripts/quality/baselines/risk_scale_without_tenant.txt"

# method → minimum number of arguments (the last one is the tenant).
PHP_CALLS = {"classify": 2, "criticalMin": 1, "highMin": 1, "mediumMin": 1, "getBands": 1}
TWIG_CALLS = {"risk_level_band": 2, "risk_threshold": 2, "risk_bands": 1}
PHP_CALL = re.compile(r"RiskMatrixThresholds::(" + "|".join(PHP_CALLS) + r")\s*\(")
THRESHOLDS_CLASS = "App\\Risk\\RiskMatrixThresholds"


def php_call_pattern(text: str) -> re.Pattern[str]:
    """PHP_CALL for this file: also every local name (`use … as T`, group use) the thresholds class goes by."""
    names = {"RiskMatrixThresholds"}
    names |= {alias for alias, fqcn in use_aliases(text).items() if fqcn.endswith("RiskMatrixThresholds")}
    return re.compile(r"(?<![\w$])(?:" + "|".join(sorted(map(re.escape, names))) + r")::(" + "|".join(PHP_CALLS) + r")\s*\(")
TWIG_CALL = re.compile(r"(?<![\w.])(" + "|".join(TWIG_CALLS) + r")\s*\(")

SCAN = [("src", "*.php"), ("templates", "*.twig"), ("assets", "*.js")]
SKIP = {"src/Risk/RiskMatrixThresholds.php"}

# A risk-score-ish operand, then a comparison with a literal band edge.
OPERAND = (r"(?:risk_?score|riskscore|risklevel|risk_level|inherentrisk|residualrisk|inherent|residual"
           r"|\bscore\b|\$rating\b|\brating\b|probability\s*\*\s*\w*\.?impact|\$level\b|\blevel\b)")
# One line break inside the window is fine (`$score\n    >= 20`).
GAP = r"(?:[^;\n{}]|\n(?!\s*\n)){0,40}?"
PATTERN = re.compile(
    r"(?i)(" + OPERAND + r")" + GAP + r"(>=|<=|>|<)\s*(20|12|6)\b(?![\d.]|\s*%(?!}))"
    # Yoda order: `20 <= $score`
    r"|(?<![\w.$])(20|12|6)\s*(<=|<|>=|>)\s*" + GAP + OPERAND
)
# The edge one below (`> 19`, `<= 11`) is the same band written the other way round.
EDGE_BELOW = re.compile(
    r"(?i)(" + OPERAND + r")" + GAP + r"(?<![=\-])(>|<=)\s*(19|11|5)\b(?![\d.]|\s*%(?!}))"
    r"|(?<![\w.$])(19|11|5)\s*(<|>=)\s*" + GAP + OPERAND
)
# `in_array($score, range(20, 25))`, `range(12, 19)`: a band as a list of scores.
RANGE_BAND = re.compile(r"(?i)\brange\(\s*(?:20|12|6)\s*,\s*(?:2[0-5]|1[0-9]|[7-9])\s*\)")
# `const BANDS = ['critical' => 20, 'high' => 12, 'medium' => 6]` and any array that maps band names to the edges.
BAND_ARRAY = re.compile(
    r"""(?i)['"](?:critical|kritisch|high|hoch|medium|mittel|elevated|severe)['"]\s*=>\s*(?:20|12|6)\s*[,\]\n)]"""
    r"""|['"](?:20|12|6)['"]\s*=>\s*['"](?:critical|kritisch|high|hoch|medium|mittel)['"]"""
)
# What a variable must be filled from to count as "a risk score": the operand words, or a risk/score getter.
# A plain accessor chain (`$r->getRiskScore()`, `risk.inherentRiskLevel`, `(int) $row['score'] ?? 0`), no arithmetic, filter or
# count: `$exceedance = $score - $appetite` or `risks|length` is another quantity.
ACCESSOR = re.compile(
    r"^[\s(]*(?:\((?:int|float|string)\)\s*)?\$?[\w.]+(?:\s*\??->\s*\w+(?:\s*\([^()]*\))?|\.\w+|\[[^\]]*\])*"
    r"\s*(?:\?\?\s*[\w$.'\"]+|\|\s*default\([^()]*\))?[\s)]*$"
)
# `$r->getProbability() * $r->getImpact()` (any accessor spelling): the score computed by hand.
PRODUCT = re.compile(
    r"(?is)^[\s(]*(?:\(\w+\)\s*)?[^;*]*?probability\w*\s*(?:\(\))?[\s)]*\*[\s(]*[^;*]*?impact\w*\s*(?:\(\))?[\s)]*$"
    r"|^[\s(]*[^;*]*?impact\w*\s*(?:\(\))?[\s)]*\*[\s(]*[^;*]*?probability\w*\s*(?:\(\))?[\s)]*$"
)
RISKISH = re.compile(r"(?i)risk|score|rating|inherent|residual|level")
RISK_NAME = re.compile(r"(?i)(?:risk|score|rating|level)$")
PHP_ASSIGN = re.compile(r"\$(\w+)\s*=(?![=>])\s*([^;]*);")
TWIG_SET = re.compile(r"\{%-?\s*set\s+(\w+)\s*=\s*(.*?)-?%\}", re.S)
JS_ASSIGN = re.compile(r"\b(?:const|let|var)\s+(\w+)\s*=\s*([^;\n]*)")
# `private const HIGH_MIN = 12;` in a risk file: an own scale the organisation cannot change.
BAND_CONST = re.compile(r"\bconst\s+(?:int\s+)?(?!\w*CIA)\w*(?:CRITICAL|HIGH|MEDIUM)\w*\s*=\s*(?:[2-9]|1\d|2[0-5])\s*;", re.I)


def normalise(line: str) -> str:
    return re.sub(r"\s+", " ", line.strip())


# A band edge dressed up: `>= (int) "12"`, `>= intval('12')`, `>= 12.0`, `>= "12"`: the same number.
_CAST_EDGE = re.compile(
    r"""(?P<op>>=|<=|>|<)\s*(?:\(\s*(?:int|integer|float|double)\s*\)\s*|(?:intval|floatval)\(\s*)?"""
    r"""['"]?(?P<n>\d+)(?:\.0+)?['"]?\s*\)?"""
)
_EDGE_FIRST = re.compile(
    r"""(?<![\w.$])(?:\(\s*(?:int|integer|float|double)\s*\)\s*|(?:intval|floatval)\(\s*)?['"]?(?P<n>\d+)(?:\.0+)?['"]?\s*\)?"""
    r"""\s*(?P<op><=|>=|<|>)(?!=)"""
)
# `private const HI = 12;`, `$hi = 12;`, `{% set hi = 12 %}`, `const HI = 12`: a name that only stands for an edge number.
_EDGE_NAME = {
    "*.php": re.compile(r"(?:\bconst\s+(?:int\s+)?|\$)([A-Za-z_]\w*)\s*=\s*(20|12|6|19|11|5)\s*;"),
    "*.twig": re.compile(r"\{%-?\s*set\s+(\w+)\s*=\s*(20|12|6|19|11|5)\s*-?%\}"),
    "*.js": re.compile(r"\b(?:const|let|var)\s+(\w+)\s*=\s*(20|12|6|19|11|5)\s*[;\n]"),
}


def resolve_edges(text: str, glob: str) -> str:
    """Spell out every disguise of a band edge as the plain number (line numbers stay)."""
    def cast(m: re.Match[str]) -> str:
        return f"{m.group('op')} {m.group('n')}" + (" " if m.group(0).endswith(" ") else "")
    text = _CAST_EDGE.sub(lambda m: cast(m) if m.group("n") in ("20", "12", "6", "19", "11", "5") else m.group(0), text)
    text = _EDGE_FIRST.sub(
        lambda m: f"{m.group('n')} {m.group('op')}" if m.group("n") in ("20", "12", "6", "19", "11", "5") else m.group(0), text)
    for m in _EDGE_NAME[glob].finditer(text):
        name, value = m.group(1), m.group(2)
        ref = (r"(?:(?:self|static|[A-Z]\w*)::)?" + (r"\$?" if glob == "*.php" and "$" in m.group(0) else "")
               + re.escape(name) + r"\b") if glob == "*.php" else r"(?<![\w.$])" + re.escape(name) + r"\b"
        after = text[m.end():]
        after = re.sub(r"(>=|<=|>|<)(\s*)" + ref, lambda c: f"{c.group(1)}{c.group(2)}{value}", after)
        after = re.sub(ref + r"(\s*)(>=|<=|>|<)", lambda c: f"{value}{c.group(1)}{c.group(2)}", after)
        text = text[:m.end()] + after
    return text


# `in_array($r->getRiskScore(), [20, 25, 24], true)` / Twig `risk.riskScore in [20, 25]`: a band as a list of scores.
_NUM_LIST = r"\[\s*\d+(?:\s*,\s*\d+)+\s*,?\s*\]"
IN_LIST_PHP = re.compile(r"(?is)\bin_array\(\s*([^;]*?)\s*,\s*(" + _NUM_LIST + r")")
IN_LIST_TWIG = re.compile(r"(?is)([\w.\[\]'\"()|]+)\s+(?:not\s+)?in\s+(" + _NUM_LIST + r")")
IN_RANGE_TWIG = re.compile(r"(?is)([\w.\[\]'\"()|]+)\s+(?:not\s+)?in\s+(\d+)\s*\.\.\s*(\d+)")


def band_lists(text: str, glob: str) -> set[int]:
    """Lines where a risk score is tested against a literal list / range of scores that is a band."""
    found: set[int] = set()
    operand = re.compile(OPERAND, re.I)
    for rx in ((IN_LIST_PHP,) if glob == "*.php" else (IN_LIST_TWIG,)):
        for m in rx.finditer(text):
            nums = [int(n) for n in re.findall(r"\d+", m.group(2))]
            if operand.search(m.group(1)) and max(nums) >= 6:
                found.add(text.count("\n", 0, m.start(2)) + 1)
    if glob == "*.twig":
        for m in IN_RANGE_TWIG.finditer(text):
            lo, hi = int(m.group(2)), int(m.group(3))
            if operand.search(m.group(1)) and (lo in (20, 12, 6) or hi in (19, 11, 5) or (lo, hi) in ((20, 25), (12, 19))):
                found.add(text.count("\n", 0, m.start(2)) + 1)
    return found


# Cheap pre-filter: the heavy patterns only run in a window around a comparison with an edge number.
EDGE_CMP = re.compile(r"(?:>=|<=|>|<)\s*(?:20|12|6|19|11|5)\b|\b(?:20|12|6|19|11|5)\s*(?:<=|<|>=|>)")
WINDOW = 260


def comparison_lines(text: str, patterns: tuple[re.Pattern[str], ...]) -> set[int]:
    lines: set[int] = set()
    for cmp_ in EDGE_CMP.finditer(text):
        start = max(0, cmp_.start() - WINDOW)
        end = min(len(text), cmp_.end() + WINDOW)
        window = text[start:end]
        for pattern in patterns:
            for m in pattern.finditer(window):
                at = m.start(2) if m.group(2) else m.start(5)
                if start + at < cmp_.start() - 4 or start + at > cmp_.end() + 4:
                    continue  # another comparison's match: found from its own window
                lines.add(text.count("\n", 0, start + at) + 1)
    return lines


def alias_hits(text: str, glob: str) -> set[int]:
    """Lines comparing a variable that was filled from a risk score with a band edge."""
    assign = {"*.php": PHP_ASSIGN, "*.twig": TWIG_SET, "*.js": JS_ASSIGN}[glob]
    found: set[int] = set()
    for m in assign.finditer(text):
        name, rhs = m.group(1), m.group(2)
        if not RISKISH.search(rhs) and not PRODUCT.match(rhs):
            continue
        last = re.findall(r"(?:->|\.|\[['\"]?)\s*(\w+)", rhs)
        if PRODUCT.match(rhs):
            last = ["risk"]  # probability * impact is the score itself
        elif (not ACCESSOR.match(rhs) or not (last or [rhs.strip(" ($")])
                or not RISK_NAME.search((last or [rhs.strip(" ($")])[-1]) or not RISKISH.search(rhs)
                or name.lower() in ("score", "level", "rating")):
            continue  # not a plain read of a risk value / those names are operands already
        sigil = r"\$" if glob == "*.php" else r"(?<![\w.$])"
        var = sigil + re.escape(name) + r"\b"
        edges = re.compile(
            r"(" + var + r")" + GAP + r"(>=|<=|>|<)\s*(20|12|6|19|11|5)\b(?![\d.]|\s*%(?!}))"
            r"|(?<![\w.$])(20|12|6|19|11|5)\s*(<=|<|>=|>)\s*" + GAP + var
        )
        for c in edges.finditer(text, m.end()):
            edge = c.group(3) or c.group(4)
            op = c.group(2) or c.group(5)
            # the "one below" edges only mean a band as `>` / `<=` (value 19, 11, 5)
            if edge in ("19", "11", "5") and op not in ((">", "<=") if c.group(2) else ("<", ">=")):
                continue
            found.add(text.count("\n", 0, c.start(2) if c.group(2) else c.start(5)) + 1)
    return found


def scan() -> list[tuple[str, int, str]]:
    hits = []
    for base, glob in SCAN:
        for path in sorted((ROOT / base).rglob(glob)):
            rel = path.relative_to(ROOT).as_posix()
            if rel in SKIP or "vendor" in path.parts:
                continue
            lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
            code = [("" if ln.strip().startswith(("//", "*", "#", "/*", "{#")) else ln) for ln in lines]
            text = "\n".join(code)
            # The line of the comparison operator (a match may start one line above it).
            if glob == "*.php":
                text = strip_php_comments(text)
            text = resolve_edges(text, glob)
            found = comparison_lines(text, (PATTERN, EDGE_BELOW))
            found |= alias_hits(text, glob)
            found |= band_lists(text, glob)
            if glob == "*.php" and re.search(r"risk", text, re.I):
                for pattern in (BAND_CONST, BAND_ARRAY, RANGE_BAND):
                    found |= {text.count("\n", 0, m.start()) + 1 for m in pattern.finditer(text)}
            for no in sorted(found):
                hits.append((rel, no, normalise(lines[no - 1])))
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


def split_args(text: str, open_pos: int) -> list[str]:
    """Top-level argument texts of the call whose '(' is at open_pos."""
    depth, quote, start, out = 0, "", open_pos + 1, []
    for i in range(open_pos, len(text)):
        ch = text[i]
        if quote:
            if ch == quote and text[i - 1] != "\\":
                quote = ""
        elif ch in "'\"":
            quote = ch
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
            if depth == 0:
                out.append(text[start:i].strip())
                return [a for a in out if a]
        elif ch == "," and depth == 1:
            out.append(text[start:i].strip())
            start = i + 1
    return []


NULL_ARG = re.compile(r"^\\?(?:null|none)$|\|\s*default\(\s*(?:null|none)\s*\)$", re.I)


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
            if glob == "*.php":
                pattern = php_call_pattern(text)
            for m in pattern.finditer(text):
                if glob == "*.php" and re.search(r"function\s+$", text[max(0, m.start() - 20):m.start()]):
                    continue
                n = count_args(text, m.end() - 1)
                line = text.count("\n", 0, m.start()) + 1
                if n is not None and n < needs[m.group(1)]:
                    hits.append((rel, line, m.group(1)))
                elif n is not None:
                    # The organisation argument is there but literally null: same defaults / viewer's scale.
                    args = split_args(text, m.end() - 1)
                    if len(args) >= needs[m.group(1)] and NULL_ARG.search(args[needs[m.group(1)] - 1]):
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
