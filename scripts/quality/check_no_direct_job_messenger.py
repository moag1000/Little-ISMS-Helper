#!/usr/bin/env python3
"""
check_no_direct_job_messenger.py — controllers must dispatch async jobs through
the JobDispatcher facade, never by putting an ExecuteJobMessage straight on the
Messenger bus.

The app's default async runner is `in_request` (shared hosting / no worker).
`$messageBus->dispatch(new ExecuteJobMessage(...))` bypasses that and lands the
job in the doctrine queue, which nothing consumes — the progress page then hangs
at "pending" forever. The correct call is
`$jobDispatcher->dispatch($jobClass, $args, $jobId, $response, $session)`, which
honours `app.async_job.runner` (in_request by default, messenger when opted in).

This guard fails if any controller references ExecuteJobMessage directly. The
MessageHandler that legitimately consumes it (messenger mode) lives outside
src/Controller and is unaffected.
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTROLLER_DIR = ROOT / "src" / "Controller"

PATTERN = re.compile(r"ExecuteJobMessage")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    hits = []
    for php in CONTROLLER_DIR.rglob("*.php"):
        text = php.read_text(encoding="utf-8", errors="ignore")
        for i, line in enumerate(text.splitlines(), 1):
            stripped = line.strip()
            if stripped.startswith("//") or stripped.startswith("*"):
                continue  # ignore comments / docblocks
            if PATTERN.search(line):
                hits.append((php.relative_to(ROOT), i, stripped[:90]))

    if hits:
        print(f"check_no_direct_job_messenger: {len(hits)} controller(s) dispatch "
              f"ExecuteJobMessage directly (jobs hang at 'pending' under the "
              f"default in_request runner):")
        for rel, ln, src in hits:
            print(f"  FAIL {rel}:{ln}  {src}")
        print("\nFix: dispatch through the facade instead —\n"
              "  return $jobDispatcher->dispatch($jobClass, $args, $jobId, "
              "$response, $request->getSession());\n"
              "It honours app.async_job.runner (in_request by default, messenger "
              "when opted in).")
        return 1

    if not args.quiet:
        print("check_no_direct_job_messenger: OK — no controller dispatches "
              "ExecuteJobMessage directly.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
