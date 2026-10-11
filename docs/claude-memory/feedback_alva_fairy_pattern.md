---
name: Alva-Fee Hilfs-Pattern
description: User schätzt proaktive UI-Hinweise im Stil "Alva-Tipp" wenn das System Datenübernahme/Setup-Vereinfachung anbieten kann.
type: feedback
originSessionId: 51278c80-8f53-4e22-ae77-1c110a277764
---
User-Präferenz: Wenn das System einen Shortcut anbieten kann (Datenübernahme via Mapping, Library-Import, Auto-Setup), proaktiv als unauffällige Info-Card anzeigen — wie eine Fee, die zuflüstert "hier kannst du es dir einfacher machen".

**Why:** Data-Reuse-Prinzip + UX-Sensibilität — User mag "kein UX-Bruch", on-demand statt Modal/Wizard-Schritt. Aurora-Maskottchen Alva als sympathischer Absender macht Hint freundlich statt aufdringlich.

**How to apply:**
- Trigger: System erkennt verfügbaren Setup-Shortcut (Library-Import, Cross-Framework-Inheritance, Bulk-Setup, Dependency-Auto-Detect)
- UI: `_fa_alert` `variant: info`, Title startet mit "Alva-Tipp:", Body knapp 1-2 Sätze + Action-Button(s) inline
- Translation-Keys: `wizard.mapping_library.fairy_*`, `<feature>.fairy_*`
- Visibility-Bedingungen: zeige Card nur wenn (a) Shortcut tatsächlich verfügbar UND (b) User Rechte hat UND (c) noch nicht angewendet
- On-demand: kein automatisches Triggern — User klickt selbst, wird zur Review-Queue/Verification weitergeleitet
- Beispiel: ComplianceWizardController::start() + templates/compliance_wizard/start.html.twig + wizard.{de,en}.yaml `mapping_library.fairy_*`
