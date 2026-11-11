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
  4. (S15) a working step open to ROLE_USER leads neither to an end state nor to a
     place a decision transition leads to; PUBLIC_ACCESS / IS_AUTHENTICATED* count as
     ROLE_USER; no workflow is exempt (notification delivery is system-only, roles: []).

Per-organisation overrides (/admin/lifecycle-overrides) live in the database, out of
reach of this gate: App\\Lifecycle\\Config\\LifecycleOverridePolicy applies the same line
in PHP (only listed roles, never ROLE_USER, four eyes / reasons / module gates only
tightened, Leitung and DSB transitions keep their roles).

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
sys.path.insert(0, str(Path(__file__).resolve().parent))
from php_source import class_methods, matching_end, split_args, strip_comments  # noqa: E402
SECURITY = ROOT / "config" / "packages" / "security.yaml"
WORKFLOWS = ROOT / "config" / "workflows"
REGULATORY = WORKFLOWS / "regulatory"
COMMANDS = ROOT / "src" / "Command"

# Roles in use whose definition is on its way in another change. Each line
# names the change; delete the line once the role is in security.yaml.
PENDING: set[str] = set()

# Recipient look-ups: the role is the first argument, or the one named `role:`.
# Any repository method named `*ByRole*` (findUsersByRoles, findByRoleInTenant, …), called on an object or class.
LOOKUP_CALL = re.compile(r"(?:->|::)\s*((?:find|get|load|fetch|search|list|query)\w*ByRoles?\w*)\s*\(")
# A look-up whose role cannot be read from the code needs a reviewed reason (T4: B10).
LOOKUP_OK = re.compile(r"@role-lookup-ok:[ \t]*[^\s*/]")
ROLE_LITERAL = re.compile(r"""['"](ROLE_[A-Z_]+)['"]""")
CONST_REF = re.compile(r"^(?:self|static|[\\\w]+)::([A-Z_][A-Z0-9_]*)$")
# A step role written into a seeder: the array key, a named argument or the setter.
# The value may be a literal, a class constant or a variable: roles_named_by() resolves it (T4: B9).
SEEDER_KEY = re.compile(r"""['"](?:approverRole|approver_role)['"]\s*=>\s*([^,;\]]+)|"""
                        r"""\bapproverRole\s*:(?!:)\s*([^,;)\]]+)|"""
                        r"""\bsetApproverRole\s*\(\s*([^,;)]+)""")
SEEDER_ROLE_KEY = re.compile(r"""['"]role['"]\s*=>\s*([^,;\]]+)""")
SEEDER_MARKER = re.compile(r"\bWorkflowStep\b|\bsetApproverRole\b|['\"]approverRole['\"]|\bapproverRole\s*:|['\"]stepType['\"]")
SEEDER_TYPE = re.compile(r"""['"](?:stepType|step_type)['"]\s*=>\s*['"]([a-z_]+)['"]|\bsetStepType\s*\(\s*['"]([a-z_]+)['"]""")

# step_type values WorkflowService knows. It treats every step that is not `notification` as an approval
# (handleStepAssignment, approvalWouldCloseWorkflow), so any other value is an approval with a mislabel.
STEP_TYPES = {"approval", "notification"}
NOTIFICATION = "notification"

# Seeder steps on ROLE_USER that exist today and wait for a decision in the code. Each entry names the
# file and step; the gate fails once the entry no longer matches (delete it with the fix).
KNOWN_SEEDER_ROLE_USER: dict[str, str] = {}


# Transitions any signed-in user may fire: they move a record into work or hand it
# to review; the decision comes in a later transition with a real role. The name alone
# is not enough (S15: `submit_for_review` with `to: published` stayed green) — a work
# transition must also lead to a place that is neither an end state nor a place a
# decision transition leads to (see decided_places()).
USER_WORK_TRANSITIONS = {"start", "submit", "submit_for_review", "triage"}

# Roles every signed-in (or anonymous) user passes — all of them count as ROLE_USER.
EVERYONE = {"ROLE_USER", "PUBLIC_ACCESS", "IS_AUTHENTICATED", "IS_AUTHENTICATED_FULLY",
            "IS_AUTHENTICATED_REMEMBERED"}

# Transition names that record a decision; the places they lead to are "decided".
DECISION_PREFIXES = ("approve", "publish", "accept", "close", "verify", "complete", "achieve", "archive",
                     "dispose", "retire", "confirm", "assess", "sign", "release", "reject", "revoke",
                     "expire", "decide", "resolve", "activate")

# A transition with a real role that leads back or aside (reopen, retry, …) is not a decision; every other
# one with a real role is, whatever it is called (S15 follow-up: `finalise` was no prefix and decided nothing).
NON_DECISION_PREFIXES = ("reopen", "retry", "return", "reset", "revert", "restart", "resume", "back", "cancel",
                         "withdraw", "defer", "pause", "request_change", "rework", "skip")

# workflow.transition → why ROLE_USER is right there. (S15 removed the pauschal exemption of
# notification_delivery_lifecycle — its steps are system-driven, `roles: []` — and the consent
# revoke/expire and objective achieve entries: DPO/manager resp. manager or responsible person.)
USER_TRANSITION_EXCEPTIONS: dict[str, str] = {
    "change_request_lifecycle.cancel_from_draft": "the requester withdraws their own draft before anybody "
                                                  "decided; nothing is approved or closed",
}


class _AnyTagLoader(yaml.SafeLoader):
    """SafeLoader that reads Symfony's `!php/const`, `!tagged_iterator`, … tags as plain values."""


def _any_tag(loader, suffix, node):
    if isinstance(node, yaml.MappingNode):
        return loader.construct_mapping(node, deep=True)
    if isinstance(node, yaml.SequenceNode):
        return loader.construct_sequence(node, deep=True)
    return loader.construct_scalar(node)


_AnyTagLoader.add_multi_constructor("!", _any_tag)


def load_yaml(text: str) -> dict:
    data = yaml.load(text, Loader=_AnyTagLoader)  # noqa: S506 - the loader is a SafeLoader
    return data if isinstance(data, dict) else {}


def workflow_files() -> list[Path]:
    """config/workflows/** plus every other YAML under config/ that defines framework.workflows (T4: B7 —
    a `framework: workflows:` block in config/packages/zz.yaml was never read)."""
    files = set(WORKFLOWS.rglob("*.yaml"))
    for path in (ROOT / "config").rglob("*.yaml"):
        if path in files or "workflows" not in path.read_text(encoding="utf-8", errors="replace"):
            continue
        try:
            data = load_yaml(path.read_text(encoding="utf-8"))
        except yaml.YAMLError:
            continue
        if workflows_of(data):
            files.add(path)
    return sorted(files)


def as_list(value) -> list:
    if value is None:
        return []
    return value if isinstance(value, list) else [value]


def decided_places(wf: dict) -> set[str]:
    """End states plus the places a decision transition leads to."""
    transitions = wf.get("transitions") or {}
    outgoing: set[str] = set()
    for t in transitions.values():
        outgoing.update(as_list((t or {}).get("from")))
    # places are names or `{name, metadata: {tone}}` maps (W7: the tone of each place lives in its metadata)
    places = {p["name"] if isinstance(p, dict) else p for p in as_list(wf.get("places"))}
    for t in transitions.values():
        places.update(as_list((t or {}).get("to")))
    decided = {p for p in places if p not in outgoing}
    for name, t in transitions.items():
        roles = set(as_list(((t or {}).get("metadata") or {}).get("roles")))
        real_role = bool(roles - EVERYONE)
        if name.startswith(DECISION_PREFIXES) or (
                real_role and name not in USER_WORK_TRANSITIONS and not name.startswith(NON_DECISION_PREFIXES)):
            decided.update(as_list((t or {}).get("to")))
    return decided


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


def workflows_of(data: dict) -> dict:
    """framework.workflows of a file, including every `when@<env>:` wrapper (config is merged per environment)."""
    found = dict((data.get("framework") or {}).get("workflows") or {})
    for key, value in data.items():
        if str(key).startswith("when@") and isinstance(value, dict):
            for name, wf in ((value.get("framework") or {}).get("workflows") or {}).items():
                found[f"{key}.{name}"] = wf
    return found


def yaml_line(lines: list[str], needle: str, start: int = 0) -> int:
    for i in range(start, len(lines)):
        if needle in lines[i]:
            return i + 1
    return 0


def check_yaml(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in workflow_files():
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()
        data = load_yaml(text)
        workflows = workflows_of(data)
        for wf_name, wf in workflows.items():
            decided = decided_places(wf)
            for t_name, transition in (wf.get("transitions") or {}).items():
                roles = as_list(((transition or {}).get("metadata") or {}).get("roles"))
                for role in roles:
                    if role not in known and role not in EVERYONE:
                        errors.append(f"{rel(path)}:{yaml_line(lines, t_name + ':')}: transition {wf_name}.{t_name} "
                                      f"names unknown role {role}")
                everyone = sorted(set(roles) & EVERYONE)
                if not everyone:
                    continue
                where = f"{rel(path)}:{yaml_line(lines, t_name + ':')}: transition {wf_name}.{t_name}"
                key = f"{wf_name}.{t_name}"
                if t_name.startswith(("approve", "reject", "verify")):
                    errors.append(f"{where} is open to {everyone[0]} (every signed-in user)")
                elif key in USER_TRANSITION_EXCEPTIONS:
                    continue
                elif t_name not in USER_WORK_TRANSITIONS:
                    errors.append(f"{where} finalises a record but is open to {everyone[0]}; name a real role or "
                                  "add it to USER_TRANSITION_EXCEPTIONS with a reason")
                else:
                    targets = sorted(set(as_list((transition or {}).get("to"))) & decided)
                    if targets:
                        errors.append(f"{where} is a work step open to {everyone[0]} but leads to {targets[0]}, "
                                      "an end state or a place a decision leads to")
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
                if role and role != OWNER and role not in known and role not in EVERYONE:
                    errors.append(f"{rel(path)}:{line}: step \"{step.get('name')}\" waits on unknown role {role}")
                step_type = step.get("step_type", "approval")
                if step_type not in STEP_TYPES:
                    errors.append(f"{rel(path)}:{line}: step \"{step.get('name')}\" has step_type {step_type!r}; "
                                  f"WorkflowService knows {sorted(STEP_TYPES)} and treats everything but "
                                  "`notification` as an approval")
                if role in EVERYONE and step_type != NOTIFICATION:
                    errors.append(f"{rel(path)}:{line}: approval step \"{step.get('name')}\" is open to ROLE_USER "
                                  "(every signed-in user)")
    return errors


def stale_exceptions() -> set[str]:
    used: set[str] = set()
    for path in workflow_files():
        data = load_yaml(path.read_text(encoding="utf-8"))
        for wf_name, wf in workflows_of(data).items():
            for t_name, transition in (wf.get("transitions") or {}).items():
                if set(as_list(((transition or {}).get("metadata") or {}).get("roles"))) & EVERYONE:
                    used.add(f"{wf_name}.{t_name}")
    return set(USER_TRANSITION_EXCEPTIONS) - used


def enclosing_array(code: str, pos: int) -> str:
    """The innermost `[...]` literal around pos (a seeder's step definition), else a 600-char window."""
    depth, i = 0, pos
    while i > 0:
        i -= 1
        if code[i] == "]":
            depth += 1
        elif code[i] == "[":
            if depth == 0:
                return code[i:matching_end(code, i)]
            depth -= 1
    return code[max(0, pos - 600):pos + 600]


def step_type_near(code: str, pos: int) -> str | None:
    """The step type of the step definition around pos: its array literal, else the method it sits in."""
    for scope in (enclosing_array(code, pos), _method_around(code, pos)):
        m = SEEDER_TYPE.search(scope)
        if m:
            return m.group(1) or m.group(2)
    return None


def _method_around(code: str, pos: int) -> str:
    _, methods = class_methods(code)
    for method in methods:
        if method.start <= pos < method.end:
            return code[method.start:method.end]
    return ""


def seeder_files() -> list[Path]:
    """Every PHP file in src/ — a seeder is not only a command (T4: B8; SEEDER_MARKER narrows it)."""
    return sorted((ROOT / "src").rglob("*.php"))


def seeder_hits(code: str) -> list[tuple[int, str, str]]:
    """(offset, role, key kind) of every step role a file writes, literals and resolvable constants / variables."""
    hits: list[tuple[int, str, str]] = []
    for m in SEEDER_KEY.finditer(code):
        arg = next(g for g in m.groups() if g)
        hits += [(m.start(), role, "approver") for role in roles_named_by(arg, code)]
    for m in SEEDER_ROLE_KEY.finditer(code):
        hits += [(m.start(), role, "role") for role in roles_named_by(m.group(1), code)]
    return sorted(hits)


def check_seeders(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in seeder_files():
        code = strip_comments(path.read_text(encoding="utf-8"))
        if not SEEDER_MARKER.search(code):
            continue
        for pos, role, _kind in seeder_hits(code):
            line = code.count("\n", 0, pos) + 1
            if role not in known:
                errors.append(f"{rel(path)}:{line}: workflow step role {role} is unknown")
            if role == "ROLE_USER":
                step_type = step_type_near(code, pos)
                if step_type == NOTIFICATION:
                    continue
                name = re.search(r"""['"]name['"]\s*=>\s*['"]([^'"]+)['"]""", enclosing_array(code, pos))
                key = f"{rel(path)}:{name.group(1) if name else line}"
                if key in KNOWN_SEEDER_ROLE_USER:
                    continue
                errors.append(f"{rel(path)}:{line}: approval step is open to ROLE_USER (every signed-in user)"
                              f" (step type {step_type or 'unset'}; only `notification` is not an approval)")
    return errors


def stale_seeder_exceptions() -> set[str]:
    live: set[str] = set()
    for path in seeder_files():
        code = strip_comments(path.read_text(encoding="utf-8"))
        for pos, role, kind in seeder_hits(code):
            if role == "ROLE_USER" and kind == "approver" and step_type_near(code, pos) != NOTIFICATION:
                name = re.search(r"""['"]name['"]\s*=>\s*['"]([^'"]+)['"]""", enclosing_array(code, pos))
                if name:
                    live.add(f"{rel(path)}:{name.group(1)}")
    return set(KNOWN_SEEDER_ROLE_USER) - live


_CONSTS: dict[str, str] | None = None


def role_constants() -> dict[str, str]:
    """`Class::NAME` / `NAME` -> ROLE_ value for every class constant in src/ that holds a role."""
    global _CONSTS
    if _CONSTS is None:
        _CONSTS = {}
        pattern = re.compile(r"""\bconst\s+(?:\w+\s+)?([A-Z_][A-Z0-9_]*)\s*=\s*['"](ROLE_[A-Z_]+)['"]""")
        for path in (ROOT / "src").rglob("*.php"):
            text = path.read_text(encoding="utf-8")
            if "ROLE_" not in text:
                continue
            for m in pattern.finditer(strip_comments(text)):
                _CONSTS.setdefault(m.group(1), m.group(2))
                _CONSTS[f"{path.stem}::{m.group(1)}"] = m.group(2)
    return _CONSTS


def roles_named_by(argument: str, code: str) -> list[str]:
    """ROLE_ strings an argument can hold: a literal, a class constant, or a variable assigned in the file."""
    argument = argument.strip()
    found = ROLE_LITERAL.findall(argument)
    ref = CONST_REF.match(argument)
    if ref:
        consts = role_constants()
        owner = argument.rsplit("::", 1)[0].rsplit("\\", 1)[-1]
        value = consts.get(f"{owner}::{ref.group(1)}") or consts.get(ref.group(1))
        found += [value] if value else []
    var = re.fullmatch(r"\$(\w+)", argument)
    if var:  # `foreach (['ROLE_A', 'ROLE_B'] as $role)`
        for m in re.finditer(r"\bforeach\s*\(([^;{]*?)\s+as\s+(?:\$\w+\s*=>\s*)?\$" + var.group(1) + r"\b", code):
            found += ROLE_LITERAL.findall(m.group(1))
    if var:  # every value the file ever gives that variable (assignment, default, ternary branch)
        for m in re.finditer(r"\$" + var.group(1) + r"\s*(?:\?\?)?=\s*([^;]+);", code):
            found += ROLE_LITERAL.findall(m.group(1))
        for m in re.finditer(r"\$" + var.group(1) + r"\s*=\s*['\"](ROLE_[A-Z_]+)['\"]\s*[,)]", code):
            found.append(m.group(1))
    return found


def role_argument(call_args: list[str]) -> str | None:
    for arg in call_args:
        named = re.match(r"roles?\s*:(?!:)\s*(.*)$", arg, re.S)
        if named:
            return named.group(1)
    return call_args[0] if call_args and not re.match(r"\w+\s*:(?!:)", call_args[0]) else None


def lookups_in(code: str) -> list[tuple[int, list[str]]]:
    """(offset, roles) of every recipient look-up; an empty list = the role cannot be read from the code."""
    found: list[tuple[int, list[str]]] = []
    for m in LOOKUP_CALL.finditer(code):
        args = split_args(code[m.end():matching_end(code, m.end() - 1) - 1])
        arg = role_argument(args)
        roles = roles_named_by(arg, code) if arg is not None else []
        if not roles and arg is not None and passes_on_parameter(code, m.start(), arg):
            continue
        found.append((m.start(), roles))
    return found


def passes_on_parameter(code: str, pos: int, arg: str) -> bool:
    """`private function findUsersByRole(string $role, …) { …->findByRoleInTenant($role, …) }`: a wrapper that is
    itself a `*ByRole*` look-up hands its parameter on; its own callers are checked by name."""
    var = re.fullmatch(r"\$(\w+)", arg.strip())
    if not var:
        return False
    _, methods = class_methods(code)
    return any(mt.start <= pos < mt.end and LOOKUP_CALL.match("->" + mt.name + "(") and var.group(1) in mt.param_names()
               for mt in methods)


def check_lookups(known: set[str]) -> list[str]:
    errors: list[str] = []
    for path in sorted((ROOT / "src").rglob("*.php")):
        text = path.read_text(encoding="utf-8")
        if "ByRole" not in text:
            continue
        code = strip_comments(text)  # whole file: the call may break across lines
        lines = text.split("\n")
        for pos, roles in lookups_in(code):
            line = code.count(chr(10), 0, pos) + 1
            for role in roles:
                if role not in known:
                    errors.append(f"{rel(path)}:{line}: recipient look-up on unknown role {role} finds nobody")
            if not roles and not (LOOKUP_OK.search(lines[line - 1]) or (line > 1 and LOOKUP_OK.search(lines[line - 2]))):
                errors.append(f"{rel(path)}:{line}: recipient look-up whose role the gate cannot read (variable, "
                              "parameter, expression) — pass a ROLE_ literal or constant, or add "
                              "`// @role-lookup-ok: <reason>` above the call")
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
    errors += [f"{rel(Path(__file__))}: KNOWN_SEEDER_ROLE_USER entry {key} matches no step any more; delete it"
               for key in sorted(stale_seeder_exceptions())]

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
