"""Thin IO for the EU mapping audit. Stdlib only (csv, json, os)."""
import csv
import json
import os


def read_mapping_csv(path):
    """Read a public mapping CSV, skipping leading '#' comment lines."""
    with open(path, encoding="utf-8") as fh:
        lines = [ln for ln in fh if not ln.lstrip().startswith("#")]
    reader = csv.DictReader(lines)
    return [dict(row) for row in reader]


def _strip_inline_comment(value):
    """Remove an inline '# ...' comment. Requirement IDs never contain '#'."""
    return value.split("#", 1)[0].strip()


def read_catalog_manifest(path):
    """Parse the tiny manifest dialect: 'Framework:' blocks with 'source:' and
    'requirements:' (a '- item' list or '[]'). Strips inline '# ...' comments.
    No PyYAML dependency."""
    catalog = {}
    current = None
    in_reqs = False
    with open(path, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.rstrip("\n")
            if not line.strip() or line.lstrip().startswith("#"):
                continue
            if not line.startswith(" "):  # top-level "Framework:"
                current = line.rstrip(":").strip()
                catalog[current] = {"source": "", "requirements": []}
                in_reqs = False
            elif line.strip().startswith("source:"):
                catalog[current]["source"] = _strip_inline_comment(line.split(":", 1)[1])
                in_reqs = False
            elif line.strip().startswith("requirements:"):
                rest = _strip_inline_comment(line.split(":", 1)[1])
                in_reqs = rest != "[]"
            elif in_reqs and line.strip().startswith("- "):
                catalog[current]["requirements"].append(_strip_inline_comment(line.strip()[2:]))
    return catalog


def write_dossier(path, data):
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, ensure_ascii=False, indent=2)
