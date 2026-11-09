"""
php_source.py — small textual helpers for the Python quality gates (not a gate itself).

Gates 82, 84, 87 and 90 read PHP as text. A line-by-line regex is blind to a call that
breaks across lines, to a comment that names the thing it should not count, and to an
alias (`use X as Y`). These helpers work on the whole file text:

  * strip_comments()  - blanks comments (offsets and line numbers stay), string-aware;
  * use_aliases()     - `use A\\B as C;` and group use `use A\\{B, C as D};` -> {alias: fqcn};
  * matching_end()    - end of a balanced (...) / {...} / [...] group, string-aware;
  * split_args()      - top-level comma split of an argument list;
  * class_methods()   - every method (any visibility, any indentation) with params, body, attributes;
  * join_literals()   - folds `'a' . 'b'` into `'ab'`.
"""
from __future__ import annotations

import re
from typing import NamedTuple

_OPEN = {"(": ")", "{": "}", "[": "]"}


_TOKEN = re.compile(r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|/\*.*?\*/|//[^\n]*|\#(?!\[)[^\n]*""", re.S)


def strip_comments(text: str) -> str:
    """Blank `//`, `#` and block comments with spaces (newlines stay); strings and `#[` attributes stay."""
    def blank(m: re.Match[str]) -> str:
        token = m.group(0)
        return token if token[0] in "'\"" else re.sub(r"[^\n]", " ", token)
    return _TOKEN.sub(blank, text)


def matching_end(code: str, open_pos: int) -> int:
    """Index just after the group that opens at open_pos ((, {, [); len(code) when it never closes."""
    opener = code[open_pos]
    closer = _OPEN[opener]
    depth, i, n = 0, open_pos, len(code)
    while i < n:
        c = code[i]
        if c in "'\"":
            i += 1
            while i < n and code[i] != c:
                i += 2 if code[i] == "\\" else 1
        elif c == opener:
            depth += 1
        elif c == closer:
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    return n


def split_args(arglist: str) -> list[str]:
    """Top-level comma split of the text inside a call's parentheses."""
    parts: list[str] = []
    depth, start, i, n = 0, 0, 0, len(arglist)
    while i < n:
        c = arglist[i]
        if c in "'\"":
            i += 1
            while i < n and arglist[i] != c:
                i += 2 if arglist[i] == "\\" else 1
        elif c in "([{":
            depth += 1
        elif c in ")]}":
            depth -= 1
        elif c == "," and depth == 0:
            parts.append(arglist[start:i])
            start = i + 1
        i += 1
    tail = arglist[start:]
    if tail.strip():
        parts.append(tail)
    return [p.strip() for p in parts]


_USE = re.compile(r"^\s*use\s+(?:function\s+|const\s+)?([^;]+);", re.M)


def use_aliases(code: str) -> dict[str, str]:
    """alias -> fully qualified name for every `use` (plain, `as`, group)."""
    found: dict[str, str] = {}
    for m in _USE.finditer(code):
        spec = m.group(1).strip()
        if "{" in spec:
            prefix, _, rest = spec.partition("{")
            items = [x.strip() for x in rest.rstrip("} ").split(",") if x.strip()]
            bases = [(prefix.strip().rstrip("\\"), item) for item in items]
        else:
            bases = [("", item.strip()) for item in spec.split(",")]
        for prefix, item in bases:
            pieces = re.split(r"\s+as\s+", item, flags=re.I)
            name = pieces[0].strip().lstrip("\\")
            fqcn = f"{prefix}\\{name}" if prefix else name
            alias = pieces[1].strip() if len(pieces) > 1 else name.rsplit("\\", 1)[-1]
            found[alias] = fqcn.lstrip("\\")
    return found


def aliases_of(code: str, fqcn_suffix: str) -> set[str]:
    """Every local name (alias or short name) a class whose FQCN ends with fqcn_suffix goes by."""
    names = {fqcn_suffix.rsplit("\\", 1)[-1]}
    for alias, full in use_aliases(code).items():
        if full == fqcn_suffix or full.endswith("\\" + fqcn_suffix):
            names.add(alias)
    return names


_CONCAT = re.compile(r"""(?P<q>['"])(?P<a>[^'"\\\n]*)(?P=q)\s*\.\s*(?P<q2>['"])(?P<b>[^'"\\\n]*)(?P=q2)""")


def join_literals(code: str) -> str:
    """Fold `'a' . 'b'` into `'ab'` until nothing joins (offsets change; use for matching only)."""
    previous = None
    while previous != code:
        previous = code
        code = _CONCAT.sub(lambda m: f"'{m.group('a')}{m.group('b')}'", code)
    return code


class Method(NamedTuple):
    name: str
    params: str
    body: str
    attributes: str       # text between the previous member and this method's `function` keyword
    start: int            # offset of `function`
    body_start: int
    end: int

    def param_names(self) -> list[str]:
        names = []
        for p in split_args(self.params):
            m = re.search(r"\$(\w+)", p)
            names.append(m.group(1) if m else "")
        return names


_CLASS_OPEN = re.compile(r"\b(?:(?:final|abstract|readonly)\s+)*(?:class|trait|enum|interface)\s+\w+[^{;]*\{")
_FUNCTION = re.compile(r"\bfunction\s+&?\s*(\w+)\s*\(")


def class_methods(code: str) -> tuple[str, list[Method]]:
    """(text before the class body, methods) of a comment-free PHP file."""
    head = _CLASS_OPEN.search(code)
    class_head = code[:head.end()] if head else ""
    previous_end = head.end() if head else 0
    methods: list[Method] = []
    pos = previous_end
    while True:
        m = _FUNCTION.search(code, pos)
        if not m:
            break
        paren = m.end() - 1
        params_end = matching_end(code, paren)
        i = params_end
        while i < len(code) and code[i] not in "{;":  # return type
            i += 1
        if i < len(code) and code[i] == "{":
            body_end = matching_end(code, i)
            body = code[i:body_end]
        else:
            body_end, body = i + 1, ""
        methods.append(Method(m.group(1), code[paren + 1:params_end - 1], body,
                              code[previous_end:m.start()], m.start(), i, body_end))
        previous_end = body_end
        pos = body_end
    return class_head, methods
