#!/usr/bin/env python3
"""
check_stored_file_paths.py — Gate 94: a stored file path is never joined onto a directory by hand.

S19 (2026-11): an organisation backup carried '../../.env.local' as AuditFreeze.pdfPath and
'../var/setup_complete.lock' as Tenant.logoPath. The audit-freeze download read any server file,
the next logo upload deleted the lock file (setup wizard open again), the next backup zipped
any file. Stored paths are data; only `App\\Service\\Upload\\StoredFilePath` (logos, avatars,
fixed folders) and `ProtectedUploads` (evidence) turn them into files, inside their folder.

The gate fails when a PHP file in src/ other than those two
  1. concatenates the value of a path getter (getLogoPath(), getProfilePicture(), getPdfPath(),
     getFilePath(), getStoredFilename()) or a variable named like one ($logoPath, $avatarPath,
     $pdfPath, $filePath, $oldLogoPath, $oldAvatarPath) with a string;
  2. joins a variable onto '/public/' other than the fixed upload folder property
     ($this->uploadsDirectory).
Comments do not count. A genuine exception carries `// @stored-path-ok: <reason>` on the line.

Behaviour (every path-like Doctrine column registered, traversal refused for each, restore
drops such values) is pinned by tests/Security/StoredFilePathContractTest.

    python3 scripts/quality/check_stored_file_paths.py
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EXEMPT = {"src/Service/Upload/StoredFilePath.php", "src/Service/Upload/ProtectedUploads.php"}
OPT_OUT = "@stored-path-ok:"

GETTERS = r"get(?:LogoPath|ProfilePicture|PdfPath|FilePath|StoredFilename)\(\)"
JOIN = re.compile(
    r"\.\s*\$[\w>-]*->" + GETTERS
    + r"|->" + GETTERS + r"\s*\.(?!=)"
    + r"|\.\s*\$(?:logoPath|avatarPath|pdfPath|filePath|oldLogoPath|oldAvatarPath)\b"
)
PUBLIC_JOIN = re.compile(r"""['"]/public/['"]\s*\.\s*\$(?!this->uploadsDirectory\b)""")
COMMENT = re.compile(r"/\*.*?\*/|//[^\n]*|#(?!\[)[^\n]*", re.S)


def strip_comments(text: str) -> str:
    return COMMENT.sub(lambda m: re.sub(r"[^\n]", " ", m.group(0)), text)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    offenders: list[str] = []
    checked = 0
    for path in sorted((ROOT / "src").rglob("*.php")):
        rel = path.relative_to(ROOT).as_posix()
        if rel in EXEMPT:
            continue
        checked += 1
        raw = path.read_text(encoding="utf-8").splitlines()
        code = strip_comments("\n".join(raw)).splitlines()
        for no, line in enumerate(code):
            if OPT_OUT in raw[no]:
                continue
            if JOIN.search(line) or PUBLIC_JOIN.search(line):
                offenders.append(f"{rel}:{no + 1}: {raw[no].strip()}")

    if offenders:
        print("Gate 94 (stored file paths) failed — resolve with StoredFilePath / ProtectedUploads:")
        for o in offenders:
            print("  " + o)
        return 1
    if not args.quiet:
        print(f"Gate 94 (stored file paths): OK ({checked} files)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
