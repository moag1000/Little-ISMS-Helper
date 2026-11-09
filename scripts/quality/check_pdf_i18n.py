#!/usr/bin/env python3
"""
check_pdf_i18n.py — Gate 102: evidence PDFs speak the reader's language.

W10, 2026-11: the processing register (GDPR Art. 30), the breach report
(Art. 33/34), the DPIA and the compliance exports mixed German titles, English
field labels and raw enum keys; four templates said lang="de" whatever the
language, three said nothing. A PDF goes to an authority or an auditor, so a
mixed-language one is a weak record.

Every PDF template (a .twig file whose name or folder says "pdf", plus the
compliance export PDFs under templates/pdf/) is checked for:

  text     text between tags that is not a Twig expression ("Organization:",
           "Not specified"); language-neutral tokens (norm numbers, acronyms,
           punctuation) are allowed
  literal  a quoted word printed as output inside {{ }} without |trans
           (`x ? 'YES' : 'NO'`, `x ?? 'Not specified'`, `|default('None')`)
  lang     <html> without lang="{{ app.locale }}" (fixed "de" or missing);
           app.locale follows the LocaleSwitcher, so jobs and scheduled reports
           without a request get it right too
  enum     a stored key made readable by |upper / |title / |capitalize /
           |replace({'_': ' '}) instead of a translation
  page     a fixed "Seite x von y" / "Page x of y" in @page CSS

Per-file counts of templates not yet migrated are baselined and may only shrink.

    python3 scripts/quality/check_pdf_i18n.py --baseline scripts/quality/baselines/pdf_i18n.txt
    python3 scripts/quality/check_pdf_i18n.py --write-baseline scripts/quality/baselines/pdf_i18n.txt
    python3 scripts/quality/check_pdf_i18n.py --show templates/processing_activity/vvt_pdf.html.twig
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATES = ROOT / "templates"

# UI components that only carry "pdf" in the name.
NOT_PDF = {
    "templates/_components/_fa_pdf_locale_switch.html.twig",
}

# German on purpose (I18N-7): forms addressed to German authorities follow their German templates
# (BfDI register, notifications to BfDI / Landesdatenschutz / BSI-Meldestelle and the generic DPA letter).
GERMAN_ON_PURPOSE = {
    "templates/processing_activity/vvt_bfdi_export.html.twig",
    "templates/authority/notification/bfdi_breach.html.twig",
    "templates/authority/notification/bsi_meldestelle.html.twig",
    "templates/authority/notification/generic_breach.html.twig",
    "templates/authority/notification/lfdi_breach.html.twig",
}
SRC = ROOT / "src"
GENERATE_PDF = re.compile(r"generatePdf\(\s*'([^']+\.twig)'")

# Words that read the same in German and English documents.
NEUTRAL = {
    "iso", "iec", "nis", "nis-2", "nis2", "dora", "isms", "bsi", "tisax", "ict", "pdf", "id", "kpi", "kpis",
    "soa", "rto", "rpo", "mtpd", "bia", "ciso", "mris", "csv", "xlsx", "it", "ot", "url", "e-mail", "email",
    "cvss", "cve", "tlpt", "dpo", "vs", "ok", "n/a", "eu", "ews", "a", "b", "c", "d", "tom", "toms", "isb",
    "little", "helper", "fte", "eur", "sha", "vda", "md5", "uuid", "html", "json", "xml", "lksg", "kritis", "c5", "ai", "sla", "slas", "ip", "ceo", "cio", "cto",
}

TWIG_COMMENT = re.compile(r"\{#.*?#\}", re.S)
TWIG_BLOCK = re.compile(r"\{#.*?#\}|\{%.*?%\}|\{\{.*?\}\}", re.S)
STYLE_SCRIPT = re.compile(r"<(style|script)\b.*?</\1>", re.S | re.I)
TAG = re.compile(r"<[^>]*>", re.S)
ENTITY = re.compile(r"&[a-zA-Z#0-9]+;")
WORD = re.compile(r"[A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß\-/]*")
OUTPUT = re.compile(r"\{\{(.*?)\}\}", re.S)
SET_OR_IF = re.compile(r"\{%-?\s*(set|if|elseif)\b(.*?)-?%\}", re.S)
QUOTED = re.compile(r"'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\"")
HTML_TAG = re.compile(r"<html\b[^>]*>", re.I)
ENUM_FILTER = re.compile(r"\|\s*(upper|title|capitalize)\b|\|\s*replace\(\s*\{\s*['\"]_['\"]")
PAGE_CSS = re.compile(r"content\s*:\s*[\"'](Seite|Page)\b", re.I)
GOOD_LANG = re.compile(r"lang=\"\{\{\s*app\.locale\s*\}\}\"")


def pdf_templates() -> list[Path]:
    """Templates named or filed as PDF, plus every template src/ hands to generatePdf()."""
    rendered = set()
    for php in SRC.rglob("*.php"):
        for m in GENERATE_PDF.finditer(php.read_text(encoding="utf-8", errors="replace")):
            rendered.add(m.group(1))
    out = []
    for p in sorted(TEMPLATES.rglob("*.twig")):
        rel = str(p.relative_to(ROOT))
        if rel in NOT_PDF or rel in GERMAN_ON_PURPOSE:
            continue
        parts = [s.lower() for s in p.relative_to(TEMPLATES).parts]
        named = "pdf" in parts[-1] or any(s == "pdf" or s.startswith("pdf") for s in parts[:-1])
        if named or str(p.relative_to(TEMPLATES)) in rendered:
            out.append(p)
    return out


def is_prose(s: str) -> bool:
    words = [w.lower().strip("-/") for w in WORD.findall(s)]
    words = [w for w in words if w]
    if not words:
        return False
    return any(len(w) >= 2 and w not in NEUTRAL for w in words)


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def findings(text: str) -> list[tuple[int, str, str]]:
    out: list[tuple[int, str, str]] = []

    # page counter text in CSS
    for m in PAGE_CSS.finditer(text):
        out.append((line_of(text, m.start()), "page", m.group(0)))

    # <html lang>
    for m in HTML_TAG.finditer(text):
        if not GOOD_LANG.search(m.group(0)):
            out.append((line_of(text, m.start()), "lang", m.group(0)))

    # text nodes: blank out style/script, Twig blocks, tags, keep offsets
    def blank(m: re.Match) -> str:
        return re.sub(r"[^\n]", " ", m.group(0))

    body = TWIG_BLOCK.sub(blank, text)
    body = STYLE_SCRIPT.sub(blank, body)
    body = TAG.sub(blank, body)
    body = ENTITY.sub(" ", body)
    for i, line in enumerate(body.split("\n"), start=1):
        chunk = line.strip()
        if chunk and is_prose(chunk):
            out.append((i, "text", chunk))

    # printed literals and raw enum filters inside {{ }}
    for m in OUTPUT.finditer(TWIG_COMMENT.sub(blank, text)):
        expr = m.group(1)
        ln = line_of(text, m.start())
        if in_tag(text, m.start()):
            continue  # attribute values (CSS classes, ids) are not read
        if "|trans" not in expr and ENUM_FILTER.search(expr):
            out.append((ln, "enum", "{{" + expr.strip() + "}}"))
        out.extend((ln, "literal", lit) for lit in printed_literals(expr))
    return out


def in_tag(text: str, pos: int) -> bool:
    return text.rfind("<", 0, pos) > text.rfind(">", 0, pos)


def innermost_call(before: str) -> str | None:
    """Name of the call whose '(' is still open at the end of `before` ('' for a bare paren)."""
    stack: list[int] = []
    quote = None
    for i, ch in enumerate(before):
        if quote:
            if ch == quote and before[i - 1] != "\\":
                quote = None
            continue
        if ch in "'\"":
            quote = ch
        elif ch == "(":
            stack.append(i)
        elif ch == ")" and stack:
            stack.pop()
    if not stack:
        return None
    m = re.search(r"([A-Za-z_][A-Za-z0-9_]*)\s*$", before[: stack[-1]])
    return m.group(1) if m else ""


SHOWN_AFTER = re.compile(r"^\)?\s*(\||$|~|\}|:|\?)")
FORMAT_FILTER = re.compile(r"^\)?\s*\|\s*(?!upper|title|capitalize|replace|default)[a-z_]+")


def printed_literals(expr: str) -> list[str]:
    """Quoted prose that ends up in the output untranslated."""
    found = []
    for q in QUOTED.finditer(expr):
        s = q.group(1) if q.group(1) is not None else q.group(2)
        if not is_prose(s) or re.fullmatch(r"[a-z0-9_]+(\.[a-z0-9_]+)+\.?", s):
            continue  # translation keys and key prefixes
        if re.fullmatch(r"[a-z][a-z0-9_]*", s) and "|trans" in expr[q.end():]:
            continue  # part of a composed key: ('x.' ~ (v ?? 'unknown'))|trans
        before = expr[: q.start()].rstrip()
        after = expr[q.end():].lstrip()
        if after.startswith("|trans") or after.startswith("|"):
            continue  # translated, or formatted further (date formats, joins)
        if after.startswith(":") and before.endswith(("{", ",")):
            continue  # hash key
        if before.endswith(("==", "!=", "in", "is", "starts with", "ends with", "matches")):
            continue  # comparison operand
        if after.startswith(("==", "!=", "in ", "is ", "not in")):
            continue
        # argument of a function/filter call other than default()
        callee = innermost_call(before)
        if callee is not None and callee not in ("default", ""):
            continue
        if FORMAT_FILTER.match(after):
            continue  # default('now')|fa_date, x|default('a')|lower in logic
        if before.endswith("[") or before.endswith("."):
            continue  # attribute access x['key']
        found.append(s)
    return found


def counts() -> dict[str, int]:
    out = {}
    for p in pdf_templates():
        n = len(findings(p.read_text(encoding="utf-8")))
        if n:
            out[str(p.relative_to(ROOT))] = n
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--baseline")
    ap.add_argument("--write-baseline")
    ap.add_argument("--show", nargs="+", metavar="TEMPLATE")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    if args.show:
        for name in args.show:
            p = Path(name)
            for ln, kind, s in findings(p.read_text(encoding="utf-8")):
                print(f"{name}:{ln}: [{kind}] {s[:100]}")
        return 0

    current = counts()
    if args.write_baseline:
        Path(args.write_baseline).write_text(
            "# PDF templates with untranslated text, fixed lang or raw enum output (Gate 102):"
            " path<TAB>count. May only shrink.\n"
            + "".join(f"{k}\t{v}\n" for k, v in current.items()),
            encoding="utf-8",
        )
        print(f"check_pdf_i18n: wrote {len(current)} entries")
        return 0

    baseline: dict[str, int] = {}
    if args.baseline:
        for line in Path(args.baseline).read_text(encoding="utf-8").splitlines():
            if line.strip() and not line.startswith("#"):
                path, _, n = line.partition("\t")
                baseline[path.strip()] = int(n or 0)

    failed = False
    grown = {k: v for k, v in current.items() if v > baseline.get(k, 0)}
    if grown:
        failed = True
        print("check_pdf_i18n: PDF text outside |trans, fixed <html lang> or raw enum output"
              " (see --show <template>):")
        for k, v in grown.items():
            print(f"  {k}: {v} (baseline {baseline.get(k, 0)})")
    shrunk = {k: v for k, v in baseline.items() if current.get(k, 0) < v}
    if shrunk:
        failed = True
        print("check_pdf_i18n: baseline is stale (fewer findings now) — run --write-baseline:")
        for k, v in shrunk.items():
            print(f"  {k}: {current.get(k, 0)} (baseline {v})")
    if not failed and not args.quiet:
        print(f"check_pdf_i18n: OK ({len(pdf_templates())} PDF templates, {sum(current.values())} baselined)")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
