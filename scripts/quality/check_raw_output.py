#!/usr/bin/env python3
"""
check_raw_output.py — Gate 91: output is escaped unless a reviewed source says otherwise.

The fifth norm assessment (S10, 2026-11) found Twig components that printed their
text slots with `|raw` (`_fa_alert` body, `_fa_modal` message, `_fa_table` cells,
`_fa_report_doc` cells, …). Callers passed record text into them — a user's own
first name, a rejection reason, a TISAX title from an uploaded workbook — so a
record could carry a form, a link or a Stimulus `data-action` element into every
page that showed it. The CSP stops scripts, not markup. The DOCX export wrote
titles into word/document.xml without escaping, so a title could inject a Word
field (INCLUDEPICTURE → UNC fetch, NTLM hash leak on open).

One mechanism instead of single fixes:
  * components print every slot escaped; HTML reaches a slot only as Twig
    `Markup` (macro output, `{% set x %}…{% endset %}` capture, `include()`,
    or `|fa_html` on reviewed HTML);
  * JSON for scripts and attributes is `|fa_json` (hex-escapes < > & ' "),
    never `|json_encode|raw`;
  * a PhpWord document is created only by `DocxDocumentFactory::create()`,
    which switches PhpWord's output escaping on.

Rules (templates/**/*.twig, src/**/*.twig):
  R1  every `|raw` / `|fa_html` is one of
        a. `'literal.key'|trans({...}, 'domain')|raw` whose parameters are all
           literals or escaped (`|e`, `|escape`, `|length`, `|number_format`, …)
           — translator HTML is repo content, parameters are not;
        b. an entry in scripts/quality/baselines/raw_output_allowlist.txt
           (`path | expression | reason`), each entry with its reason.
      `|raw` on a macro call is rejected as redundant (macro output is Markup)
      and `|json_encode|raw` is rejected in favour of `|fa_json`.
  R2  an argument passed to a component (`_components/*`, `_macros/*`) carries
      no HTML string literal and no translation that contains HTML unless the
      value ends in `|fa_html` — the component escapes it, the tags would show.
Rules (src/**/*.php):
  R3  `new PhpWord(` only in src/Service/Export/DocxDocumentFactory.php, no
      `TemplateProcessor`, never `setOutputEscapingEnabled(false)`.
  R4  `new Markup(` and Twig callables declared `is_safe` carry a
      `// @markup-safe: <reason>` comment (same or previous line) that names
      how record text is escaped before it becomes HTML.

Stale allowlist entries fail too, so the list only shrinks.

    python3 scripts/quality/check_raw_output.py [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ALLOWLIST = ROOT / "scripts" / "quality" / "baselines" / "raw_output_allowlist.txt"
DOCX_FACTORY = "src/Service/Export/DocxDocumentFactory.php"
MARKUP_FILTERS = "src/Twig/OutputEscapingExtension.php"

RAW = re.compile(r"\|\s*(raw|fa_html)\b")
SAFE_PARAM_TAIL = re.compile(
    r"\|\s*(e|escape|length|number_format|round|abs|format_number|fa_date|fa_datetime|fa_time|fa_month)"
    r"(\([^()]*\))?\s*$"
)
LITERAL = re.compile(r"""^\s*(-?\d+(\.\d+)?|'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|true|false|null)\s*$""")
HTML_IN_LITERAL = re.compile(r"""'(?:[^'\\]|\\.)*<[a-zA-Z/!](?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*<[a-zA-Z/!](?:[^"\\]|\\.)*\"""")
TRANS_KEY = re.compile(r"""^\(?\s*'([\w.\-]+)'\s*\|\s*trans\b""")
HTML_TEXT = re.compile(r"<[a-zA-Z/][^>]*>|&[a-z#0-9]+;")


# ── Twig expression helpers ────────────────────────────────────────────────

def _escaped(s: str, j: int) -> bool:
    """True when s[j] is preceded by an odd number of backslashes."""
    n = 0
    k = j - 1
    while k >= 0 and s[k] == "\\":
        n += 1
        k -= 1
    return n % 2 == 1


def _skip_string_back(s: str, j: int) -> int:
    """s[j] is a closing quote; return index of the opening quote."""
    q = s[j]
    j -= 1
    while j >= 0:
        if s[j] == q and not _escaped(s, j):
            return j
        j -= 1
    return 0


def _match_back(s: str, j: int) -> int:
    """s[j] is a closing bracket; return index of its opener (strings skipped)."""
    pairs = {")": "(", "]": "[", "}": "{"}
    stack = [s[j]]
    j -= 1
    while j >= 0 and stack:
        ch = s[j]
        if ch in "'\"":
            j = _skip_string_back(s, j)
        elif ch in pairs:
            stack.append(ch)
        elif ch in "([{":
            if pairs[stack[-1]] == ch:
                stack.pop()
                if not stack:
                    return j
        j -= 1
    return max(j, 0)


def operand_before(s: str, pos: int) -> str:
    """The postfix expression a filter at `pos` (the `|`) applies to."""
    j = pos - 1
    while j >= 0 and s[j] in " \t":
        j -= 1
    end = j + 1
    while j >= 0:
        ch = s[j]
        if ch in ")]}":
            j = _match_back(s, j) - 1
        elif ch in "'\"":
            j = _skip_string_back(s, j) - 1
        elif ch.isalnum() or ch in "_.|":
            j -= 1
        elif ch in " \t\n" and j > 0:
            # `x | filter` — allow whitespace around a pipe only
            k = j
            while k >= 0 and s[k] in " \t\n":
                k -= 1
            if k >= 0 and s[k] == "|":
                j = k - 1
                continue
            nxt = end and s[j + 1:end].lstrip()[:1]
            if nxt == "|":
                j = k
                continue
            break
        else:
            break
    return s[j + 1:end].strip()


def balanced_from(s: str, i: int) -> str:
    """s[i] is an opener; return the balanced substring."""
    pairs = {"(": ")", "[": "]", "{": "}"}
    stack = []
    j = i
    while j < len(s):
        ch = s[j]
        if ch in "'\"":
            q = ch
            j += 1
            while j < len(s) and not (s[j] == q and not _escaped(s, j)):
                j += 1
        elif ch in pairs:
            stack.append(pairs[ch])
        elif stack and ch == stack[-1]:
            stack.pop()
            if not stack:
                return s[i:j + 1]
        j += 1
    return s[i:]


def split_top(inner: str, sep: str = ",") -> list[str]:
    parts, cur, depth, j = [], [], 0, 0
    while j < len(inner):
        ch = inner[j]
        if ch in "'\"":
            q = ch
            k = j + 1
            while k < len(inner) and not (inner[k] == q and not _escaped(inner, k)):
                k += 1
            cur.append(inner[j:k + 1])
            j = k + 1
            continue
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        if ch == sep and depth == 0:
            parts.append("".join(cur))
            cur = []
        else:
            cur.append(ch)
        j += 1
    if "".join(cur).strip():
        parts.append("".join(cur))
    return parts


def hash_pairs(h: str) -> list[tuple[str, str]]:
    h = h.strip()
    if not (h.startswith("{") and h.endswith("}")):
        return []
    out = []
    for p in split_top(h[1:-1]):
        m = re.match(r"""\s*(?:'([^']+)'|"([^"]+)"|([\w%-]+)|\(([^)]*)\))\s*:\s*(.*)$""", p, re.S)
        if m:
            out.append((m.group(1) or m.group(2) or m.group(3) or m.group(4) or "", m.group(5).strip()))
    return out


def norm(expr: str) -> str:
    return re.sub(r"\s+", " ", expr).strip()


def strip_parens(expr: str) -> str:
    e = expr.strip()
    while e.startswith("(") and balanced_from(e, 0) == e:
        e = e[1:-1].strip()
    return e


# ── R1 ─────────────────────────────────────────────────────────────────────

def macro_aliases(src: str) -> set[str]:
    names = set(re.findall(r"\{%-?\s*import\s+(?:'[^']+'|\"[^\"]+\"|_self)\s+as\s+(\w+)", src))
    for m in re.finditer(r"\{%-?\s*from\s+\S+\s+import\s+([^%]+)%\}", src):
        for part in m.group(1).split(","):
            bits = part.split(" as ")
            names.add(bits[-1].strip().rstrip("-").strip())
    names.add("_self")
    return names


def _safe_value(v: str) -> bool:
    """A literal, an escaped/numeric expression, or a generated URL."""
    v = strip_parens(v.strip()) if v.strip().startswith("(") and not SAFE_PARAM_TAIL.search(v.strip()) else v.strip()
    return bool(LITERAL.match(v) or SAFE_PARAM_TAIL.search(v) or re.match(r"^(path|url|csrf_token)\(", v))


def trans_params_safe(expr: str) -> tuple[bool, str]:
    """expr = `'key'|trans(...)` (optionally with more safe filters). Returns (ok, why)."""
    m = re.match(r"""^\s*'[\w.\-]+'\s*\|\s*trans\b""", expr)
    if not m:
        return False, "dynamic translation key"
    rest = expr[m.end():].lstrip()
    if rest.startswith("("):
        args = balanced_from(rest, 0)
        params = split_top(args[1:-1])
        if params:
            first = params[0].strip()
            for key, value in hash_pairs(first) if first.startswith("{") else [("?", first)]:
                if all(_safe_value(part) for part in split_top(value, "~")):
                    continue
                return False, f"parameter {key} = {norm(value)[:60]} is not escaped (add |e)"
        rest = rest[len(args):]
    if rest.strip() and not re.fullmatch(r"(\s*\|\s*(nl2br|trim|default\([^()]*\)))*\s*", rest):
        return False, "filters after trans that may change the HTML"
    return True, ""


def load_allowlist() -> dict[tuple[str, str], str]:
    out: dict[tuple[str, str], str] = {}
    if not ALLOWLIST.exists():
        return out
    for line in ALLOWLIST.read_text(encoding="utf-8").splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        parts = [p.strip() for p in line.split(" | ", 2)]
        if len(parts) != 3 or not parts[2]:
            out[("__malformed__", line)] = ""
            continue
        out[(parts[0], norm(parts[1]))] = parts[2]
    return out


def twig_files() -> list[Path]:
    files = list((ROOT / "templates").rglob("*.twig"))
    files += list((ROOT / "src").rglob("*.twig"))
    return sorted(files)


def check_r1(files: list[Path], allow: dict, used: set) -> list[str]:
    errors = []
    for f in files:
        rel = f.relative_to(ROOT).as_posix()
        src = f.read_text(encoding="utf-8")
        # Twig comments never render
        code = re.sub(r"\{#.*?#\}", lambda m: re.sub(r"[^\n]", " ", m.group(0)), src, flags=re.S)
        aliases = macro_aliases(code)
        for m in RAW.finditer(code):
            line = code.count("\n", 0, m.start()) + 1
            op = operand_before(code, m.start())
            key = (rel, norm(op))
            if key in allow:
                used.add(key)
                continue
            bare = strip_parens(op)
            if re.search(r"\|\s*json_encode(\([^()]*(\([^()]*\))*[^()]*\))?$", bare):
                errors.append(f"{rel}:{line}: `{norm(op)[:80]}|{m.group(1)}` — use |fa_json (hex-escapes < > & ' \")")
                continue
            mc = re.match(r"^(\w+)\.\w+\s*\(", bare)
            if mc and mc.group(1) in aliases and balanced_from(bare, bare.index("(")) == bare[bare.index("("):]:
                errors.append(f"{rel}:{line}: `|{m.group(1)}` on macro output `{norm(bare)[:60]}` — drop it, macro output is already Markup")
                continue
            if TRANS_KEY.match(bare):
                ok, why = trans_params_safe(bare)
                if ok:
                    continue
                errors.append(f"{rel}:{line}: `{norm(op)[:80]}|{m.group(1)}` — {why}")
                continue
            errors.append(
                f"{rel}:{line}: `{norm(op)[:80]}|{m.group(1)}` — unreviewed raw output; print escaped, pass Markup, "
                f"or add `{rel} | {norm(op)} | <reason>` to scripts/quality/baselines/{ALLOWLIST.name}"
            )
    return errors


# ── R2 ─────────────────────────────────────────────────────────────────────

def translation_html_keys() -> set[str]:
    keys: set[str] = set()
    for f in (ROOT / "translations").glob("*.yaml"):
        try:
            data = yaml.safe_load(f.read_text(encoding="utf-8")) or {}
        except yaml.YAMLError:
            continue

        def walk(node, prefix):
            if isinstance(node, dict):
                for k, v in node.items():
                    walk(v, f"{prefix}.{k}" if prefix else str(k))
            elif isinstance(node, str) and HTML_TEXT.search(node):
                keys.add(prefix)

        walk(data, "")
    return keys


COMPONENT_IMPORT = re.compile(r"""\{%-?\s*import\s+['"]((?:_components|_macros)/[^'"]+)['"]\s+as\s+(\w+)""")
COMPONENT_INCLUDE = re.compile(r"""\{%-?\s*(?:include|embed)\s+['"]((?:_components|_macros)/[^'"]+)['"]\s+with\s+(?=\{)""")


def html_string_vars(code: str, html_keys: set[str]) -> set[str]:
    """Names assigned an HTML string with `{% set x = … %}` (a string, not Markup)."""
    names: set[str] = set()
    for _ in range(3):  # x = '<b>' … ; y = x ~ … ; rows|merge([x])
        for m in re.finditer(r"\{%-?\s*set\s+(\w+)\s*=\s*(.*?)-?%\}", code, re.S):
            name, expr = m.group(1), m.group(2)
            if re.search(r"\|\s*fa_(html|json)\s*$", expr.strip()):
                continue
            if values_needing_markup(expr, html_keys, "", names):
                names.add(name)
    return names


def values_needing_markup(
    expr: str, html_keys: set[str], path: str = "", html_vars: set[str] | None = None
) -> list[tuple[str, str]]:
    """Leaf values of a component argument that would print HTML as text."""
    html_vars = html_vars or set()
    e = expr.strip()
    if re.search(r"\|\s*fa_html\s*$", e) or re.search(r"\|\s*fa_json\s*$", e):
        return []
    inner = strip_parens(e)
    if inner.startswith("{") and balanced_from(inner, 0) == inner:
        out = []
        for k, v in hash_pairs(inner):
            out += values_needing_markup(v, html_keys, f"{path}.{k}" if path else k, html_vars)
        return out
    if inner.startswith("[") and balanced_from(inner, 0) == inner:
        out = []
        for i, v in enumerate(split_top(inner[1:-1])):
            out += values_needing_markup(v, html_keys, f"{path}[{i}]", html_vars)
        return out
    # `cond ? a : b` and `a ?? b`: either branch reaches the slot
    tern = split_top(inner, "?")
    if len(tern) == 2 and ":" in tern[1]:
        branches = split_top(tern[1], ":")
        if len(branches) == 2:
            return [hit for b in branches for hit in values_needing_markup(b, html_keys, path, html_vars)]
    # `|merge([...])` / `|map(...)` / `|default(...)` carry their arguments into the value
    for fm in re.finditer(r"\|\s*(merge|default)\s*\(", inner):
        arg = balanced_from(inner, fm.end() - 1)[1:-1]
        hits = values_needing_markup(arg, html_keys, path, html_vars)
        if hits:
            return hits
    if re.search(r"\|\s*(nl2br|striptags\([^()]*\)\s*\|\s*nl2br)\s*$", inner) or re.match(r"^(form_widget|form_row|form_label|form_errors|form_rest|block)\(", inner):
        return [(path, "this output is a string, not Markup, so the component escapes its tags; wrap it in a {% set x %}…{% endset %} capture")]
    if re.search(r"\|\s*(e|escape)(\(\s*'html'\s*\))?\s*$", inner):
        return [(path, "value is escaped with |e and the component escapes it again (&amp;amp;); drop the |e")]
    if re.fullmatch(r"\w+", inner) and inner in html_vars:
        return [(path, f"variable {inner} holds an HTML string (set with = …)")]
    # attribute maps (`attrs: {...}`) and data/url values are printed into attributes, never as HTML
    if re.search(r"(^|\.)(attrs|attr|card_attrs)(\.|$)|(^|\.)(href|url|data|id|class|icon)$", path):
        return []
    # Twig filter/function arguments are not slot values themselves; a literal inside
    # `'k'|trans({'%x%': '<b>'})` is fine when the whole value ends in |fa_html (handled above)
    if HTML_IN_LITERAL.search(re.sub(r"\|\s*trans\s*\(\s*\{.*?\}\s*", "|trans(", inner, flags=re.S)):
        return [(path, "HTML string literal")]
    tk = TRANS_KEY.match(inner)
    if tk and tk.group(1) in html_keys:
        return [(path, f"translation {tk.group(1)} contains HTML")]
    return []


def check_r2(files: list[Path], html_keys: set[str], allow: dict, used: set) -> list[str]:
    errors = []
    for f in files:
        rel = f.relative_to(ROOT).as_posix()
        if rel.startswith("templates/dev/"):
            continue  # design-system showcase prints its own examples
        src = f.read_text(encoding="utf-8")
        code = re.sub(r"\{#.*?#\}", lambda m: re.sub(r"[^\n]", " ", m.group(0)), src, flags=re.S)
        calls: list[tuple[int, str]] = []
        for m in COMPONENT_IMPORT.finditer(code):
            alias = m.group(2)
            for c in re.finditer(r"\b" + re.escape(alias) + r"\.\w+\s*\(", code):
                start = c.end() - 1
                calls.append((start, balanced_from(code, start)[1:-1]))
        for m in COMPONENT_INCLUDE.finditer(code):
            calls.append((m.end(), balanced_from(code, m.end())))
        html_vars = html_string_vars(code, html_keys) if calls else set()
        seen: set[str] = set()
        for start, args in sorted(set(calls)):
            line = code.count("\n", 0, start) + 1
            for arg in split_top(args):
                for path, why in values_needing_markup(arg, html_keys, "", html_vars):
                    key = (rel, f"slot {path or '(arg)'} @ {norm(arg)[:60]}")
                    if key in allow:
                        used.add(key)
                        continue
                    msg_key = f"{line}:{path}:{why}"
                    if msg_key in seen:
                        continue
                    seen.add(msg_key)
                    errors.append(
                        f"{rel}:{line}: component argument `{path or norm(arg)[:40]}` — {why}; the component escapes it. "
                        "Pass a {% set x %}…{% endset %} capture, or end the value in |fa_html (parameters |e)"
                    )
    return errors


# ── R3 ─────────────────────────────────────────────────────────────────────

def check_r3() -> list[str]:
    errors = []
    for f in sorted((ROOT / "src").rglob("*.php")):
        rel = f.relative_to(ROOT).as_posix()
        src = f.read_text(encoding="utf-8")
        lines = src.splitlines()
        for i, line in enumerate(lines, 1):
            if re.search(r"new\s+\\?(PhpOffice\\PhpWord\\)?PhpWord\s*\(", line) and rel != DOCX_FACTORY:
                errors.append(f"{rel}:{i}: PhpWord document created outside DocxDocumentFactory::create() — output escaping may be off")
            if re.search(r"\bTemplateProcessor\b", line) and not line.lstrip().startswith(("*", "//")):
                errors.append(f"{rel}:{i}: PhpWord TemplateProcessor — setValue() writes XML unescaped unless output escaping is on; use DocxDocumentFactory")
            if re.search(r"setOutputEscapingEnabled\s*\(\s*false", line):
                errors.append(f"{rel}:{i}: PhpWord output escaping switched off")
            if rel == MARKUP_FILTERS:
                continue
            marked = "@markup-safe:" in line or (i > 1 and "@markup-safe:" in lines[i - 2])
            if re.search(r"new\s+\\?(Twig\\)?Markup\s*\(", line) and not marked:
                errors.append(
                    f"{rel}:{i}: `new Markup()` — record text would print as HTML; escape it first and say how in "
                    "a `// @markup-safe: <reason>` comment on this or the previous line"
                )
            if re.search(r"""['"]is_safe['"]\s*=>|\bisSafe\s*:""", line) and not marked:
                errors.append(
                    f"{rel}:{i}: Twig callable marked is_safe — its output is printed unescaped; "
                    "add `// @markup-safe: <reason>` once it escapes record text itself"
                )
    return errors


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    allow = load_allowlist()
    used: set = set()
    errors: list[str] = []
    for key in allow:
        if key[0] == "__malformed__":
            errors.append(f"{ALLOWLIST.name}: malformed line (need `path | expression | reason`): {key[1]}")
    files = twig_files()
    errors += check_r1(files, allow, used)
    errors += check_r2(files, translation_html_keys(), allow, used)
    errors += check_r3()
    for key in allow:
        if key[0] != "__malformed__" and key not in used:
            errors.append(f"{ALLOWLIST.name}: stale entry (no such raw output any more): {key[0]} | {key[1]}")

    if errors:
        print(f"Gate 91 (raw output): {len(errors)} finding(s)")
        for e in errors:
            print("  " + e)
        return 1
    if not args.quiet:
        print(f"Gate 91 (raw output): OK — {len(files)} templates, {len(allow)} allowlisted raw outputs")
    return 0


if __name__ == "__main__":
    sys.exit(main())
