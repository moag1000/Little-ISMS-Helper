#!/usr/bin/env python3
"""
check_job_id_access.py — Gate 90: every route that reads an async job by id checks its owner.

Async admin jobs (exports, backups, reports) keep their status and their result
file under var/jobs/<uuid>.json. A job id travels in URLs and logs; whoever knows
it must still not read another person's export. `JobStatusService::create()`
records the dispatching user and organisation, and `AsyncJobVoter::ACCESS` lets
only that user (or a super admin) in — but only on routes that ask for it
(CLAUDE.md "Tenant scope + ownership").

The gate scans the controllers in src/Controller/ and fails on a `#[Route]`
action that calls JobStatusService `read()` / `exists()` / `delete()` with a route
argument and carries no `#[IsGranted(AsyncJobVoter::ACCESS, subject: '<arg>')]`
(on the action or the class). A route with its own access rule (the quick-fix
pages answer to the quick-fix token) carries `// @job-access-ok: <reason>` in
the action.

Since T2 the job files themselves have two readers only: JobStatusService (the
status store with the owner record) and WorkerHealthService (counts pending jobs
for the worker page, per organisation). Any other file in src/ that names the
`var/jobs` directory reads job status and result paths past the voter and fails.

    python3 scripts/quality/check_job_id_access.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
CONTROLLERS = ROOT / "src" / "Controller"
OPT_OUT = "@job-access-ok:"
from php_source import class_methods, join_literals, matching_end, split_args, strip_comments, use_aliases

# Readers of the job files, with the reason they may.
JOB_DIR_READERS = {
    "src/Service/Job/JobStatusService.php": "the status store; records and checks the owner",
    "src/Service/Job/WorkerHealthService.php": "counts pending jobs and the heartbeat for the worker page, per organisation",
}
JOB_DIR = re.compile(r"""var/jobs\b|['"]/?jobs/?['"]""")
VAR_SEGMENT = re.compile(r"""['"]/?var/?['"]""")

ROUTE = re.compile(r"#\[Route\(")
# Every JobStatusService method that takes a job id (read, delete, but also markFailed / updatePayload ...).
JOB_METHODS = r"(?:read|exists|delete|mark\w+|heartbeat|update\w+)"
# Job types a controller may hold without that reading a job by id. SetupJobStatusService keeps the
# wizard's fixed job keys in the session of the person setting up (no var/jobs, no id from outside).
NOT_READERS = {"JobDispatcher", "AsyncJobDispatcher", "JobTenantScope", "JobContext", "AsyncJobInterface",
               "JobRunnerInterface", "InRequestJobRunner", "MessengerJobRunner", "SetupJobStatusService"}
LEGACY_RECEIVERS = {"jobStatusService": "JobStatusService", "jobStatus": "JobStatusService",
                    "jobs": "JobStatusService"}
GUARD = re.compile(r"#\[IsGranted\(\s*(?:\\?App\\Security\\Voter\\)?AsyncJobVoter::ACCESS\s*,\s*subject:\s*'(\w+)'")
# A route argument that is named like a job id must be guarded whatever the body does with it.
JOB_PLACEHOLDER = re.compile(r"\{(job\w*|\w*[jJ]ob_?[iI]d|\w*job_?uuid)\}", re.I)
TYPED_PARAM = re.compile(r"\??\\?([A-Za-z_][\w\\]*)\s+\$(\w+)")
# `$jobId = $service->create(...)` is a fresh id, not an input; `(string) $id` or `$request->attributes->get('id')` is.
FRESH_VALUE = re.compile(r"(?<!attributes)(?<!query)(?<!request)(?<!headers)(?<!cookies)->\s*(?!get\w*\()\w+\s*\(")
# Request input that is no route argument: a guard cannot name it as subject (T4).
SUPERGLOBALS = {"_GET", "_POST", "_REQUEST", "_COOKIE"}
# `$x = new JobStatusService()` / `(new JobStatusService())->read($id)`: a receiver without a typed parameter.
NEW_JOB = re.compile(r"\bnew\s+\\?((?:\w+\\)*Job\w*)\s*\(")
CALL_ARGS = re.compile(r"(?:\$this\s*->\s*|\bself::|\bstatic::)(\w+)\s*\(")


def job_receivers(code: str) -> dict[str, str]:
    """property / variable name -> job service class, for every Job* type the file declares."""
    aliases = use_aliases(code)
    found = dict(LEGACY_RECEIVERS)
    for m in TYPED_PARAM.finditer(code):
        short = aliases.get(m.group(1), m.group(1)).rsplit("\\", 1)[-1]
        if "Job" in short and not short.endswith("Job") and short not in NOT_READERS:
            found[m.group(2)] = short
    for m in re.finditer(r"\$(\w+)\s*=\s*" + NEW_JOB.pattern, code):
        short = aliases.get(m.group(2), m.group(2)).rsplit("\\", 1)[-1]
        if not short.endswith("Job") and short not in NOT_READERS:
            found[m.group(1)] = short
    return found


def direct_reads(body: str, receivers: dict[str, str]) -> set[str]:
    """Variables a method body passes to a job-reading call (statuses by id, or any Job* wrapper)."""
    used: set[str] = set()
    for name, kind in receivers.items():
        # a dynamic method name (`->$f($id)`) may be any reader, so its arguments count as read
        method = (JOB_METHODS + r"|\$\w+|\{[^}]*\}") if kind == "JobStatusService" else r"\w+|\$\w+|\{[^}]*\}"
        method = "(?:" + method + ")"
        call = re.compile(r"\$(?:this\s*->\s*)?" + re.escape(name) + r"\s*\??->\s*" + method + r"\s*\(")
        call2 = re.compile(r"\$this\s*->\s*" + re.escape(name) + r"\s*\??->\s*" + method + r"\s*\(")
        for pattern in (call, call2):
            for m in pattern.finditer(body):
                used.update(re.findall(r"\$(\w+)", body[m.end():matching_end(body, m.end() - 1)]))
    for m in re.finditer(r"\(\s*" + NEW_JOB.pattern + r"[^()]*\)\s*\)\s*->\s*(?:\w+|\$\w+)\s*\(", body):
        used.update(re.findall(r"\$(\w+)", body[m.end():matching_end(body, m.end() - 1)]))
    # `$alias = $this->jobStatusService;` then `$alias->read($id)`
    for alias, prop in re.findall(r"\$(\w+)\s*=\s*\$this\s*->\s*(\w+)\s*;", body):
        if prop in receivers:
            sub = {alias: receivers[prop]}
            used |= direct_reads(body, sub) if alias not in receivers else set()
    return used


def reads_by_method(code: str) -> tuple[str, list, dict[str, set[str]]]:
    """(class head, methods, name -> variables/parameters that end in a job read), helpers resolved."""
    head, methods = class_methods(code)
    receivers = job_receivers(code)
    reads = {m.name: direct_reads(m.body, receivers) for m in methods}
    by_name = {m.name: m for m in methods}
    changed = True
    while changed:  # helper chains and local aliases, to a fixed point
        changed = False
        for m in methods:
            current = reads[m.name]
            before = set(current)
            for local, expr in re.findall(r"\$(\w+)\s*=\s*([^;]+);", m.body):
                if local in current and not FRESH_VALUE.search(expr):
                    current.update(re.findall(r"\$(\w+)", expr))
            for call in CALL_ARGS.finditer(m.body):
                helper = by_name.get(call.group(1))
                if helper is None:
                    continue
                args = split_args(m.body[call.end():matching_end(m.body, call.end() - 1) - 1])
                names = helper.param_names()
                for i, arg in enumerate(args):
                    named = re.match(r"(\w+)\s*:(?!:)", arg)
                    target = named.group(1) if named else (names[i] if i < len(names) else None)
                    if target and target in reads[helper.name]:
                        current.update(re.findall(r"\$(\w+)", arg))
            if current != before:
                changed = True
    return head, methods, reads


def problems_in(rel: str, text: str) -> tuple[list[str], int]:
    code = strip_comments(text)
    head, methods, reads = reads_by_method(code)
    class_guards = set(GUARD.findall(head))
    found: list[str] = []
    checked = 0
    for m in methods:
        if not ROUTE.search(m.attributes):
            continue
        line = code.count("\n", 0, m.start) + 1
        guards = set(GUARD.findall(m.attributes)) | class_guards
        if OPT_OUT in text[m.start:m.start + len(m.params) + len(m.body) + 200]:
            continue
        params = set(m.param_names())
        wanted = {v for v in reads[m.name] if v in params or v in SUPERGLOBALS}
        for placeholder in JOB_PLACEHOLDER.findall(m.attributes):
            wanted.add(placeholder)
        for variable in sorted(wanted):
            checked += 1
            if variable not in guards:
                found.append(f"{rel}:{line}: {m.name}() reads job ${variable} without "
                             f"#[IsGranted(AsyncJobVoter::ACCESS, subject: '{variable}')]")
    return found, checked


def reads_job_files(code: str) -> int:
    """Offset of a reference to the var/jobs directory, in any spelling (joined literals, path segments); -1 if none."""
    joined = join_literals(code)
    hit = JOB_DIR.search(joined)
    if hit and not (hit.group(0).startswith(("'", '"'))):
        return hit.start()
    if VAR_SEGMENT.search(joined) and re.search(r"""['"]/?jobs/?['"]""", joined):
        return 0
    return hit.start() if hit else -1


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    failures: list[str] = []
    checked = 0
    for path in sorted(CONTROLLERS.rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        found, count = problems_in(path.relative_to(ROOT).as_posix(), text)
        failures.extend(found)
        checked += count

    for path in sorted((ROOT / "src").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        if rel in JOB_DIR_READERS:
            continue
        code = strip_comments(path.read_text(encoding="utf-8", errors="replace"))
        at = reads_job_files(code)
        if at >= 0:
            line = code.count("\n", 0, at) + 1
            failures.append(f"{rel}:{line}: reads the job files directly — go through JobStatusService "
                            f"(owner check) or add the file with its reason to JOB_DIR_READERS")

    if failures:
        print(f"Gate 90: {len(failures)} job route(s) or file reader(s) without an owner check:")
        for failure in failures:
            print(f"  {failure}")
        return 1
    if not args.quiet:
        print(f"Gate 90: every job route checks the job's owner ({checked} routes).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
