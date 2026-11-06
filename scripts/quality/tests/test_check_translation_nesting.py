"""Self-test for Gate 26 collision detection and Gate 76 masking scanner."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

Q = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, Q / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


nesting = load("check_translation_nesting")
masking = load("check_trans_default_masking")


class CollisionTest(unittest.TestCase):
    def collisions(self, body):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "x.de.yaml"
            p.write_text(body, encoding="utf-8")
            return nesting.find_collisions(p)

    def test_nested_and_flat_same_key(self):
        self.assertEqual(self.collisions("a:\n    b: 1\n'a.b': 2\n"), [(2, "a.b")])

    def test_flat_inside_nested(self):
        self.assertEqual(len(self.collisions("a:\n    b: 1\n    b: 2\n")), 1)

    def test_scalar_next_to_subtree_is_fine(self):
        self.assertEqual(self.collisions("a:\n    title: T\n    title.edit: E\n"), [])


class MaskingScannerTest(unittest.TestCase):
    def test_finds_literal_key_with_default(self):
        got = masking.find_masked("{{ 'a.b'|trans({'%x%': f(1)}, 'tenant')|default('Hi') }}")
        self.assertEqual(got, [(1, "a.b", "tenant", "Hi")])

    def test_ignores_dynamic_key_and_missing_default(self):
        self.assertEqual(masking.find_masked("{{ ('a.' ~ k)|trans({}, 'tenant')|default('x') }}"), [])
        self.assertEqual(masking.find_masked("{{ 'a.b'|trans({}, 'tenant') }}"), [])


if __name__ == "__main__":
    unittest.main()
