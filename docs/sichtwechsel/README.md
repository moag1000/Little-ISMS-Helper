# Sichtwechsel — Sieben Perspektiven auf dasselbe ISMS

> Ein ISMS sieht für jede Rolle anders aus. Der CISO will eine Heatmap, der Auditor will Evidence-zum-Stichtag, der Junior-Implementer will einen Wizard, der Fachbereichsleiter will eine Ein-Klick-Freigabe, der Tester will sehen ob es WIRKLICH funktioniert. Diese Doku führt durch **Little ISMS Helper aus sieben Blickwinkeln** — mit echten Screenshots, getriggert über die Persona-Skills im Projekt.

---

## Die sieben Perspektiven

| Persona | Was sie/er im Tool sucht | Walkthrough |
|---|---|---|
| **ISB / Security Officer** | Audit-Trail, SoA-Pflege, Risikoregister, Wirksamkeitsmessung | [→ ISB-Sicht](isb-practitioner.md) |
| **CISO / Executive** | Heatmap, Reifegrad-Trend, Vorstandsvorlage in 30 Sekunden | [→ CISO-Sicht](ciso-executive.md) |
| **Compliance-Manager / Head of GRC** | Framework-Reuse, Cross-Mapping, Library-Onboarding | [→ Compliance-Sicht](compliance-manager.md) |
| **Junior-Implementer (neu in InfoSec)** | Setup-Wizard, Empty-States mit CTA, geführte Pfade | [→ Junior-Sicht](implementer-junior.md) |
| **Risk-Owner / Fachbereichsleiter** | Eine-Aufgabe-eine-Entscheidung, Business-Sprache, mobil | [→ Risk-Owner-Sicht](risk-owner-business.md) |
| **Externer Auditor (ISO 19011)** | Stichtag-Snapshot, NC-Detail, Konsistenz Policy↔SoA↔Evidence | [→ Auditor-Sicht](auditor-external.md) |
| **Tool-Tester / QA mit ISMS-Basics** | Reale Umsetzung, i18n-Parität, Aurora-Konformität, Mapping-Qualität | [→ Tester-Sicht](tool-tester.md) |

---

## Warum dieser Aufbau?

Das Projekt pflegt acht Persona-Definitionen unter [`.claude/skills/persona-*`](../../.claude/skills/) — versionierte Rollen-Briefings für Realismus in Reviews und Designentscheidungen. Sieben davon haben eigene Tool-Sichten (der `persona-consultant-senior` ist ein abstrakter Reviewer und arbeitet auf dieselben Screens wie ISB/CISO). Diese Doku spiegelt die sieben UI-Personas 1:1 in echten Screens. Das hat drei Effekte:

1. **Feature-Diskussionen werden konkret**: "Hier ist, was der CISO sieht — und was er vermisst." statt abstrakter UX-Behauptungen.
2. **Nutzer-Marketing ohne Stockfotos**: Statt generischen Compliance-Bildern zeigt das README den echten ISO-27001-Workflow aus Rollen-Sicht.
3. **Audit-Stoff in 60 Sekunden**: Externer Prüfer sieht im [Auditor-Walkthrough](auditor-external.md) sofort, ob Tool seine Arbeit unterstützt.

---

## Wie die Screenshots entstehen

Vollautomatisiert via [`scripts/screenshots/capture.mjs`](../../scripts/screenshots/capture.mjs) — Playwright + Persona-Catalog ([`personas.yaml`](../../scripts/screenshots/personas.yaml)).

**Re-Generierung:**

```bash
# 1. Demo-User seeden (idempotent, nur dev/test)
php bin/console app:create-screenshot-user

# 2. Symfony-Server starten
symfony server:start --daemon --port=8000

# 3. Screenshots erzeugen — 7 Personas × 2 Themes (light+dark) ≈ 290 PNGs
#    Demo-User braucht Tenant mit Sample-Daten — sonst Empty-States überall:
#       php bin/console app:create-screenshot-user --tenant-code=default
SCREENSHOT_USER='screenshots@local.test' \
SCREENSHOT_PASS='Screenshots-Aurora-2026!' \
npm run screenshots

# Filter (optional):
node scripts/screenshots/capture.mjs --persona=isb-practitioner --theme=dark
```

Output landet unter `var/screenshots/<persona>/<theme>/<screen>.png` (gitignored). Diese Doku zeigt eine kuratierte Auswahl, light-Theme, optimiert auf 1200px Breite.

---

## Was nicht abgedeckt ist

- **Holding/Konzern-Struktur** — eigene Persona-Skill `persona-konzern-ciso` wäre sinnvoll, kommt nach.
- **DPO / Datenschutzbeauftragter** — separate Doku-Linie unter `dpo-specialist`-Skill, nicht hier.
- **Senior-Consultant** — `persona-consultant-senior` ist Review-/Vergleichsrolle ohne eigene Tool-Sicht; nutzt CISO- und Compliance-Manager-Walkthroughs als Baseline.
- **Mobile-Viewports** — aktuell Desktop 1440×900; Mobile-Capture ist YAML-Erweiterung weg.
- **EN-Locale (gesamt)** — DE-Screens reichen für jetzige Nutzergruppe; EN-Smoke-Tests sind im Tool-Tester-Walkthrough verlinkt. Vollständige EN-Erweiterung trivial via `themes`-analoger `locales`-Liste.

---

## Auch interessant

- [Junior-Implementer-Walkthrough (textuell, vor Sichtwechsel)](../JUNIOR_IMPLEMENTER_WALKTHROUGH.md)
- [Compliance-Frameworks-Guide](../COMPLIANCE_FRAMEWORKS_GUIDE.md)
- [Architektur-README](../../README.md)
