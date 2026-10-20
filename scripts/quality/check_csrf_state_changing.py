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
work; stale entries are reported but never fail the gate.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

RE_METHOD = re.compile(r"^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(", re.M)
RE_ROUTE_METHODS = re.compile(r"#\[Route\((?:(?!\)\]).)*?methods:\s*\[([^\]]*)\]", re.S)
RE_WRITE_VERB = re.compile(r"['\"](POST|PUT|PATCH|DELETE)['\"]")
RE_PROTECTION = re.compile(
    r"IsCsrfTokenValid|isCsrfTokenValid\s*\(|isBulkCsrfTokenValid\s*\(|handleBulkDelete\s*\("
    r"|isTokenValid\s*\(\s*new\s+CsrfToken|createForm\s*\(|createFormBuilder\s*\(|handleRequest\s*\("
)
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
        if "#[Route" in pre and (not m or not RE_WRITE_VERB.search(m.group(1))):
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
        if RE_PROTECTION.search(pre) or RE_PROTECTION.search(body):
            continue
        # One level of same-class helper calls (e.g. approve() → transition()).
        helpers = {h for h in RE_THIS_CALL.findall(body) if h in bodies and h != name}
        if any(RE_PROTECTION.search(bodies[h]) for h in helpers):
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

    if stale and not args.quiet:
        print("check_csrf_state_changing: stale baseline entries (now protected, remove them):")
        for s in stale:
            print(f"  {s}")

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
