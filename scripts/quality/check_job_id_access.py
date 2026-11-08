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

    python3 scripts/quality/check_job_id_access.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLERS = ROOT / "src" / "Controller"
OPT_OUT = "@job-access-ok:"

ACTION = re.compile(r"\n    public function (\w+)\(([^)]*)\)")
ROUTE = re.compile(r"#\[Route\(")
# Every JobStatusService method that takes a job id (read, delete, but also markFailed / updatePayload ...).
JOB_METHODS = r"(?:read|exists|delete|mark\w+|heartbeat|update\w+)"
RECEIVER_DECL = re.compile(r"JobStatusService\s+\$(\w+)")
ARG = re.compile(r"\$(\w+)")
GUARD = re.compile(r"#\[IsGranted\(\s*(?:\\?App\\Security\\Voter\\)?AsyncJobVoter::ACCESS\s*,\s*subject:\s*'(\w+)'")


def actions(text: str) -> list[tuple[str, str, str, int]]:
    """(name, attribute block, body, line) of every public method."""
    found = []
    matches = list(ACTION.finditer(text))
    for i, m in enumerate(matches):
        attr_start = text.rfind("\n\n", 0, m.start())
        attributes = text[attr_start if attr_start >= 0 else 0:m.start()]
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        found.append((m.group(1), attributes, text[m.start():end], text.count("\n", 0, m.start()) + 2))
    return found


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    failures: list[str] = []
    checked = 0
    for path in sorted(CONTROLLERS.rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="replace")
        if "JobStatusService" not in text:
            continue
        class_start = text.find("\nfinal class ") if "\nfinal class " in text else text.find("\nclass ")
        class_guards = set(GUARD.findall(text[:class_start])) if class_start > 0 else set()
        for name, attributes, body, line in actions(text):
            if not ROUTE.search(attributes):
                continue
            signature = body.split("{", 1)[0]
            # The service may sit in any property / local alias, not just `$jobStatusService`.
            receivers = set(RECEIVER_DECL.findall(text)) | {"jobStatusService", "jobStatus", "jobs"}
            for alias in re.findall(r"\$(\w+)\s*=\s*\$this->(?:" + "|".join(map(re.escape, receivers)) + r")\b", body):
                receivers.add(alias)
            call = re.compile(r"(?:" + "|".join(map(re.escape, receivers)) + r")\s*->\s*" + JOB_METHODS + r"\(")
            used: set[str] = set()
            for m in call.finditer(body):
                depth, i = 1, m.end()
                while i < len(body) and depth:  # the whole argument list: `(string) $id`, `id: $id`, `trim($id)`
                    depth += {"(": 1, ")": -1}.get(body[i], 0)
                    i += 1
                used.update(ARG.findall(body[m.end():i]))
            for variable in used:
                if f"${variable}" not in signature:
                    continue  # not a route argument (an id from the session, …)
                checked += 1
                if variable in GUARD.findall(attributes) or variable in class_guards or OPT_OUT in body:
                    continue
                failures.append(
                    f"{path.relative_to(ROOT)}:{line}: {name}() reads job ${variable} without "
                    f"#[IsGranted(AsyncJobVoter::ACCESS, subject: '{variable}')]"
                )

    if failures:
        print(f"Gate 90: {len(failures)} job route(s) without an owner check:")
        for failure in failures:
            print(f"  {failure}")
        return 1
    if not args.quiet:
        print(f"Gate 90: every job route checks the job's owner ({checked} routes).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
