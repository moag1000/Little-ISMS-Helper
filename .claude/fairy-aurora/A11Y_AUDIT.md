# FairyAurora v3.0 — A11y-Kontrast-Baseline (WCAG 2.2 AA)

**Gemessen:** 2026-04-21 · **Phase:** FA-1 Theme-Foundation
**Methode:** WCAG 2.x relative luminance formula, automated Python-Script

## WCAG 2.2 AA Schwellen

- **Normal text** (< 18pt / < 14pt bold): ≥ 4.5 : 1
- **Large text** (≥ 18pt / ≥ 14pt bold): ≥ 3.0 : 1
- **UI-Components + Graphical**: ≥ 3.0 : 1

## Light Mode — Kontrast-Matrix

| Foreground \\ Background | `--bg` #f5f6fa | `--surface` #fff | `--surface-2` #eef0f9 |
|--------------------------|----------------|-------------------|------------------------|
| **`--fg`** #1e1b4b | 14.80 AAA | 15.99 AAA | 14.06 AAA |
| **`--fg-2`** #4c4a73 | 7.67 AAA | 8.29 AAA | 7.29 AAA |
| **`--fg-3`** #6d6b92 | 4.66 AA | 5.04 AA | **4.43 AA-Large** |
| **`--primary`** #0284c7 | 3.79 AA-Large | 4.10 AA-Large | 3.60 AA-Large |
| **`--accent`** #7c3aed | 5.28 AA | 5.70 AA | 5.01 AA |
| **`--success`** #059669 | 3.49 AA-Large | 3.77 AA-Large | 3.31 AA-Large |
| **`--warning`** #d97706 | ⚠ 2.95 FAIL | 3.19 AA-Large | ⚠ 2.80 FAIL |
| **`--danger`** #dc2626 | 4.47 AA-Large | 4.83 AA | 4.25 AA-Large |

### CTA-Button-Textkontrast (Light)

| Text \\ Button-Background | Contrast | Status |
|---------------------------|----------|--------|
| `#ffffff` auf `--primary` #0284c7 | 4.10 | AA-Large |
| `#ffffff` auf **`--primary-strong`** #0369a1 | **5.93** | **AA ✓** |
| `#ffffff` auf `--accent` #7c3aed | 5.70 | AA ✓ |
| `#ffffff` auf **`--accent-strong`** #6d28d9 | **7.21** | AAA ✓ |
| `#ffffff` auf `--success` #059669 | 3.77 | AA-Large |
| `#ffffff` auf **`--success-strong`** #047857 | **5.48** | AA ✓ |
| `#ffffff` auf `--danger` #dc2626 | 4.83 | AA ✓ |
| `#ffffff` auf **`--danger-strong`** #b91c1c | **6.47** | AA ✓ |

## Dark Mode — Kontrast-Matrix

| Foreground \\ Background | `--bg` #0a0e1a | `--surface` #141829 | `--surface-2` #1e2139 |
|--------------------------|----------------|----------------------|------------------------|
| **`--fg`** #e9eaf5 | 16.09 AAA | 14.72 AAA | 13.18 AAA |
| **`--fg-2`** #b9bad4 | 10.12 AAA | 9.26 AAA | 8.29 AAA |
| **`--fg-3`** #6d6f99 | 4.02 AA-Large | 3.68 AA-Large | 3.29 AA-Large |
| **`--primary`** #38bdf8 | 8.99 AAA | 8.22 AAA | 7.36 AAA |
| **`--accent`** #a78bfa | 7.08 AAA | 6.47 AA | 5.79 AA |
| **`--success`** #34d399 | 10.02 AAA | 9.16 AAA | 8.20 AAA |
| **`--warning`** #fbbf24 | 11.53 AAA | 10.55 AAA | 9.45 AAA |
| **`--danger`** #f87171 | 6.96 AA | 6.37 AA | 5.70 AA |

### Dark-CTA-Button-Textkontrast

Hell-auf-dunkel → Inverse-Text-Policy: Button-bg ist bright, Button-text
ist dark. Über `--on-*`-Tokens (`#0a0e1a` in Dark).

| Text \\ Button-Background | Contrast | Status |
|---------------------------|----------|--------|
| `#0a0e1a` auf `--primary` #38bdf8 | **8.99** | AAA ✓ |
| `#0a0e1a` auf `--accent` #a78bfa | **7.08** | AAA ✓ |
| `#0a0e1a` auf `--danger` #f87171 | **6.96** | AA ✓ |
| `#0a0e1a` auf `--warning` #fbbf24 | **11.53** | AAA ✓ |

## Token-Regeln (verbindlich)

### Farb-Nutzung nach Kontext

| Zweck | Light-Token | Dark-Token | Begründung |
|-------|-------------|------------|------------|
| Button-BG (Primary, Text weiß) | `--primary-strong` | `--primary` | AA 5.93 / AAA 8.99 |
| Button-BG (Accent, Text weiß) | `--accent-strong` | `--accent` | AAA 7.21 / AAA 7.08 |
| Button-BG (Danger, Text weiß) | `--danger` ODER `--danger-strong` | `--danger` | AA 4.83+ |
| Button-Text-Farbe | `--on-primary` (white) | `--on-primary` (dark) | per Token gelöst |
| Icon/Border auf bg | `--primary` | `--primary` | AA-Large reicht für Icons |
| Warning-Text (body) | `--warning-text` #b45309 | `--warning` | AA 4.65 / AAA 11.53 |
| Warning-Badge/Icon | `--warning` | `--warning` | non-text OK |
| Metadata (Mono-Label) | `--fg-3` | `--fg-2` | AA auf surface-Ebene |

### „Dos"

- **Immer** `--on-primary` / `--on-accent` etc. für Text auf Buttons — löst Light/Dark-Flip automatisch
- **Für Body-Text** mit Warning-Farbe: `--warning-text` (Light darker)
- **Button-BG** mit `--primary-strong` → AA-safe
- **`--fg-3` auf `--surface-2`** nur für Metadata oder sekundäre Text-Rolle (grenzt AA-Large)

### „Don'ts"

- ❌ `--primary` als Button-BG mit weißem Text in Light-Mode → nur AA-Large (3.79)
- ❌ `--warning` als Body-Text auf `--bg` in Light-Mode → 2.95 FAIL
- ❌ `--fg-3` als Primary-Body-Text → grenzt AA
- ❌ Harte `color: #ffffff` auf `--primary` ohne Token-Layer — bricht in Dark

## Focus-Ring-Spec

- `outline: 3px solid var(--primary)` + `outline-offset: 2px` + `box-shadow: 0 0 0 3px var(--primary-glow)`
- Gilt für alle interaktiven Elemente (Button, Input, Link, Checkbox, Toggle, Tab)
- In Komponente `.fa-focus-ring:focus-visible` bereits hinterlegt

## Re-Audit-Runner

```bash
python3 .claude/fairy-aurora/a11y_contrast_audit.py
```

Exit-Code 0 = alle Kombinationen pass, 1 = mindestens ein FAIL (CI-Gate-kompatibel).

## Bekannte Grenzen

- `--primary` alone for icons on surfaces = AA-Large (3.6-4.10). Akzeptiert für **ikonografische** Nutzung (Icons, Sparkline-Linien, Borders), wo 3:1 ausreicht.
- Chart.js-Farb-Serien (§ 16) folgen Aurora — Pattern-Safe-Mode für Color-Deficit-User.
- Dark-Mode `--fg-3` auf Surfaces = AA-Large. Nur für Metadaten geeignet, nicht für Primary-Body-Text.

## Retest-Cadence

- Nach jeder Palette-Änderung → Python-Audit neu laufen lassen
- Bei Component-Launch (FA-2 .. FA-10) → Screenshot-Vergleich + Axe-DevTools
- Quartalsweise Full-Audit (manuell + Axe-Browser-Extension) durch ISB
