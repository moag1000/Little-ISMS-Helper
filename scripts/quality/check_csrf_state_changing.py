#!/usr/bin/env python3
"""
check_csrf_state_changing.py — state-changing controller actions need CSRF.

Every public controller action whose #[Route] allows POST, PUT, PATCH or
DELETE must show one of these protections (in its attributes, its body, or a
private helper of the same class it calls):

  - #[IsCsrfTokenValid(...)]              (method or class level)
  - $this->isCsrfTokenValid(...) / ->isTokenValid(new CsrfToken(...))
  - BulkActionTrait helpers: handleBulkDelete() / isBulkCsrfTokenValid()
  - a Symfony form: createForm() / handleRequest() (form CSRF is on by default)

An action that is genuinely not CSRF-relevant (pure computation that changes
nothing, an external IdP callback, a step gated by a secret such as a TOTP
code or the password) is marked in its docblock or attribute block with

    // @csrf-exempt: <reason>        (or  * @csrf-exempt: <reason>)

so the reason is reviewed together with the code.

It also rejects a literal route placeholder in a static token id, e.g.
#[IsCsrfTokenValid('delete_{id}')]: attribute ids are never interpolated, so
the check compares against the text "delete_{id}" and always fails. Such
entries are reported as `<path>::<method>#literal-id`; use
new Expression('"delete_" ~ args["entity"].getId()') instead.

GET (and HEAD) routes are checked too: a public action whose #[Route] allows only
safe verbs (GET/HEAD/OPTIONS, or no `methods:` at all) must not write. A method
body that calls `->flush()`, `->persist(` or `->remove(` is reported as
`<path>::<method>#get-writes`; links, prefetch and <img> tags would trigger it
without any CSRF check. Move the write to a POST route with
#[IsCsrfTokenValid], or mark a deliberate case (e.g. a write that only touches
a session or a log) with `// @get-write-ok: <reason>` in its docblock. Limits:
the scan is textual and covers the method body plus one level of same-class
private helpers; writes hidden in other services (`$service->doIt()` that
flushes internally) cannot be seen — those stay a review concern.

Baseline entries (`<relative-path>::<method>`) cover files owned by in-flight
work; stale entries fail too (T2), so the list only shrinks.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

RE_METHOD = re.compile(r"^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(", re.M)
RE_ROUTE_METHODS = re.compile(
    r"#\[Route\((?:(?!\)\]).)*?methods:\s*(?:\[([^\]]*)\]|(['\"][A-Za-z|]+['\"]))", re.S
)
RE_WRITE_VERB = re.compile(r"['\"](POST|PUT|PATCH|DELETE)['\"]")
# Protections that work by being called (attribute, bulk helpers).
RE_PROTECTION = re.compile(r"IsCsrfTokenValid|handleBulkDelete\s*\(")
# Token checks that return a bool: they only protect when the result decides something (T2:
# `$ok = $this->isCsrfTokenValid(...)` without using $ok passed before).
RE_TOKEN_CHECK = re.compile(r"(?:isCsrfTokenValid|isBulkCsrfTokenValid)\s*\(|isTokenValid\s*\(\s*new\s+CsrfToken")
RE_PHP_COMMENT = re.compile(r"/\*.*?\*/|(?<![:\"'])//[^\n]*", re.S)
RE_ASSIGNED = re.compile(r"^\s*(\$\w+)\s*=")
# A Symfony form protects only when the request is handled and its result checked (T2: `createForm()`
# alone, never submitted, passed before).
RE_FORM_HANDLED = re.compile(r"(?:handleRequest|->submit)\s*\(")
RE_FORM_CHECKED = re.compile(r"isSubmitted\s*\(|isValid\s*\(")
RE_EXEMPT = re.compile(r"@csrf-exempt:\s*\S")
RE_THIS_CALL = re.compile(r"\$this->(\w+)\s*\(")
RE_GET_WRITE = re.compile(r"(?:entityManager|\$em|->em|[mM]anager|registry)\s*->\s*(?:flush|persist|remove)\s*\(")
RE_GET_WRITE_OK = re.compile(r"@get-write-ok:\s*\S")
RE_LITERAL_PLACEHOLDER = re.compile(r"IsCsrfTokenValid\(\s*(?:id:\s*)?['\"][^'\"]*\{\w+\}")


def split_methods(text: str) -> list[tuple[str, str, int, int]]:
    """Return (name, visibility, start_offset, end_offset) for each method."""
    matches = list(RE_METHOD.finditer(text))
    out = []
    for i, m in enumerate(matches):
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        out.append((m.group(3), m.group(1), m.start(), end))
    return out


def token_check_decides(body: str) -> bool:
    """True when a CSRF token check's result decides what the action does.

    Fails only on the two shapes that check nothing: a bare call as its own statement,
    and `$ok = …check…` whose variable is never used in a condition afterwards.
    """
    body = RE_PHP_COMMENT.sub(" ", body)
    for m in RE_TOKEN_CHECK.finditer(body):
        stmt_start = max(body.rfind(";", 0, m.start()), body.rfind("{", 0, m.start()), body.rfind("}", 0, m.start()))
        prefix = body[stmt_start + 1:m.start()]
        assigned = RE_ASSIGNED.match(prefix)
        if assigned and re.fullmatch(r"\s*\$\w+\s*=\s*(?:\$this->|\$\w+->)?\s*", prefix):
            if re.search(r"(?:\bif\b|\breturn\b|&&|\|\||\?|=>|\bthrow\b)[^;{]*" + re.escape(assigned.group(1)) + r"\b",
                         body[m.end():]):
                return True
            continue
        if re.fullmatch(r"\s*(?:\$this->|\$\w+->)?\s*", prefix):
            continue  # bare call, result dropped
        return True
    return False


def protected(code: str) -> bool:
    if RE_PROTECTION.search(code) or token_check_decides(code):
        return True
    return bool(RE_FORM_HANDLED.search(code) and RE_FORM_CHECKED.search(code))


def preamble(text: str, start: int) -> str:
    """Docblock + attribute lines directly above a method."""
    lines = text[:start].split("\n")
    collected: list[str] = []
    for line in reversed(lines[:-1] if lines and lines[-1].strip() == "" else lines):
        s = line.strip()
        if s == "" or s.startswith(("#[", "*", "/**", "*/", "//")) or s.endswith(("]", ")]", ",")) or s.startswith(("'", '"', ")", "name:", "methods:", "requirements:")):
            if s.startswith(("}", "{")) or s.endswith(("{", ";")) and not s.startswith(("*", "//")):
                break
            collected.append(line)
            if len(collected) > 60:
                break
            continue
        break
    return "\n".join(reversed(collected))


def class_preamble(text: str) -> str:
    m = re.search(r"^(?:final\s+|abstract\s+)?class\s+\w+", text, re.M)
    return text[: m.start()] if m else ""


def scan(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="ignore")
    if "#[Route" not in text:
        return []
    class_level = "#[IsCsrfTokenValid" in class_preamble(text)
    methods = split_methods(text)
    bodies = {name: text[s:e] for name, _vis, s, e in methods}
    violations = []
    for name, vis, start, end in methods:
        if vis != "public":
            continue
        pre = preamble(text, start)
        if RE_LITERAL_PLACEHOLDER.search(pre):
            violations.append(f"{name}#literal-id")
        m = RE_ROUTE_METHODS.search(pre)
        verbs = (m.group(1) or m.group(2)) if m else ""
        if "#[Route" in pre and (not m or not RE_WRITE_VERB.search(verbs.upper().replace("|", "','"))):
            body = text[start:end]
            helpers = {h for h in RE_THIS_CALL.findall(body) if h in bodies and h != name}
            if not RE_GET_WRITE_OK.search(pre) and (
                RE_GET_WRITE.search(body) or any(RE_GET_WRITE.search(bodies[h]) for h in helpers)
            ):
                violations.append(f"{name}#get-writes")
            continue
        if not m:
            continue
        if class_level or RE_EXEMPT.search(pre):
            continue
        body = text[start:end]
        if RE_PROTECTION.search(pre) or protected(body):
            continue
        # One level of same-class helper calls (e.g. approve() → transition()).
        helpers = {h for h in RE_THIS_CALL.findall(body) if h in bodies and h != name}
        if any(protected(bodies[h]) for h in helpers):
            continue
        violations.append(name)
    return violations


def _rel(p: Path) -> str:
    try:
        return str(p.relative_to(ROOT))
    except ValueError:
        return p.name


def load_baseline(path: Path | None) -> set[str]:
    if path is None or not path.exists():
        return set()
    return {
        s.strip()
        for s in path.read_text(encoding="utf-8").splitlines()
        if s.strip() and not s.strip().startswith("#")
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--baseline", type=Path, default=None)
    ap.add_argument("--write-baseline", type=Path, default=None)
    ap.add_argument("--quiet", action="store_true")
    ap.add_argument("--paths", nargs="+", type=Path, default=None, help="files/dirs to scan (default: src/Controller)")
    args = ap.parse_args()

    targets: list[Path] = []
    for p in args.paths or [CONTROLLER_DIR]:
        targets.extend(sorted(p.rglob("*.php")) if p.is_dir() else [p])

    found = [f"{_rel(f)}::{name}" for f in targets for name in scan(f)]

    if args.write_baseline is not None:
        args.write_baseline.parent.mkdir(parents=True, exist_ok=True)
        args.write_baseline.write_text(
            "# check_csrf_state_changing.py baseline\n# Format: <relative-path>::<method>\n"
            + "".join(f"{v}\n" for v in found),
            encoding="utf-8",
        )
        print(f"check_csrf_state_changing: wrote {len(found)} entries to {args.write_baseline}")
        return 0

    baseline = load_baseline(args.baseline)
    new = [v for v in found if v not in baseline]
    stale = sorted(baseline - set(found))

    if stale:
        print("check_csrf_state_changing: stale baseline entries (now protected, remove them):")
        for s in stale:
            print(f"  {s}")

    if not new and stale:
        return 1
    if not new:
        print(f"check_csrf_state_changing: OK — {len(found)} unprotected action(s), all baselined.")
        return 0

    print("check_csrf_state_changing: VIOLATIONS — state-changing action without CSRF check\n")
    for v in new:
        print(f"FAIL {v}")
    print(
        f"\ncheck_csrf_state_changing: {len(new)} new violation(s)."
        "\nFix: add #[IsCsrfTokenValid('<id>')] (tokenSource: IsCsrfTokenValid::SOURCE_HEADER for fetch()"
        "\nwith an X-CSRF-Token header) and send the token from the template; or mark a genuinely"
        "\nnon-state-changing action with `// @csrf-exempt: <reason>`."
        "\n#literal-id: a '{placeholder}' inside a static token id is never interpolated;"
        "\nuse new Expression('\"prefix_\" ~ args[\"entity\"].getId()')."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
