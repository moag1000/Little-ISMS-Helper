"""
Root conftest.py — minimal pytest configuration for Python quality scripts.

Ensures the repo root is on sys.path so that
    from scripts.quality.mapping_audit import metrics
resolves correctly when pytest is run from the repo root.

macOS case-sensitivity fix
--------------------------
On macOS (case-insensitive HFS+), the tests/Quality directory (capital Q,
created by the Symfony PHPUnit suite) and tests/quality are the same physical
directory.  pytest >= 9 uses strict Path.__eq__ for CLI-arg matching which
fails on case-insensitive filesystems when the caller passes a lowercase path
(tests/quality/) but the physical directory was created with capital letters.

We monkeypatch _pytest.pathlib.absolutepath to return the canonical on-disk
case by walking each path component through os.listdir(), so that:

    python3 -m pytest tests/quality/mapping_audit/test_metrics.py -v

resolves to the same Path object that pytest creates when scanning the
filesystem (tests/Quality/).
"""

import os
import pathlib
import sys

# ── sys.path guarantee ──────────────────────────────────────────────────────
_repo_root = pathlib.Path(__file__).parent
if str(_repo_root) not in sys.path:
    sys.path.insert(0, str(_repo_root))


# ── macOS case-normalisation patch ──────────────────────────────────────────
if sys.platform == "darwin":
    def _real_case_path(path: str) -> pathlib.Path:
        """
        Return the path with the actual on-disk case on a case-insensitive
        filesystem (macOS HFS+/APFS) by walking each path component through
        os.listdir() and finding the case-insensitive match.
        """
        p = pathlib.Path(path)
        if not p.exists():
            return p
        parts = p.parts  # ('/', 'Users', ..., 'tests', 'quality', ...)
        result = pathlib.Path(parts[0])
        for part in parts[1:]:
            try:
                entries = os.listdir(result)
            except (NotADirectoryError, PermissionError):
                result = result / part
                continue
            # Find the entry that matches case-insensitively
            match = next(
                (e for e in entries if e.lower() == part.lower()),
                part,  # fallback: keep as-is
            )
            result = result / match
        return result

    import _pytest.pathlib as _pp
    import _pytest.main as _pm

    _orig_absolutepath = _pp.absolutepath

    def _patched_absolutepath(p: "str | os.PathLike[str]") -> pathlib.Path:
        result = _orig_absolutepath(p)
        return _real_case_path(str(result))

    _pp.absolutepath = _patched_absolutepath
    if hasattr(_pm, "absolutepath"):
        _pm.absolutepath = _patched_absolutepath


def pytest_ignore_collect(collection_path: pathlib.Path, config) -> "bool | None":
    """Skip PHP test files to avoid confusing Python's importer."""
    if collection_path.suffix == ".php":
        return True
    return None
