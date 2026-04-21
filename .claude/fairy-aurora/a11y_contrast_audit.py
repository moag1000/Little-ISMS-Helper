#!/usr/bin/env python3
"""
FairyAurora A11y-Kontrast-Audit — WCAG 2.2 AA
Runs against the Aurora palette, verifies all critical FG/BG combinations.

Usage: python3 docs/scripts/a11y_contrast_audit.py
Exit code: 0 = alle Kombinationen pass, 1 = mindestens ein FAIL (für CI-Gate)
"""

import sys


def hex_to_rgb(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def rel_lum(rgb):
    def ch(c):
        c = c / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = rgb
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)


def contrast(fg, bg):
    l1 = rel_lum(hex_to_rgb(fg))
    l2 = rel_lum(hex_to_rgb(bg))
    return (max(l1, l2) + 0.05) / (min(l1, l2) + 0.05)


def rate(ratio, large=False):
    threshold_aa = 3.0 if large else 4.5
    threshold_aaa = 4.5 if large else 7.0
    if ratio >= threshold_aaa:
        return 'AAA'
    if ratio >= threshold_aa:
        return 'AA'
    return 'FAIL'


# — Aurora-Palette (muss synchron zu assets/styles/fairy-aurora.css sein) —
LIGHT = {
    'bg': '#f5f6fa', 'surface': '#ffffff', 'surface-2': '#eef0f9',
    'fg': '#1e1b4b', 'fg-2': '#4c4a73', 'fg-3': '#6d6b92',
    'primary': '#0284c7', 'primary-strong': '#0369a1',
    'accent': '#7c3aed', 'accent-strong': '#6d28d9',
    'success': '#059669', 'success-strong': '#047857',
    'warning': '#d97706', 'warning-text': '#b45309',
    'danger': '#dc2626', 'danger-strong': '#b91c1c',
}

DARK = {
    'bg': '#0a0e1a', 'surface': '#141829', 'surface-2': '#1e2139',
    'fg': '#e9eaf5', 'fg-2': '#b9bad4', 'fg-3': '#6d6f99',
    'primary': '#38bdf8', 'accent': '#a78bfa',
    'success': '#34d399', 'warning': '#fbbf24', 'danger': '#f87171',
}

# Critical combinations that MUST pass AA (body-text)
CRITICAL_AA = [
    # (name, fg, bg, large?, palette)
    ('Light fg on bg', 'fg', 'bg', False, LIGHT),
    ('Light fg on surface', 'fg', 'surface', False, LIGHT),
    ('Light fg-2 on bg', 'fg-2', 'bg', False, LIGHT),
    ('Light accent on bg', 'accent', 'bg', False, LIGHT),
    ('Light danger on surface', 'danger', 'surface', False, LIGHT),
    ('Light warning-text on bg', 'warning-text', 'bg', False, LIGHT),
    ('Dark fg on bg', 'fg', 'bg', False, DARK),
    ('Dark fg-2 on bg', 'fg-2', 'bg', False, DARK),
    ('Dark primary on bg', 'primary', 'bg', False, DARK),
    ('Dark accent on bg', 'accent', 'bg', False, DARK),
    ('Dark danger on bg', 'danger', 'bg', False, DARK),
]

# Button-background combinations (white text on colored bg in light, dark text in dark)
CTA = [
    ('Light white on primary-strong', '#ffffff', LIGHT['primary-strong']),
    ('Light white on accent-strong', '#ffffff', LIGHT['accent-strong']),
    ('Light white on success-strong', '#ffffff', LIGHT['success-strong']),
    ('Light white on danger', '#ffffff', LIGHT['danger']),
    ('Dark bg-text on primary', DARK['bg'], DARK['primary']),
    ('Dark bg-text on accent', DARK['bg'], DARK['accent']),
    ('Dark bg-text on danger', DARK['bg'], DARK['danger']),
]


def main():
    failures = []
    print("═" * 70)
    print("FAIRYAURORA A11Y-KONTRAST-AUDIT — WCAG 2.2 AA")
    print("═" * 70)

    print("\n▸ Critical body-text combinations (AA ≥ 4.5 required)")
    for name, fg_key, bg_key, large, pal in CRITICAL_AA:
        fg_hex = pal[fg_key] if not fg_key.startswith('#') else fg_key
        bg_hex = pal[bg_key]
        r = contrast(fg_hex, bg_hex)
        status = rate(r, large)
        mark = '✓' if status != 'FAIL' else '✗'
        print(f"  {mark} {name:<40} {r:>5.2f} {status}")
        if status == 'FAIL':
            failures.append(f"{name}: {r:.2f}")

    print("\n▸ CTA-Button combinations (white/inverse on colored bg)")
    for name, fg_hex, bg_hex in CTA:
        r = contrast(fg_hex, bg_hex)
        status = rate(r)
        mark = '✓' if status != 'FAIL' else '✗'
        print(f"  {mark} {name:<40} {r:>5.2f} {status}")
        if status == 'FAIL':
            failures.append(f"{name}: {r:.2f}")

    print()
    if failures:
        print(f"❌ {len(failures)} kritische Kontrast-FAILS:")
        for f in failures:
            print(f"   - {f}")
        return 1

    print("✅ Alle kritischen Kombinationen bestehen WCAG 2.2 AA.")
    return 0


if __name__ == '__main__':
    sys.exit(main())
