# Quick Start Guide - Corporate Structure Management

## 🚀 In 5 Minuten zur ersten Konzernstruktur

Voraussetzung: Schritte 2 und 3 macht ein **Instanz-Betreiber** (`ROLE_SUPER_ADMIN`); Governance-Regeln
und Kontext-Verteilung (Schritte 4 und 5) auch der `ROLE_ADMIN` der Muttergesellschaft.

### Schritt 1: Datenbank aktuell (einmalig)

Ausstehende Migrationen über `/quick-fix` oder `php bin/console doctrine:migrations:migrate` anwenden
(legt u. a. die Tabelle `corporate_governance` an).

---

### Schritt 2: Tenants anlegen

Erstelle mindestens 2 Tenants:

1. Gehe zu **Admin → Mandanten** (`/de/admin/tenants`) → **Neuer Mandant**
2. Erstelle "Parent Corp" (z.B. Code: `PARENT`)
3. Erstelle "Subsidiary A" (z.B. Code: `SUBA`)

---

### Schritt 3: Konzernstruktur aufbauen

1. Gehe zu **Admin → Konzernstruktur** (`/de/admin/tenants/corporate-structure`)
2. Bei "Subsidiary A" klicke **"Muttergesellschaft zuweisen"**
3. Wähle:
   - **Muttergesellschaft:** Parent Corp
   - **Governance-Modell:** Hierarchisch
4. Klicke **Speichern**

✅ Subsidiary A erscheint jetzt unter Parent Corp in der Hierarchie

---

### Schritt 4: Granulare Governance konfigurieren

1. Öffne Detail-Seite von "Subsidiary A"
2. Scrolle zu **"Granulare Governance-Regeln"**
3. Klicke **"Regel hinzufügen"**
4. Konfiguriere:
   - **Bereich:** Control
   - **Bereichs-ID:** A.5.1
   - **Governance-Modell:** Hierarchisch
5. Klicke **Speichern**

✅ Control A.5.1 wird jetzt von Parent Corp gesteuert

---

### Schritt 5: ISMS-Kontext vererben

1. Als **Parent Corp**: Gehe zu **ISMS → Kontext** und definiere Organisation
2. Zurück zu Parent Corp Detail-Seite
3. Im Bereich **"ISMS-Kontext & Organisation"** klicke **"An Tochtergesellschaften verteilen"**
4. Bestätige

✅ Subsidiary A hat jetzt denselben ISMS-Kontext wie Parent Corp

---

## 🎯 Use Cases

### Use Case 1: Zentral verwalteter Konzern

**Szenario:** Alle Tochtergesellschaften folgen zu 100% der Muttergesellschaft

**Setup:**
```
Parent Corp
├─ Subsidiary A (Hierarchisch)
└─ Subsidiary B (Hierarchisch)
```

**Ergebnis:**
- ISMS-Kontext wird von Parent geerbt
- Alle Controls folgen Parent-Richtlinien
- Zentrale Policies gelten für alle

---

### Use Case 2: Föderierte Struktur

**Szenario:** Rahmen durch Muttergesellschaft, eigene Implementierung pro Tochter

**Setup:**
```
Holding AG
├─ IT GmbH (Geteilt)
├─ Consulting GmbH (Geteilt)
└─ Support GmbH (Geteilt)
```

**Ergebnis:**
- Jede Tochter hat eigenen ISMS-Kontext
- Parent gibt Mindeststandards vor
- Lokale Anpassungen möglich

---

### Use Case 3: Rechtlich getrennte Einheiten

**Szenario:** Organisatorische Zugehörigkeit ohne ISMS-Abhängigkeit

**Setup:**
```
Group Parent
├─ Company A (Unabhängig)
└─ Company B (Unabhängig)
```

**Ergebnis:**
- Kein Zugriff auf Parent-Ressourcen
- Vollständig autonome ISMS-Verwaltung
- Nur Reporting zur Holding

---

### Use Case 4: Granulare Control-Governance

**Szenario:** Kritische Controls zentral, andere lokal

**Setup:**

Subsidiary hat Default-Governance: **Geteilt**

**Aber spezielle Rules:**
- Control A.5.1 (Access Control): **Hierarchisch** ← Von Parent
- Control A.8.1 (Asset Management): **Hierarchisch** ← Von Parent
- Control A.12.1 (Operations): **Geteilt** ← Default gilt
- Control A.14.1 (Acquisition): **Unabhängig** ← Spezielle Rule

**Ergebnis:**
- Security-kritische Controls zentral gesteuert
- Operations kann Subsidiary selbst bestimmen
- Einkauf vollständig autonom

---

## 🔍 Testen

### Test 1: Hierarchie funktioniert

Im Browser: `http://localhost/de/admin/tenants/corporate-structure`

**Erwartung:** Subsidiary erscheint eingerückt unter Parent

---

### Test 2: API-Test

Die JSON-Endpunkte brauchen eine angemeldete Browser-Sitzung (`ROLE_ADMIN`) und tragen das Locale-Präfix.

```bash
# Hole Governance-Rules
curl -b "isms_session=<cookie>" http://localhost/de/api/corporate-structure/2/governance

# Erwartete Response:
{
  "tenant": {"id": 2, "name": "Subsidiary A"},
  "rules": [
    {
      "scope": "control",
      "scopeId": "A.5.1",
      "governanceModel": "hierarchical"
    }
  ]
}
```

---

### Test 3: ISMS-Kontext-Vererbung

```bash
# Hole effektiven Kontext
curl -b "isms_session=<cookie>" http://localhost/de/api/corporate-structure/effective-context/2

# Erwartete Response bei Hierarchical:
{
  "context": {
    "organizationName": "Parent Corp",
    "isInherited": true,
    "inheritedFrom": {"id": 1, "name": "Parent Corp"}
  }
}
```

---

## ⚡ Tipps & Tricks

### Tipp 1: Multi-Tenant-Check

Bei nur einem aktiven Mandanten zeigt die Konzernstruktur-Seite einen Hinweis. Zweiten Mandanten unter
**Admin → Mandanten** aktivieren (Aktivieren/Deaktivieren, `ROLE_SUPER_ADMIN`).

---

### Tipp 2: Schreibende API-Aufrufe

`POST`/`PATCH`/`DELETE` auf `/de/api/corporate-structure/…` brauchen zusätzlich den CSRF-Token
`corporate_structure` im Header `X-CSRF-Token` — praktisch also die UI. Struktur nicht per SQL ändern:
das umgeht Validierung (Zyklen) und Audit-Log.

---

### Tipp 3: Debugging

Die Governance-Regeln auf der Mandanten-Detailseite lädt der Stimulus-Controller `tenant-governance`
(`assets/controllers/tenant-governance_controller.js`); Fehler stehen im Network-Tab (F12).

---

## 🐛 Häufige Fehler

### Fehler: "Valid governance model is required"

**Ursache:** Dropdown nicht ausgewählt oder ungültiger Wert

**Lösung:**
- Governance-Modell muss genau sein: `hierarchical`, `shared`, oder `independent`
- Keine Leerzeichen, case-sensitive!

---

### Fehler: "Tenant must have a parent to set governance"

**Ursache:** Versuch, Governance für Standalone-Tenant zu setzen

**Lösung:**
- Zuerst Parent zuweisen
- Dann granulare Rules hinzufügen

---

### Fehler: "Circular reference detected"

**Ursache:** Tenant soll eigener Parent werden

**Lösung:**
```
❌ Tenant A → Parent: Tenant A (nicht erlaubt)
✅ Tenant A → Parent: Tenant B (erlaubt)
```

---

## 📚 Weiterführende Dokumentation

- **Vollständige Dokumentation:** [CORPORATE_STRUCTURE.md](./CORPORATE_STRUCTURE.md)
- **Rechte und Mandantentrennung:** [admin/TENANT_SYSTEM.md](./admin/TENANT_SYSTEM.md)
- **API-Referenz:** [CORPORATE_STRUCTURE.md#api-endpoints](./CORPORATE_STRUCTURE.md#api-endpoints)

---

## 🆘 Hilfe benötigt?

```bash
tail -f var/log/dev.log | grep -i corporate
```

GitHub Issues: <https://github.com/moag1000/Little-ISMS-Helper/issues>
