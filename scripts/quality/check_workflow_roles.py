#!/usr/bin/env python3
"""
check_workflow_roles.py — Gate 82: workflow approvers and recipients are real roles.

A role string Symfony does not know is never granted and never matches a user:
the step waits for nobody, nobody is notified, and only admins can approve. The
norm-coverage check (2026-11) found ten regulatory workflow steps on such roles
(ROLE_RISK_OWNER, ROLE_CEO, ROLE_SECURITY_MANAGER, …) and ten approval steps on
ROLE_USER, which every signed-in user holds, so anybody could approve a CAPA
root cause or a document review.

Checked:
  * config/workflows/**/*.yaml — transition `roles`, step `approver_role`,
    `escalation_role`;
  * the PHP seeders that write workflow steps (`'approverRole' =>`, `'role' =>`);
  * recipient look-ups in src/ (`findByRoleInTenant('ROLE_…'`,
    `findUserByRole('ROLE_…'`, `findUsersByRole('ROLE_…'`).

Rules:
  1. every role exists in config/packages/security.yaml (role_hierarchy keys
     or values) or in the pending list below;
  2. no approval step and no approve/reject/verify transition is open to ROLE_USER.

A relation on the record ("the risk owner", "the document owner") is not a
role; it needs the responsibility resolver, not a made-up ROLE_ string.

    python3 scripts/quality/check_workflow_roles.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
SECURITY = ROOT / "config" / "packages" / "security.yaml"
WORKFLOWS = ROOT / "config" / "workflows"
SEEDERS = [
    ROOT / "src" / "Command" / "GenerateRegulatoryWorkflowsCommand.php",
    ROOT / "src" / "Command" / "SeedIncidentWorkflowsCommand.php",
    ROOT / "src" / "Command" / "SeedPolicyApprovalWorkflowCommand.php",
]

# Roles in use whose definition is on its way in another change. Each line
# names the change; delete the line once the role is in security.yaml.
PENDING: set[str] = set()

LOOKUP = re.compile(r"""\b(?:findByRoleInTenant|findUserByRole|findUsersByRole)\(\s*'(ROLE_[A-Z_]+)'""")
SEEDER_ROLE = re.compile(r"""'(?:approverRole|role)'\s*=>\s*'(ROLE_[A-Z_]+)'""")
SEEDER_TYPE = re.compile(r"""'stepType'\s*=>\s*'([a-z_]+)'""")


def known_roles() -> set[str]:
    data = yaml.safe_load(SECURITY.read_text(encoding="utf-8"))
    hierarchy = data["security"]["role_hierarchy"]
    roles = set(hierarchy)
    for value in hierarchy.values():
        roles.update(value if isinstance(value, list) else [value])
    return roles


def rel(path: Path) -> str:
    return str(path.relative_to(ROOT))


def yaml_line(lines: list[str], needle: str, start: int = 0) -> int:
    for i in range(start, len(lines)):
        if needle in lines[i]:
            return i + 1
    return 0


def check_yaml(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in sorted(WORKFLOWS.rglob("*.yaml")):
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()
        data = yaml.safe_load(text) or {}
        workflows = (data.get("framework") or {}).get("workflows") or {}
        for wf_name, wf in workflows.items():
            for t_name, transition in (wf.get("transitions") or {}).items():
                roles = ((transition or {}).get("metadata") or {}).get("roles") or []
                for role in roles:
                    if role not in known:
                        errors.append(f"{rel(path)}:{yaml_line(lines, t_name + ':')}: transition {wf_name}.{t_name} "
                                      f"names unknown role {role}")
                if t_name.startswith(("approve", "reject", "verify")) and "ROLE_USER" in roles:
                    errors.append(f"{rel(path)}:{yaml_line(lines, t_name + ':')}: transition {wf_name}.{t_name} "
                                  "is open to ROLE_USER (every signed-in user)")
            meta = (wf.get("metadata") or {}).get("regulatory_metadata") or {}
            esc = meta.get("escalation_role")
            if esc and esc not in known:
                errors.append(f"{rel(path)}:{yaml_line(lines, 'escalation_role')}: escalation_role {esc} is unknown")
            for step in meta.get("steps") or []:
                role = step.get("approver_role")
                line = yaml_line(lines, f'"{step.get("name")}"')
                if role and role not in known:
                    errors.append(f"{rel(path)}:{line}: step \"{step.get('name')}\" waits on unknown role {role}")
                if role == "ROLE_USER" and step.get("step_type", "approval") == "approval":
                    errors.append(f"{rel(path)}:{line}: approval step \"{step.get('name')}\" is open to ROLE_USER "
                                  "(every signed-in user)")
    return errors


def check_seeders(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in SEEDERS:
        if not path.exists():
            continue
        lines = path.read_text(encoding="utf-8").splitlines()
        for i, line in enumerate(lines):
            m = SEEDER_ROLE.search(line)
            if not m:
                continue
            role = m.group(1)
            if role not in known:
                errors.append(f"{rel(path)}:{i + 1}: workflow step role {role} is unknown")
            if role == "ROLE_USER":
                window = "\n".join(lines[max(0, i - 6):i + 6])
                step_type = SEEDER_TYPE.search(window)
                if step_type is None or step_type.group(1) == "approval":
                    errors.append(f"{rel(path)}:{i + 1}: approval step is open to ROLE_USER (every signed-in user)")
    return errors


def check_lookups(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in sorted((ROOT / "src").rglob("*.php")):
        for i, line in enumerate(path.read_text(encoding="utf-8").splitlines()):
            for m in LOOKUP.finditer(line):
                if m.group(1) not in known:
                    errors.append(f"{rel(path)}:{i + 1}: recipient look-up on unknown role {m.group(1)} "
                                  "finds nobody")
    return errors


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    defined = known_roles()
    known = defined | PENDING
    errors = check_yaml(known) + check_seeders(known) + check_lookups(known)

    for role in sorted(PENDING & defined):
        print(f"note: {role} is defined in security.yaml now; remove it from PENDING in {rel(Path(__file__))}")

    if errors:
        print(f"Gate 82: {len(errors)} workflow role problem(s):")
        for e in errors:
            print(f"  {e}")
        print("Use a role from security.yaml role_hierarchy. An owner/assignee is a relation on the record,"
              " not a role.")
        return 1
    if not args.quiet:
        print("Gate 82: workflow approvers and recipients are real roles.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
