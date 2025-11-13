# Corporate Structure Management - Vollständige Dokumentation

## 📋 Inhaltsverzeichnis
1. [Überblick](#überblick)
2. [Features](#features)
3. [Installation & Setup](#installation--setup)
4. [Governance-Modelle](#governance-modelle)
5. [API-Endpoints](#api-endpoints)
6. [Frontend-Funktionen](#frontend-funktionen)
7. [Datenbank-Schema](#datenbank-schema)
8. [Troubleshooting](#troubleshooting)
9. [Testing](#testing)

---

## Überblick

Das Corporate Structure Management ermöglicht die Verwaltung von **hierarchischen Konzernstrukturen** mit Muttergesellschaften und Tochtergesellschaften. Es unterstützt **granulare Governance-Modelle** auf verschiedenen Ebenen (Global, Control, Risiko, Asset, etc.).

### Kernfunktionalitäten

1. **Hierarchische Strukturen**: Unbegrenzte Verschachtelung von Parent-Child-Beziehungen
2. **Granulare Governance**: Pro Scope (z.B. pro Control) individuelle Governance-Regeln
3. **ISMS-Kontext-Vererbung**: Automatische Propagierung von ISMS-Kontexten
4. **Multi-Tenant-Check**: Automatisches Ausblenden wenn nur 1 Mandant existiert

---

## Features

### ✅ Feature 1: Multi-Tenant-Check
**Commit:** `f3dea58`

- Prüft ob mehrere aktive Mandanten existieren
- Blendet Corporate-Structure-Menü bei nur 1 Mandant aus
- Zeigt Info-Alert auf Corporate-Structure-Seite

**Betroffene Dateien:**
- `src/Service/MultiTenantCheckService.php`
- `src/Twig/MultiTenantExtension.php`
- `templates/admin/layout.html.twig`
- `templates/admin/tenants/corporate_structure.html.twig`

### ✅ Feature 2: Granulare Governance
**Commits:** `6254d96` (API), `e447ebc` (UI)

- **Scopes**: `default`, `control`, `isms_context`, `risk`, `asset`, `process`
- **Pro Scope**: Individuelles Governance-Modell definierbar
- **Fallback-Chain**: ScopeID → Scope → Default

**API-Endpoints:**
```
GET    /api/corporate-structure/{tenantId}/governance
POST   /api/corporate-structure/{tenantId}/governance/{scope}
DELETE /api/corporate-structure/{tenantId}/governance/{scope}/{scopeId}
```

**Betroffene Dateien:**
- `src/Entity/CorporateGovernance.php`
- `src/Repository/CorporateGovernanceRepository.php`
- `src/Controller/CorporateStructureController.php`
- `templates/admin/tenants/show.html.twig`

### ✅ Feature 3: ISMS-Kontext-Vererbung
**Commit:** `a0eab59`

- Anzeige des effektiven ISMS-Kontexts (eigen vs. geerbt)
- Propagierungs-Funktion für Muttergesellschaften
- Automatische Vererbung bei hierarchischem Governance-Modell

**API-Endpoints:**
```
GET  /api/corporate-structure/effective-context/{id}
POST /api/corporate-structure/propagate-context/{id}
```

---

## Installation & Setup

### 1. Migration ausführen

```bash
php bin/console doctrine:migrations:migrate
```

**Was passiert:**
- Tabelle `corporate_governance` wird erstellt
- Bestehende `governance_model` Daten werden migriert
- Foreign Keys werden gesetzt (CASCADE DELETE)

### 2. Services prüfen

Alle Services werden automatisch registriert via Symfony's `autoconfigure: true`.

**Überprüfen:**
```bash
php bin/console debug:container MultiTenantCheckService
php bin/console debug:container CorporateStructureService
```

### 3. Routes testen

```bash
php bin/console debug:router | grep corporate
```

**Erwartete Routen:**
- `tenant_management_corporate_structure` (GET)
- `tenant_management_set_parent` (POST)
- `tenant_management_update_governance` (POST)
- `api_corporate_structure_*` (10 API-Routen)

---

## Governance-Modelle

### 1. Hierarchical (Hierarchisch)

**Beschreibung:** Muttergesellschaft hat 100% Kontrolle

**Verhalten:**
- ISMS-Kontext wird vom Parent geerbt
- Tochtergesellschaft folgt allen Parent-Richtlinien
- Keine lokale Autonomie

**Use Case:** Zentral verwaltete Konzerne

### 2. Shared (Geteilt)

**Beschreibung:** Geteilte Verantwortung

**Verhalten:**
- Jede Tochter kann eigenen ISMS-Kontext haben
- Parent gibt Rahmen vor
- Tochter implementiert im eigenen Kontext

**Use Case:** Föderierte Strukturen

### 3. Independent (Unabhängig)

**Beschreibung:** Vollständige Autonomie

**Verhalten:**
- Kein Zugriff auf Parent-Ressourcen
- Eigenes ISMS-Management
- Nur organisatorische Zugehörigkeit

**Use Case:** Rechtlich getrennte Einheiten

---

## API-Endpoints

### Corporate Structure Tree
```http
GET /api/corporate-structure/tree/{id}
```

**Response:**
```json
{
  "id": 1,
  "name": "Acme Corp",
  "governanceModel": "hierarchical",
  "children": [...]
}
```

### Granular Governance - GET
```http
GET /api/corporate-structure/{tenantId}/governance
```

**Response:**
```json
{
  "tenant": { "id": 2, "name": "Subsidiary A" },
  "rules": [
    {
      "id": 1,
      "scope": "control",
      "scopeId": "A.5.1",
      "governanceModel": "hierarchical",
      "governanceLabel": "Hierarchisch (100% Muttergesellschaft)",
      "notes": "Kritisches Control",
      "parent": { "id": 1, "name": "Parent Corp" },
      "createdAt": "2025-01-13 10:00:00"
    }
  ],
  "total": 1
}
```

### Granular Governance - POST
```http
POST /api/corporate-structure/{tenantId}/governance/{scope}
Content-Type: application/json

{
  "scopeId": "A.5.1",
  "governanceModel": "hierarchical",
  "notes": "Optional notes"
}
```

### Granular Governance - DELETE
```http
DELETE /api/corporate-structure/{tenantId}/governance/{scope}/{scopeId}
```

**⚠️ Wichtig:** Default-Governance kann NICHT gelöscht werden!

### Effective ISMS Context
```http
GET /api/corporate-structure/effective-context/{id}
```

**Response:**
```json
{
  "context": {
    "id": 5,
    "organizationName": "Acme Corp",
    "tenant": { "id": 1, "name": "Acme Corp" },
    "isInherited": false,
    "inheritedFrom": null
  }
}
```

### Propagate Context
```http
POST /api/corporate-structure/propagate-context/{id}
```

**Response:**
```json
{
  "success": true,
  "updatedCount": 3,
  "message": "ISMS context propagated to 3 subsidiary(ies)"
}
```

---

## Frontend-Funktionen

### Corporate Structure Seite

**Route:** `/admin/tenants/corporate-structure`

**Features:**
- Übersicht aller Konzerngruppen
- Statistiken (Gruppen, Standalone, Gesamt)
- Hierarchische Darstellung mit Indentation
- Set-Parent-Modal
- Update-Governance-Modal

### Tenant Detail Seite

**Route:** `/admin/tenants/show/{id}`

**Neue Sektionen:**

#### 1. Corporate Structure Information
- Parent-Anzeige mit Link
- Governance-Modell Badge
- Hierarchieebene
- Liste der Subsidiaries

#### 2. ISMS Context & Organization
- Effektiver ISMS-Kontext
- Vererbungsstatus
- Propagierungs-Button (nur für Parents)

#### 3. Granular Governance Rules
- Liste aller Rules gruppiert nach Scope
- Add-Rule-Button
- Delete-Button pro Rule
- Loading-State

---

## Datenbank-Schema

### Tabelle: `corporate_governance`

```sql
CREATE TABLE corporate_governance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    parent_id INT NOT NULL,
    scope VARCHAR(50) NOT NULL,
    scope_id VARCHAR(100) DEFAULT NULL,
    governance_model VARCHAR(20) NOT NULL,
    notes LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME DEFAULT NULL,
    created_by_id INT DEFAULT NULL,

    FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES tenant(id) ON DELETE CASCADE,

    UNIQUE KEY uniq_tenant_scope (tenant_id, scope, scope_id)
);
```

**Wichtige Indices:**
- `IDX_tenant_id` - Schnelle Abfrage aller Rules eines Tenants
- `IDX_parent_id` - Hierarchische Queries
- `uniq_tenant_scope` - Verhindert Duplikate

---

## Troubleshooting

### Problem: 404 "Tenant not found"

**Ursache:** Gelöschter Tenant wird noch referenziert

**Lösung:**
1. Event Subscriber aktiviert? → Prüfe `src/EventSubscriber/TenantNotFoundSubscriber.php`
2. CASCADE DELETE funktioniert? → Prüfe Foreign Keys
3. Cache leeren:
   ```bash
   php bin/console cache:clear
   php bin/console doctrine:cache:clear-metadata
   ```

### Problem: Migration schlägt fehl

**Ursache:** Bestehende Daten inkonsistent

**Lösung:**
```bash
# Rollback
php bin/console doctrine:migrations:migrate prev

# Daten bereinigen
php bin/console doctrine:query:sql "DELETE FROM corporate_governance"

# Erneut migrieren
php bin/console doctrine:migrations:migrate
```

### Problem: Governance-Rules werden nicht geladen

**Ursache:** JavaScript-Fehler oder API-Fehler

**Debug:**
1. Browser-Konsole öffnen (F12)
2. Network-Tab prüfen
3. API-Response analysieren

**Typische Fehler:**
- 403 Forbidden → Fehlende ROLE_ADMIN
- 404 Not Found → Tenant existiert nicht
- 500 Server Error → Prüfe Logs: `var/log/dev.log`

### Problem: Circular Reference beim Parent-Setzen

**Ursache:** Tenant soll eigener Parent werden

**Validierung:** Bereits implementiert in `CorporateStructureService::validateStructure()`

**Manuell prüfen:**
```bash
php bin/console doctrine:query:sql "
SELECT t1.id, t1.name, t2.id as parent_id, t2.name as parent_name
FROM tenant t1
LEFT JOIN tenant t2 ON t1.parent_id = t2.id
WHERE t1.id = t1.parent_id
"
```

---

## Testing

### Manuelle Tests

#### Test 1: Hierarchie erstellen
1. Gehe zu `/admin/tenants/corporate-structure`
2. Klicke bei einem Standalone-Tenant auf "Muttergesellschaft zuweisen"
3. Wähle Parent und Governance-Modell
4. Speichern
5. **Erwartung:** Tenant erscheint unter Parent in Hierarchie

#### Test 2: Granulare Governance
1. Öffne Tenant-Detail-Seite eines Subsidiary
2. Scrolle zu "Granulare Governance-Regeln"
3. Klicke "Regel hinzufügen"
4. Wähle Scope `control`, ID `A.5.1`, Modell `hierarchical`
5. Speichern
6. **Erwartung:** Regel erscheint in Liste mit Badge

#### Test 3: ISMS-Kontext-Propagierung
1. Als Parent-Tenant: Erstelle/Ändere ISMS-Kontext
2. Gehe zu Parent-Tenant-Detail-Seite
3. Klicke "An Tochtergesellschaften verteilen"
4. Bestätige
5. **Erwartung:** Alert "ISMS context propagated to X subsidiary(ies)"
6. **Prüfe:** Subsidiary hat jetzt gleichen ISMS-Kontext

#### Test 4: Multi-Tenant-Check
1. Deaktiviere alle Tenants außer einem
2. Navigiere zu `/admin/tenants`
3. **Erwartung:** Kein "Konzernstrukturen"-Menüpunkt sichtbar
4. Aktiviere zweiten Tenant
5. **Erwartung:** Menüpunkt erscheint wieder

### Automated Tests (Empfohlen)

```php
// tests/Functional/CorporateStructureTest.php
public function testSetParentCreatesGovernance(): void
{
    $client = static::createClient();
    $this->loginAsAdmin($client);

    $client->request('POST', '/admin/tenants/1/set-parent', [
        'parent_id' => 2,
        'governance_model' => 'hierarchical'
    ]);

    $this->assertResponseRedirects();

    $governance = $this->entityManager
        ->getRepository(CorporateGovernance::class)
        ->findDefaultGovernance($tenant);

    $this->assertNotNull($governance);
    $this->assertEquals('hierarchical', $governance->getGovernanceModel()->value);
}
```

### Performance Tests

**Große Hierarchien (1000+ Tenants):**

```bash
# Generiere Testdaten
php bin/console app:generate-test-tenants 1000

# Messe Performance
time curl http://localhost/admin/tenants/corporate-structure
```

**Erwartete Ladezeit:** < 2 Sekunden

---

## Bekannte Limitationen

1. **Keine Drag & Drop UI** für Hierarchie-Änderungen
2. **Keine Bulk-Operations** für Governance-Rules
3. **Keine Visualisierung** als Baum-Diagramm
4. **Keine Historie** von Governance-Änderungen
5. **Keine Benachrichtigungen** bei Propagierung

---

## Roadmap

### Version 1.1
- [ ] Visueller Struktur-Baum mit D3.js
- [ ] Audit-Log für alle Änderungen
- [ ] Export als PDF/JSON
- [ ] Bulk-Import via CSV

### Version 1.2
- [ ] Rollen-basierte Governance
- [ ] Workflows für Genehmigungen
- [ ] Auto-Propagierung bei Changes
- [ ] Performance-Optimierung (Caching)

---

## Support

**Fragen?** Erstelle ein Issue auf GitHub.

**Bugs?** Bitte mit folgenden Infos:
- Symfony Version
- PHP Version
- Browser & Version
- Fehlermeldung aus `var/log/dev.log`
- Steps to reproduce

---

**Version:** 1.0.0
**Letzte Aktualisierung:** 2025-01-13
**Autor:** Claude AI & Development Team
