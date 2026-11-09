r"""Shared detector for Gate 77: dates shown to users with a hard-coded |date format.

A display date sits in `{{ ... }}` or `{% ... %}` (set/include hashes) in text content (not inside an HTML tag, so
not in value=/min=/data-* attributes) and uses any |date() format except the machine ones
(`U`, `u`, `c`, ISO `Y-m-d\TH:i…`). Those go through |fa_date / |fa_datetime (seconds:
`fa_datetime(true)`), |fa_time and |fa_month, which apply the admin-configured format,
timezone and locale.
"""

from __future__ import annotations

import re

# format → replacement filter (--fix); any other display format becomes fa_date
DISPLAY_FORMATS = {
    "d.m.Y H:i": "fa_datetime",
    "Y-m-d H:i": "fa_datetime",
    "d.m.Y H:i:s": "fa_datetime(true)",
    "Y-m-d H:i:s": "fa_datetime(true)",
    "d.m.Y": "fa_date",
    "Y-m-d": "fa_date",
    "H:i": "fa_time",
    "H:i:s": "fa_time(true)",
    "F Y": "fa_month",
}

# Formats for machines (timestamps for comparisons, ISO values), never shown as text.
MACHINE_FORMATS = re.compile(r"^(U|u|c|Y-m-d\\{1,2}TH:i(:s)?(P|O)?)$")

_DATE_CALL = re.compile(r"""\|\s*date\s*\(\s*(['"])([^'"]*)\1\s*(?:,[^)]*)?\)""")
_OUTPUT = re.compile(r"\{\{.*?\}\}|\{%.*?%\}", re.S)
# Other routes to the same hard-coded display date: |date without a literal format (default format or a
# variable format), the Twig intl filters and DateTime::format() in a template.
_DATE_DYNAMIC = re.compile(
    r"""\|\s*date(?!\w)(?!\s*\(\s*(['"])[^'"]*\1\s*(?:,[^)]*)?\))(?:\s*\([^()]*\))?"""
    r"""|\|\s*format_(?:date|datetime|time)\s*\([^()]*\)"""
    r"""|\.format\(\s*['"][^'"]*['"]\s*\)"""
)
# Attributes whose value a person reads (tooltips, labels); machine attributes (datetime, value, data-*) stay free.
_HUMAN_ATTR = re.compile(
    r"""([\w:-]+)\s*=\s*(?:"[^"]*|'[^']*)$""", re.S
)
_HUMAN_ATTR_NAMES = {"title", "aria-label", "aria-description", "placeholder", "alt", "label",
                     "data-bs-title", "data-bs-content", "data-title", "data-tooltip"}


def _in_human_attr(text: str, pos: int) -> bool:
    lt = text.rfind("<", 0, pos)
    m = _HUMAN_ATTR.search(text[lt:pos])
    return bool(m) and m.group(1).lower() in _HUMAN_ATTR_NAMES


def _call_spans(body: str):
    """(start, end) of the argument list of every path()/url()/asset() call in `body`."""
    for m in re.finditer(r"\b(?:path|url|asset)\(", body):
        depth, i = 1, m.end()
        while depth and i < len(body):
            depth += {"(": 1, ")": -1}.get(body[i], 0)
            i += 1
        yield m.end(), i


def _in_tag(text: str, pos: int) -> bool:
    """True when `pos` lies inside an HTML tag (between '<' and '>')."""
    lt = text.rfind("<", 0, pos)
    gt = text.rfind(">", 0, pos)
    if lt <= gt:
        return False
    # '<' followed by a letter or '/' opens a tag; '< ' or '<=' inside Twig does not.
    nxt = text[lt + 1 : lt + 2]
    return bool(nxt) and (nxt.isalpha() or nxt == "/")


_SCRIPT = re.compile(r"<(script|style)\b.*?</\1>", re.S | re.I)  # <textarea> content is text a person reads (T2)


def display_dates(text: str):
    """Yield (start, end, format) for every display-date |date call in `text`."""
    skipped = [(m.start(), m.end()) for m in _SCRIPT.finditer(text)]
    skipped += [(m.start(), m.end()) for m in re.finditer(r"\{#.*?#\}", text, re.S)]  # usage examples in comments
    for out in _OUTPUT.finditer(text):
        if any(a <= out.start() < b for a, b in skipped):
            continue
        if _in_tag(text, out.start()) and not _in_human_attr(text, out.start()):
            continue
        body = out.group(0)
        # Strings built for machines stay as they are: URL builders, and
        # expressions marked with a `{# machine-date #}` comment on the line
        # before (route params, grouping keys).
        route_args = list(_call_spans(body))
        line_start = text.rfind("\n", 0, out.start())
        prev_start = text.rfind("\n", 0, max(line_start, 0))
        if "machine-date" in text[max(prev_start, 0) : out.start()]:
            continue
        for m in _DATE_CALL.finditer(body):
            if MACHINE_FORMATS.match(m.group(2)) or any(a <= m.start() < b for a, b in route_args):
                continue
            yield out.start() + m.start(), out.start() + m.end(), m.group(2)
        for m in _DATE_DYNAMIC.finditer(body):
            if not any(a <= m.start() < b for a, b in route_args):
                yield out.start() + m.start(), out.start() + m.end(), ""


def fix(text: str) -> tuple[str, int]:
    hits = list(display_dates(text))
    for start, end, fmt in reversed(hits):
        text = text[:start] + "|" + DISPLAY_FORMATS.get(fmt, "fa_date") + text[end:]
    return text, len(hits)
