#!/usr/bin/env python3
"""
check_stimulus_wiring.py — Gate 79: every Stimulus reference resolves, every controller is used.

Stimulus fails silently: `data-action="click->foo#bar"` on a page without a
`foo` controller, or with a `foo` that has no `bar()`, simply does nothing; a
`data-controller="foo"` without `foo_controller.js` is ignored. The 2026-11
sweep found an import upload zone, a preset picker, delete confirmations and a
menu link that looked live but were wired to controllers or methods that never
existed, next to controllers no markup attached any more.

Checks over templates/ and src/ (Twig markup, Twig hashes, PHP form `attr`
arrays):

  1. every identifier in `data-controller`, in an action (`foo#bar`) and in a
     target attribute (`data-foo-target`) is a controller in assets/controllers/
     (or registered under that name in assets/stimulus_bootstrap.js);
  2. every action method exists in that controller;
  3. every target name is declared in the controller's `static targets`, every
     `data-foo-bar-value` in its `static values`;
  5. no controller assigns a Stimulus base property (`this.scope = …` throws
     in strict mode and kills connect() without a visible error);
  4. every controller in assets/controllers/ is attached somewhere — as a
     `data-controller` token, a quoted identifier passed to a macro
     (`stimulusController: 'modal-wizard'`), or from another controller.

Parts of a value built with Twig (`{{ … }}`, `{% … %}`) are skipped. Names
that are only built at runtime go into the baseline with a reason
(`kind  location  # reason`).

Usage: check_stimulus_wiring.py [--baseline FILE] [--quiet]
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLERS = ROOT / "assets" / "controllers"
BOOTSTRAP = ROOT / "assets" / "stimulus_bootstrap.js"
SCAN_DIRS = (ROOT / "templates", ROOT / "src")

# Controllers that come from packages (controllers.json), not from assets/controllers/.
PACKAGE_CONTROLLERS = {"symfony--ux-chartjs--chart", "symfony--ux-turbo--turbo-core", "live", "csrf-protection"}
# `data-bs-target` & co. belong to Bootstrap, not to Stimulus.
NOT_STIMULUS_PREFIXES = ("bs", "turbo")

ATTR_VALUE = r"""\s*(?:=|=>|:)\s*(?P<q>["'])(?P<v>.*?)(?P=q)"""
CONTROLLER_ATTR = re.compile(r"""data-controller["']?""" + ATTR_VALUE, re.S)
ACTION_ATTR = re.compile(r"""data-action["']?""" + ATTR_VALUE, re.S)
TARGET_ATTR = re.compile(r"""data-(?P<id>[a-z][a-z0-9-]*?)-target["']?""" + ATTR_VALUE, re.S)
TWIG_EXPR = re.compile(r"\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}", re.S)
ACTION_TOKEN = re.compile(r"^(?:[^\s>]+->)?(?P<id>[a-z][a-z0-9-]*(?:--[a-z0-9-]+)*)#(?P<m>[A-Za-z_$][\w$]*)(?:@\w+)?(?::[\w:]+)?$")
IDENT = re.compile(r"^[a-z][a-z0-9]*(?:-{1,2}[a-z0-9]+)*$")


def load_controllers() -> dict[str, Path]:
    found = {p.name.removesuffix("_controller.js").replace("_", "-"): p for p in CONTROLLERS.glob("*_controller.js")}
    if BOOTSTRAP.exists():
        text = BOOTSTRAP.read_text(encoding="utf-8")
        imports = dict(re.findall(r"import (\w+) from '\./controllers/(\w+)_controller\.js'", text))
        for ident, cls in re.findall(r"app\.register\('([\w-]+)',\s*(\w+)\)", text):
            module = imports.get(cls)
            if module and ident not in found:
                found[ident] = CONTROLLERS / f"{module}_controller.js"
    return found


def class_members(src: str) -> set[str]:
    members = set(re.findall(r"^\s{4}(?:static\s+)?(?:async\s+)?(?:get\s+|set\s+)?#?([A-Za-z_$][\w$]*)\s*\(", src, re.M))
    members |= set(re.findall(r"^\s{4}#?([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\([^)]*\)|[\w$]+)\s*=>", src, re.M))
    return members


def static_targets(src: str) -> set[str]:
    match = re.search(r"static\s+targets\s*=\s*\[([^\]]*)\]", src, re.S)
    return set(re.findall(r"""["']([\w-]+)["']""", match.group(1))) if match else set()


def static_values(src: str) -> set[str] | None:
    """Value names (kebab-case) of `static values = { … }`; None when not parseable."""
    match = re.search(r"static\s+values\s*=\s*\{", src)
    if not match:
        return set()
    depth, i = 1, match.end()
    while i < len(src) and depth:
        depth += {"{": 1, "}": -1}.get(src[i], 0)
        i += 1
    body = src[match.end():i - 1]
    names, depth = [], 0
    for token in re.finditer(r"[{}]|([A-Za-z_$][\w$]*)\s*:", body):
        if token.group(0) in "{}":
            depth += 1 if token.group(0) == "{" else -1
        elif depth == 0:
            names.append(token.group(1))
    return {re.sub(r"(?<!^)([A-Z])", r"-\1", n).lower() for n in names}


def strip_twig(value: str) -> str:
    return TWIG_EXPR.sub(" ", value)


def line_of(text: str, pos: int) -> int:
    return text.count("\n", 0, pos) + 1


def scan_files() -> list[Path]:
    files = []
    for base in SCAN_DIRS:
        files += [p for p in base.rglob("*") if p.suffix in (".twig", ".php") and p.is_file()]
    # Markup built in JS template strings (toasts, palette, notifications) is wired just the same.
    files += [p for p in (ROOT / "assets").rglob("*.js") if p.is_file() and "vendor" not in p.parts]
    return sorted(files)


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    out = set()
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.lstrip().startswith("#"):
            continue
        line = line.split(" # ", 1)[0].strip()  # "foo#bar" is an action, " # " starts the reason
        if line:
            out.add(" ".join(line.split()))
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    parser.add_argument("--baseline", type=Path)
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    controllers = load_controllers()
    sources = {ident: path.read_text(encoding="utf-8", errors="ignore") for ident, path in controllers.items()}
    members = {ident: class_members(src) for ident, src in sources.items()}
    targets = {ident: static_targets(src) for ident, src in sources.items()}
    values = {ident: static_values(src) for ident, src in sources.items()}
    by_length = sorted(controllers, key=len, reverse=True)
    value_attr = re.compile(r"""data-([a-z][a-z0-9-]*)-value["']?\s*(?:=|=>|:)""")

    known = set(controllers) | PACKAGE_CONTROLLERS
    problems: list[str] = []
    attached: set[str] = set()
    corpus_parts: list[str] = []

    for path in scan_files():
        text = path.read_text(encoding="utf-8", errors="ignore")
        if path.suffix == ".twig":
            # Usage notes in {# … #} are documentation, not wiring (keep line numbers).
            text = re.sub(r"\{#.*?#\}", lambda c: "\n" * c.group(0).count("\n"), text, flags=re.S)
        elif path.suffix == ".js":
            # Usage notes in comments are documentation, not wiring (keep line numbers).
            text = re.sub(r"/\*.*?\*/", lambda c: "\n" * c.group(0).count("\n"), text, flags=re.S)
            text = re.sub(r"^\s*//.*$", "", text, flags=re.M)
        corpus_parts.append(text)
        rel = path.relative_to(ROOT).as_posix()

        # Tokens that are not even shaped like an identifier (`ui_actions`, `uiActions#x`) are skipped by the
        # checks below - and Stimulus ignores them just the same. Tokens touching Twig are left alone.
        for kind, pattern in (("data-controller", CONTROLLER_ATTR), ("data-action", ACTION_ATTR)):
            for m in pattern.finditer(text):
                raw = TWIG_EXPR.sub("\x00", m.group("v"))
                for token in raw.split():
                    if "\x00" in token or token in "?:":
                        continue
                    ok = IDENT.match(token) if kind == "data-controller" else ACTION_TOKEN.match(token)
                    if not ok:
                        problems.append(f"bad-identifier {rel}:{line_of(text, m.start())} {kind}={token}")

        for m in CONTROLLER_ATTR.finditer(text):
            # Identifiers inside Twig string literals ({{ cond ? ' foo bar' }})
            # count as attached; only the static part is checked for existence.
            for literal in re.findall(r"""'([^']*)'""", " ".join(TWIG_EXPR.findall(m.group("v")))):
                attached.update(t for t in literal.split() if IDENT.match(t))
            for ident in strip_twig(m.group("v")).split():
                if not IDENT.match(ident):
                    continue
                attached.add(ident)
                if ident not in known:
                    problems.append(f"unknown-controller {rel}:{line_of(text, m.start())} {ident}")

        for m in ACTION_ATTR.finditer(text):
            for token in strip_twig(m.group("v")).split():
                a = ACTION_TOKEN.match(token)
                if not a:
                    continue
                ident, method = a.group("id"), a.group("m")
                if ident in PACKAGE_CONTROLLERS:
                    continue
                where = f"{rel}:{line_of(text, m.start())}"
                if ident not in controllers:
                    problems.append(f"unknown-controller {where} {ident}#{method}")
                elif method not in members[ident]:
                    problems.append(f"unknown-method {where} {ident}#{method}")

        for m in TARGET_ATTR.finditer(text):
            ident = m.group("id")
            if ident.startswith(NOT_STIMULUS_PREFIXES) or ident in PACKAGE_CONTROLLERS:
                continue
            where = f"{rel}:{line_of(text, m.start())}"
            if ident not in controllers:
                problems.append(f"unknown-controller {where} {ident} (target)")
                continue
            for name in strip_twig(m.group("v")).split():
                if re.match(r"^[\w-]+$", name) and name not in targets[ident]:
                    problems.append(f"unknown-target {where} {ident}.{name}")

        for m in value_attr.finditer(text):
            name = m.group(1)
            ident = next((c for c in by_length if name.startswith(c + "-")), None)
            if ident is None or name.startswith(NOT_STIMULUS_PREFIXES):
                continue
            value = name[len(ident) + 1:]
            if values[ident] is not None and value not in values[ident]:
                problems.append(f"unknown-value {rel}:{line_of(text, m.start())} {ident}.{value}")

    # 5. Assigning a Stimulus base property (`this.scope = …`) throws in the
    # module's strict mode, so connect() dies silently.
    names = "scope|element|application|data|identifier|targets|outlets|context|classes"
    reserved = re.compile(
        rf"this(?:\.|\s*\[\s*['\"])(?P<n>{names})(?:['\"]\s*\])?\s*=(?!=)"
        rf"|Object\.assign\(\s*this\s*,\s*\{{[^}}]*?\b(?P<m>{names})\s*[:,}}]"
    )
    for ident, src in sources.items():
        for m in reserved.finditer(src):
            problems.append(f"reserved-property {controllers[ident].relative_to(ROOT).as_posix()}:{line_of(src, m.start())} this.{m.group('n') or m.group('m')}")

    # 4. Dead controllers: not attached by data-controller, not passed as a
    # quoted identifier (macro option / JS), not referenced by another controller.
    corpus = "\n".join(corpus_parts)
    js_corpus = {ident: src for ident, src in sources.items()}
    for ident, path in sorted(controllers.items()):
        if path.name.removesuffix("_controller.js").replace("_", "-") != ident:
            continue  # alias registration, the file is checked under its own name
        if ident in attached:
            continue
        # Passed to a macro / helper as the controller name:
        #   stimulusController ?? 'modal-wizard', controller: 'x', controller|default('x'),
        #   'controller' => 'x', _ctrls|merge(['fa-bulk-select'])
        quoted = re.compile(
            r"""(?:controller\w*["']?\s*(?:\?\?|:|=>|=|,|\|default\()\s*|merge\(\[)["']"""
            + re.escape(ident) + r"""["']""",
            re.I,
        )
        if quoted.search(corpus):
            continue
        # Attached from another controller's JS: getControllerForElementAndIdentifier(el, 'x'),
        # generated markup (data-controller="… x …", x#method, data-x-…).
        in_js = re.compile(
            r"""Identifier\([^)]*["']""" + re.escape(ident) + r"""["']|(?<![\w-])""" + re.escape(ident)
            + r"""#\w|data-""" + re.escape(ident) + r"""-|data-controller=\\?["'][^"']*(?<![\w-])"""
            + re.escape(ident) + r"""(?![\w-])"""
        )
        if any(in_js.search(src) for other, src in js_corpus.items() if other != ident):
            continue
        aliases = [a for a, p in controllers.items() if p == path and a != ident and a in attached]
        if aliases:
            continue
        problems.append(f"dead-controller {path.relative_to(ROOT).as_posix()}")

    baseline = load_baseline(args.baseline)
    normalized = [" ".join(p.split()) for p in problems]
    new = sorted({p for p in normalized if p not in baseline and _strip_line(p) not in baseline})
    stale = sorted(b for b in baseline if b not in normalized and b not in {_strip_line(p) for p in normalized})

    if stale and not args.quiet:
        print("Stale baseline entries (fixed — remove them):")
        for entry in stale:
            print(f"  {entry}")
    if new:
        print("Stimulus wiring that does nothing (fix the reference or delete the dead code):")
        for entry in new:
            print(f"  {entry}")
        return 1
    if not args.quiet:
        print(f"OK — {len(controllers)} controllers, all references resolve.")
    return 0


def _strip_line(problem: str) -> str:
    """Baseline entries may omit the line number (`path` instead of `path:12`)."""
    return re.sub(r"(\S+):\d+", r"\1", problem)


if __name__ == "__main__":
    sys.exit(main())
