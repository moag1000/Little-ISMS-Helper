#!/usr/bin/env python3
"""
Gate 8 — No /{_locale}/ prefix in Controller route attributes.

config/routes.yaml wraps all src/Controller/ in prefix /{_locale} globally
(with default _locale: de). Controllers that ALSO declare /{_locale}/ in
their #[Route(...)] attribute produce "/{_locale}/{_locale}/..." patterns
which Symfony refuses to compile:

  Route pattern "/{_locale}/{_locale}/..." cannot reference variable
  name "_locale" more than once.

Allow-list: Controllers in routes.yaml that are NOT wrapped by the global
prefix (SecurityController, HomeController, QuickFixController, Admin/TagController).

Per memory: feedback_locale_in_links

Exit 0 = clean, exit 1 = violations.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path


ALLOWED_UNPREFIXED_CONTROLLERS = {
    "src/Controller/SecurityController.php",
    "src/Controller/HomeController.php",
    "src/Controller/QuickFixController.php",
    "src/Controller/Admin/TagController.php",
}


def find_double_locale_routes(file_path: Path, project_root: Path) -> list[tuple[int, str]]:
    rel_path = str(file_path.relative_to(project_root))
    if rel_path in ALLOWED_UNPREFIXED_CONTROLLERS:
        return []

    pattern = re.compile(r"#\[Route\(\s*['\"]/?\{_locale\}/")
    violations: list[tuple[int, str]] = []

    try:
        content = file_path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError):
        return violations

    for line_num, line in enumerate(content.splitlines(), 1):
        if pattern.search(line):
            violations.append((line_num, line.strip()[:140]))
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
        violations = find_double_locale_routes(php_file, project_root)
        if violations:
            failed_files += 1
            rel_path = php_file.relative_to(project_root)
            for line_num, line_content in violations:
                print(f"{rel_path}:{line_num}: double /{{_locale}}/ prefix — {line_content}")
                total_violations += 1

    if total_violations == 0:
        print(f"OK  Gate 8 — {total_files} controllers checked, no double /{{_locale}}/ prefix.")
        return 0

    print(
        f"\nGate 8 FAIL: {total_violations} #[Route] attribute(s) with redundant "
        f"/{{_locale}}/ in {failed_files} file(s)."
    )
    print(
        "Fix: drop /{_locale}/ from the controller route — config/routes.yaml "
        "already wraps src/Controller/ in prefix /{_locale}. Example:\n"
        "  WRONG: #[Route('/{_locale}/foo', requirements: ['_locale' => 'de|en'])]\n"
        "  RIGHT: #[Route('/foo')]"
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
