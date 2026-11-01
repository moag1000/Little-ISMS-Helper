#!/usr/bin/env python3
"""
check_settings_readers.py — Gate 70: every admin setting must have an effect.

/admin/settings stores each field of ApplicationSettingsType / SecuritySettingsType
as SystemSettings(<category>, <field>). A field that nothing outside the settings
screen reads is a switch that switches nothing — for a security tool the worst
kind of UI (an admin ticks "Require MFA" and nothing happens).

A field counts as read when, outside SystemSettingsController and the FormType,
src/ contains getSetting('<category>', '<field>' …), the dotted key
'<category>.<field>' (TenantSettingResolver), or a getSetting('<category>', $var)
call in a class whose source names the field as a string literal.

No baseline: the settings screens are clean since the 2026-11 de-clutter.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

FORMS = {
    "application": ROOT / "src/Form/ApplicationSettingsType.php",
    "security": ROOT / "src/Form/SecuritySettingsType.php",
}
EXCLUDE = {ROOT / "src/Controller/SystemSettingsController.php", *FORMS.values()}

RE_ADD = re.compile(r"->add\(\s*'([a-z0-9_]+)'")


def main() -> int:
    sources = {
        p: p.read_text(encoding="utf-8", errors="ignore")
        for p in (ROOT / "src").rglob("*.php")
        if p not in EXCLUDE
    }
    missing: list[str] = []
    for category, form in FORMS.items():
        if not form.exists():
            continue
        for field in RE_ADD.findall(form.read_text(encoding="utf-8")):
            direct = re.compile(rf"getSetting\(\s*'{category}'\s*,\s*'{field}'")
            dotted = f"'{category}.{field}'"
            indirect = re.compile(rf"getSetting\(\s*'{category}'\s*,\s*\$")
            if any(
                direct.search(src)
                or dotted in src
                or (indirect.search(src) and f"'{field}'" in src)
                for src in sources.values()
            ):
                continue
            missing.append(f"{category}.{field} ({form.relative_to(ROOT)})")

    if missing:
        print("Admin settings without any reader (wire them up or remove the field):")
        for entry in missing:
            print(f"  {entry}")
        return 1
    print("OK — every admin settings field has a reader.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
