"""Self-test for Gate 103 (W11 accessibility: markup rules + token contrast).
Run: python3 -m unittest discover -s scripts/quality/tests"""
import importlib.util
import tempfile
import unittest
from pathlib import Path

QUALITY = Path(__file__).resolve().parents[1]


def load(name):
    spec = importlib.util.spec_from_file_location(name, QUALITY / f"{name}.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


a11y = load("check_a11y_static")
contrast = load("check_token_contrast")


def rules(src, name="page.html.twig"):
    with tempfile.TemporaryDirectory() as d:
        f = Path(d) / name
        f.write_text(src, encoding="utf-8")
        return [p.split("[")[1].split("]")[0] for p in a11y.check_file(f)]


class A11yMarkupGateTest(unittest.TestCase):
    def test_th_without_scope(self):
        self.assertEqual(rules("<table><tr><th>A</th></tr></table>"), ["th-scope"])
        self.assertEqual(rules('<table><tr><th scope="col">A</th></tr></table>'), [])

    def test_canvas_needs_role_and_label(self):
        self.assertEqual(rules('<canvas id="x"></canvas>'), ["canvas"])
        self.assertEqual(rules('<canvas aria-label="Risiken"></canvas>'), ["canvas"])
        self.assertEqual(rules('<canvas role="img" aria-label="{{ t }}"></canvas>'), [])
        self.assertEqual(rules('<canvas data-controller="chart" data-chart-label-value="{{ t }}"></canvas>'), [])

    def test_icon_only_button(self):
        self.assertEqual(rules('<button type="submit"><i class="fa-icon fa-icon--ui-trash"></i></button>'), ["icon-only"])
        self.assertEqual(rules('<a href="x"><i class="fa-icon" aria-hidden="true"></i></a>'), ["icon-only"])
        self.assertEqual(rules('<button aria-label="{{ l }}"><i class="fa-icon"></i></button>'), [])
        self.assertEqual(rules('<a href="x"><i class="fa-icon"></i> {{ label }}</a>'), [])
        self.assertEqual(rules('<a href="x"><i class="fa-icon"></i><span class="visually-hidden">X</span></a>'), [])
        self.assertEqual(rules('<button {% if a > b %}disabled{% endif %}><i class="fa-icon"></i></button>'),
                         ["icon-only"])

    def test_banner_only_in_base(self):
        self.assertEqual(rules('<header role="banner">x</header>'), ["banner"])
        self.assertEqual(rules('<header role="banner">x</header>', "base.html.twig"), [])

    def test_click_on_div(self):
        self.assertEqual(rules('<div data-action="click->toggle#toggleContent">x</div>'), ["click"])
        self.assertEqual(rules('<div role="button" tabindex="0" data-action="click->toggle#toggleContent">x</div>'), [])
        self.assertEqual(rules('<div class="fa-modal__backdrop" data-action="click->fa-modal#backdropClick"></div>'), [])
        self.assertEqual(rules('{# a11y-ok: row has a link #}\n<tr data-action="click->m#select">x</tr>'), [])


class TokenContrastGateTest(unittest.TestCase):
    def test_ratio_math(self):
        black, white = (0, 0, 0, 1.0), (255, 255, 255, 1.0)
        self.assertAlmostEqual(contrast.ratio(black, white), 21.0, places=1)
        grey = contrast.parse_color("#777777", {})
        self.assertAlmostEqual(contrast.ratio(grey, white), 4.48, places=2)

    def test_alias_and_alpha_resolve(self):
        tokens = {"--a": "#ffffff", "--b": "var(--a)", "--t": "rgba(0, 0, 0, 0.5)"}
        self.assertEqual(contrast.parse_color("var(--b)", tokens), (255, 255, 255, 1.0))
        mixed = contrast.over(contrast.parse_color("var(--t)", tokens), (255, 255, 255, 1.0))
        self.assertAlmostEqual(mixed[0], 127.5)

    def test_repository_tokens_pass(self):
        problems, table = contrast.check()
        self.assertEqual(problems, [])
        self.assertGreater(len(table), 100)


if __name__ == "__main__":
    unittest.main()
