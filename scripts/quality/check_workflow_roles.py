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
  * config/workflows/**/*.yaml — transition `roles`, step `approver_role`
    (steps at `metadata.steps`, the layout the loader reads — S17),
    `escalation_role`; every regulatory workflow has steps, a title and an
    entity type;
  * the PHP seeders that write workflow steps (`'approverRole' =>`, `'role' =>`);
  * recipient look-ups in src/ (`findByRoleInTenant('ROLE_…'`,
    `findUserByRole('ROLE_…'`, `findUsersByRole('ROLE_…'`).

Rules:
  1. every role exists in config/packages/security.yaml (role_hierarchy keys
     or values) or in the pending list below;
  2. no approval step and no approve/reject/verify transition is open to ROLE_USER;
  3. (S11) a lifecycle transition open to ROLE_USER is a working step (start, submit,
     triage, …) or listed in USER_TRANSITION_EXCEPTIONS with a reason. A transition
     that finalises a record (assess, accept, publish, close, …) needs a real role —
     the TIA `assess` was open to every signed-in user.

A relation on the record ("the risk owner", "the document owner") is not a
role: a step names it with the keyword `approver_role: owner`, which the
responsibility resolver turns into the record's owner (deputies, then the
role fallback of the record's deadline source) — never a made-up ROLE_ string.

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
REGULATORY = WORKFLOWS / "regulatory"
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


# Transitions any signed-in user may fire: they move a record into work or hand it
# to review; the decision comes in a later transition with a real role.
USER_WORK_TRANSITIONS = {"start", "submit", "submit_for_review", "cancel_from_draft", "triage"}

# Workflows the system drives (delivery bookkeeping); roles only gate manual retries.
SYSTEM_WORKFLOWS = {"notification_delivery_lifecycle"}

# workflow.transition → why ROLE_USER is right there.
USER_TRANSITION_EXCEPTIONS = {
    "consent_lifecycle.revoke": "records the data subject's own withdrawal (GDPR Art. 7(3)); nobody decides it",
    "consent_lifecycle.expire": "records that the consent period ran out; a fact, not a decision",
    "isms_objective_lifecycle.achieve": "the objective owner records the measured result; ISO 9.3 reviews it",
}


# Step keys the application evaluates (RegulatoryWorkflowMirror → WorkflowStep, WorkflowService).
# S17: 27 `auto_progress_conditions` (field_completion, auto, risk_appetite) sat in the YAMLs and
# nobody evaluated them — only `time_based` is (app:process-timed-workflows). Record-driven
# progression belongs in config/packages/lifecycle.yaml (FieldCompletionAutoTransition).
STEP_KEYS = {"name", "description", "order", "approver_role", "step_type", "days_to_complete",
             "is_required", "auto_progress_conditions", "reject_action", "reject_target_step"}
EVALUATED_CONDITION_TYPES = {"time_based"}
REJECT_ACTIONS = {"loop_back"}


def step_key_problems(step: dict) -> list[str]:
    problems = [f"has key {key}, which nothing evaluates" for key in sorted(set(step) - STEP_KEYS)]
    conditions = step.get("auto_progress_conditions")
    if conditions is not None:
        kind = (conditions or {}).get("type") if isinstance(conditions, dict) else None
        if kind not in EVALUATED_CONDITION_TYPES:
            problems.append(f"has auto_progress_conditions of type {kind!r}, which nothing evaluates "
                            "(only time_based; record-driven progression goes into lifecycle.yaml)")
    if "reject_action" in step and step.get("reject_action") not in REJECT_ACTIONS:
        problems.append(f"has reject_action {step.get('reject_action')!r}; WorkflowService knows only loop_back")
    if step.get("reject_action") == "loop_back" and not isinstance(step.get("reject_target_step"), int):
        problems.append("loops back without reject_target_step (order of an earlier step)")
    return problems


# Keyword for "whoever is responsible for the workflow's record" (WorkflowInstanceAdapter::APPROVER_OWNER).
OWNER = "owner"


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
                elif ("ROLE_USER" in roles and t_name not in USER_WORK_TRANSITIONS
                      and wf_name not in SYSTEM_WORKFLOWS and f"{wf_name}.{t_name}" not in USER_TRANSITION_EXCEPTIONS):
                    errors.append(f"{rel(path)}:{yaml_line(lines, t_name + ':')}: transition {wf_name}.{t_name} "
                                  "finalises a record but is open to ROLE_USER; name a real role or add it to "
                                  "USER_TRANSITION_EXCEPTIONS with a reason")
            wf_meta = wf.get("metadata") or {}
            meta = wf_meta.get("regulatory_metadata") or {}
            esc = meta.get("escalation_role")
            if esc and esc not in known:
                errors.append(f"{rel(path)}:{yaml_line(lines, 'escalation_role')}: escalation_role {esc} is unknown")
            # S17: the steps sit at metadata.steps, beside regulatory_metadata — the one layout
            # RegulatoryWorkflowLoader::stepsOf() reads. The gate once read regulatory_metadata.steps,
            # matched nothing and checked no step at all.
            if "steps" in meta:
                errors.append(f"{rel(path)}:{yaml_line(lines, 'steps:')}: {wf_name} nests its steps under "
                              "regulatory_metadata; put them at metadata.steps (RegulatoryWorkflowLoader::stepsOf)")
            steps = wf_meta.get("steps") or meta.get("steps") or []
            if path.parent == REGULATORY:
                if not steps:
                    errors.append(f"{rel(path)}:1: regulatory workflow {wf_name} has no approval steps at "
                                  "metadata.steps — the loader would return null for it")
                for key in ("title", "entity_type"):
                    if not meta.get(key):
                        errors.append(f"{rel(path)}:{yaml_line(lines, 'regulatory_metadata:')}: {wf_name} lacks "
                                      f"regulatory_metadata.{key} (RegulatoryWorkflowMirror needs it for the "
                                      "database row)")
            for step in steps:
                for problem in step_key_problems(step):
                    step_line = yaml_line(lines, '"' + str(step.get("name")) + '"')
                    errors.append(f"{rel(path)}:{step_line}: step \"{step.get('name')}\" {problem}")
                role = step.get("approver_role")
                line = yaml_line(lines, f'"{step.get("name")}"')
                if role and role != OWNER and role not in known:
                    errors.append(f"{rel(path)}:{line}: step \"{step.get('name')}\" waits on unknown role {role}")
                if role == "ROLE_USER" and step.get("step_type", "approval") == "approval":
                    errors.append(f"{rel(path)}:{line}: approval step \"{step.get('name')}\" is open to ROLE_USER "
                                  "(every signed-in user)")
    return errors


def stale_exceptions() -> set[str]:
    used: set[str] = set()
    for path in sorted(WORKFLOWS.rglob("*.yaml")):
        data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
        for wf_name, wf in ((data.get("framework") or {}).get("workflows") or {}).items():
            for t_name, transition in (wf.get("transitions") or {}).items():
                if "ROLE_USER" in (((transition or {}).get("metadata") or {}).get("roles") or []):
                    used.add(f"{wf_name}.{t_name}")
    return set(USER_TRANSITION_EXCEPTIONS) - used


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
    errors += [f"{rel(Path(__file__))}: USER_TRANSITION_EXCEPTIONS entry {key} matches no ROLE_USER transition"
               for key in sorted(stale_exceptions())]

    for role in sorted(PENDING & defined):
        print(f"note: {role} is defined in security.yaml now; remove it from PENDING in {rel(Path(__file__))}")

    if errors:
        print(f"Gate 82: {len(errors)} workflow role problem(s):")
        for e in errors:
            print(f"  {e}")
        print("Use a role from security.yaml role_hierarchy. An owner/assignee is a relation on the record:"
              " use `approver_role: owner`.")
        return 1
    if not args.quiet:
        print("Gate 82: workflow approvers and recipients are real roles.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
