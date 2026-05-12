#!/usr/bin/env python3
"""
Gate 10 — Wildcard route requirements vs literal-sibling collision.

Symfony matches routes in declared (source-code) order. A route like
`/{id}` declared in the same controller as a literal-prefix route like
`/new` or `/approvals` will match the literal path if the wildcard
appears first OR if the wildcard has no requirements that exclude the
literal segment.

Real-world incident: SsoProviderController declared `/{id}` (show)
before `/new` and `/approvals`. Requests to `/admin/sso/new` matched
`show()` with id="new", triggering NotFoundHttpException from
EntityValueResolver — "App\\Entity\\IdentityProvider object not found".

Detection logic:
- For each PHP file in src/Controller/, parse #[Route] attributes.
- Group by class-level prefix.
- For each group, find:
  - "wildcard" routes: `/{name}` or `/{name}/...` where {name} has no
    requirement restricting it to numeric or other restricted set.
  - "literal" sibling routes: `/word` or `/word/...` under same prefix.
- If a wildcard route lacks `requirements: ['name' => '\\d+']` (or
  similar restrictive regex) AND any literal-sibling exists, that is
  a violation — the literal sibling will silently match the wildcard.

Allow patterns:
- requirements with explicit pattern containing `\\d` (numeric)
- requirements with `[a-z-]+` slug pattern that excludes the literal
- routes whose wildcard placeholder name has class-level `requirements`

Per memory: feedback_locale_in_links (related routing trap).

Exit 0 = clean, exit 1 = violations.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path
from collections import defaultdict


# Match #[Route('path', ...)] — captures path + the full args tail
# (non-greedy `.*?` with DOTALL so it stops at first `)]` after this Route).
ROUTE_PATTERN = re.compile(
    r"#\[Route\(\s*['\"]([^'\"]*)['\"](.*?)\)\]",
    re.DOTALL,
)

# Extract `requirements: [...]` from a route's tail (handles nested ['GET']
# in methods array, etc.). Looks for `requirements:` then balanced `[...]`.
REQUIREMENTS_PATTERN = re.compile(
    r"requirements:\s*(\[[^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*\])",
    re.DOTALL,
)


def extract_requirements(tail: str) -> str | None:
    """Return the requirements array source (e.g. `['id' => '\\d+']`) or None."""
    m = REQUIREMENTS_PATTERN.search(tail)
    return m.group(1) if m else None


def extract_placeholder_name(path: str) -> str | None:
    """Return the first placeholder name in a route path, or None."""
    m = re.search(r"\{([a-zA-Z_][a-zA-Z0-9_]*)\}", path)
    return m.group(1) if m else None


def has_restrictive_requirement(requirements_block: str | None, placeholder: str) -> bool:
    """
    Return True if requirements restrict the placeholder enough that
    arbitrary literal siblings cannot accidentally match. Any non-empty
    regex other than `.*` / `.+` / `[^/]+` is considered restrictive.
    Symfony's default already excludes `/`, so unrestricted means the
    requirement is missing or matches anything-without-slash.
    """
    if not requirements_block:
        return False
    pattern = re.compile(
        rf"['\"]" + re.escape(placeholder) + r"['\"]\s*=>\s*['\"]([^'\"]+)['\"]"
    )
    m = pattern.search(requirements_block)
    if not m:
        return False
    req = m.group(1).strip()
    # Permissive patterns that do NOT exclude literal segments
    permissive = {".*", ".+", "[^/]+", "[^/]*", "\\w+", "\\w*", ".*?"}
    return req not in permissive


def is_wildcard_route(path: str) -> bool:
    """A path starting with /{placeholder} (no literal first-segment)."""
    # Strip leading slash, check first segment
    stripped = path.lstrip("/")
    if not stripped:
        return False
    first_segment = stripped.split("/", 1)[0]
    return first_segment.startswith("{") and first_segment.endswith("}")


def is_literal_route(path: str) -> bool:
    """A path starting with a literal segment (no placeholder in first segment)."""
    stripped = path.lstrip("/")
    if not stripped:
        return True  # empty path = index, treat as literal
    first_segment = stripped.split("/", 1)[0]
    return not (first_segment.startswith("{") and first_segment.endswith("}"))


def find_violations(file_path: Path) -> list[tuple[int, str, str]]:
    """Return violations: (line_number, path, reason)."""
    violations: list[tuple[int, str, str]] = []
    try:
        content = file_path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return violations

    # Collect all method-level routes with their line numbers
    routes: list[tuple[int, str, str | None]] = []
    for match in ROUTE_PATTERN.finditer(content):
        path = match.group(1)
        tail = match.group(2)
        requirements = extract_requirements(tail)
        line_num = content[: match.start()].count("\n") + 1
        routes.append((line_num, path, requirements))

    # Skip class-level routes (the first one if it's at top of file / class)
    # We need to distinguish: drop routes that are on a `final class` line or
    # the class declaration itself.
    method_routes: list[tuple[int, str, str | None]] = []
    for line_num, path, req in routes:
        # Look at surrounding context — class-level routes are immediately
        # followed by `final class` or `class` declaration.
        lines = content.splitlines()
        idx = line_num - 1
        next_nonblank = ""
        for i in range(idx + 1, min(idx + 6, len(lines))):
            stripped = lines[i].strip()
            if stripped and not stripped.startswith("#["):
                next_nonblank = stripped
                break
        if next_nonblank.startswith(("final class ", "class ", "abstract class ")):
            continue
        method_routes.append((line_num, path, req))

    # Group routes by their "directory" (everything before last segment placeholder)
    # For simplicity, just check: if any literal route exists, all wildcard
    # routes in the same file must have restrictive requirements.
    has_literal_sibling = any(is_literal_route(p) for _, p, _ in method_routes)
    if not has_literal_sibling:
        return violations

    for line_num, path, requirements in method_routes:
        if not is_wildcard_route(path):
            continue
        placeholder = extract_placeholder_name(path)
        if placeholder is None:
            continue
        if has_restrictive_requirement(requirements, placeholder):
            continue
        violations.append(
            (
                line_num,
                path,
                f"wildcard /{{{placeholder}}} without numeric requirement, "
                f"and file has literal-prefix sibling routes — "
                f"literal paths will silently match the wildcard",
            )
        )

    return violations


def main() -> int:
    project_root = Path(__file__).resolve().parents[2]
    controllers_dir = project_root / "src" / "Controller"

    if not controllers_dir.is_dir():
        print(f"ERROR: src/Controller not found at {controllers_dir}", file=sys.stderr)
        return 2

    total_files = 0
    total_violations = 0
    failed_files = 0

    for php_file in sorted(controllers_dir.rglob("*.php")):
        total_files += 1
        violations = find_violations(php_file)
        if violations:
            failed_files += 1
            rel_path = php_file.relative_to(project_root)
            for line_num, path, reason in violations:
                print(f"{rel_path}:{line_num}: {path} — {reason}")
                total_violations += 1

    if total_violations == 0:
        print(
            f"OK  Gate 10 — {total_files} controllers checked, "
            f"no wildcard-vs-literal route collisions."
        )
        return 0

    print(
        f"\nGate 10 FAIL: {total_violations} wildcard route(s) without "
        f"restrictive requirements in {failed_files} file(s)."
    )
    print(
        "Fix: add requirements to the wildcard route — e.g. "
        "#[Route('/{id}', requirements: ['id' => '\\d+'])]. "
        "This stops literal sibling segments (like '/new' or '/approvals') "
        "from matching the wildcard and triggering an entity-not-found 404."
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
