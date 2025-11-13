# Quick Start Guide - Corporate Structure Management

## 🚀 In 5 Minuten zur ersten Konzernstruktur

### Schritt 1: Migration ausführen (einmalig)

```bash
php bin/console doctrine:migrations:migrate
```

✅ Tabelle `corporate_governance` wurde erstellt

---

### Schritt 2: Tenants anlegen

Erstelle mindestens 2 Tenants:

1. Gehe zu **Admin → Mandanten** → **Neuer Mandant**
2. Erstelle "Parent Corp" (z.B. Code: `PARENT`)
3. Erstelle "Subsidiary A" (z.B. Code: `SUBA`)

---

### Schritt 3: Konzernstruktur aufbauen

1. Gehe zu **Admin → Konzernstrukturen**
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

```bash
# Via Browser
http://localhost/admin/tenants/corporate-structure
```

**Erwartung:** Subsidiary erscheint eingerückt unter Parent

---

### Test 2: API-Test

```bash
# Hole Governance-Rules
curl http://localhost/api/corporate-structure/2/governance

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
curl http://localhost/api/corporate-structure/effective-context/2

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

### Tipp 1: Multi-Tenant-Check nutzen

Bei nur 1 Mandant wird das Konzernstruktur-Menü automatisch ausgeblendet.

**Aktiviere Second-Tenant:**
```sql
UPDATE tenant SET is_active = 1 WHERE id = 2;
```

→ Menü erscheint automatisch

---

### Tipp 2: Schnelles Testen mit cURL

```bash
# Set Parent via API
curl -X POST http://localhost/api/corporate-structure/set-parent \
  -H "Content-Type: application/json" \
  -d '{
    "tenantId": 2,
    "parentId": 1,
    "governanceModel": "hierarchical"
  }'
```

---

### Tipp 3: Bulk-Import via SQL

```sql
-- Erstelle 10 Subsidiaries unter Parent 1
INSERT INTO corporate_governance (tenant_id, parent_id, scope, scope_id, governance_model, created_at)
SELECT
    t.id,
    1 as parent_id,
    'default' as scope,
    NULL as scope_id,
    'shared' as governance_model,
    NOW() as created_at
FROM tenant t
WHERE t.id BETWEEN 2 AND 11;

-- Setze Parent-Beziehung
UPDATE tenant
SET parent_id = 1
WHERE id BETWEEN 2 AND 11;
```

---

### Tipp 4: Debugging mit Browser-Console

```javascript
// In Browser-Console (F12):
// Teste loadGovernanceRules()
loadGovernanceRules();

// Teste saveGovernanceRule()
document.getElementById('ruleScope').value = 'control';
document.getElementById('ruleScopeId').value = 'A.5.1';
document.getElementById('ruleGovernance').value = 'hierarchical';
saveGovernanceRule();
```

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
- **Migration Guide:** [MIGRATION_GUIDE.md](./MIGRATION_GUIDE.md)
- **API-Referenz:** [CORPORATE_STRUCTURE.md#api-endpoints](./CORPORATE_STRUCTURE.md#api-endpoints)

---

## 🆘 Hilfe benötigt?

**Logs prüfen:**
```bash
tail -f var/log/dev.log | grep -i corporate
```

**Debug-Modus aktivieren:**
```yaml
# config/packages/monolog.yaml
monolog:
    channels: ['corporate']
    handlers:
        corporate:
            type: stream
            path: "%kernel.logs_dir%/corporate.log"
            level: debug
            channels: ["corporate"]
```

**Community:**
- GitHub Issues: [https://github.com/your-repo/issues](https://github.com/your-repo/issues)
- Slack Channel: #isms-helper-support

---

**Happy Corporate Structuring! 🎉**

**Version:** 1.0.0
**Letzte Aktualisierung:** 2025-01-13
