# Konzernstruktur-Feature - Dokumentation

## Übersicht

Dieses Feature ermöglicht die Abbildung von Konzernstrukturen mit Muttergesellschaften und Tochtergesellschaften im Little ISMS Helper. Es unterstützt verschiedene Governance-Modelle für die ISMS-Verantwortung.

## Features

### 1. Hierarchische Konzernstruktur
- **Parent-Child Beziehungen**: Mandanten können als Mutter- oder Tochtergesellschaften definiert werden
- **Unbegrenzte Hierarchietiefe**: Unterstützung für mehrere Ebenen (Konzern → Tochter → Enkeltochter etc.)
- **Kreislaufprüfung**: Automatische Validierung gegen zirkuläre Referenzen

### 2. Governance-Modelle

#### a) Hierarchisch (100% Muttergesellschaft)
- Die Muttergesellschaft hat vollständige Kontrolle und Verantwortung für das ISMS
- ISMS-Kontext wird von der Muttergesellschaft geerbt
- Tochtergesellschaften übernehmen Richtlinien und Kontext der Muttergesellschaft
- Zentralisierte Entscheidungsfindung

**Anwendungsfall**: Konzerne mit stark zentralisierter IT- und Sicherheitsverwaltung

#### b) Geteilte Verantwortung
- Verantwortung wird zwischen Mutter- und Tochtergesellschaft geteilt
- Die Muttergesellschaft gibt den Rahmen vor
- Tochtergesellschaften implementieren im eigenen Kontext
- Jede Tochter kann ihren eigenen ISMS-Kontext haben

**Anwendungsfall**: Konzerne mit autonomen Geschäftsbereichen, die eigene Sicherheitsverantwortung tragen

#### c) Unabhängig
- Tochtergesellschaft operiert vollständig unabhängig mit eigenem ISMS
- Muttergesellschaft hat keine direkte Kontrolle
- Verwendung für rechtlich separate Entitäten

**Anwendungsfall**: Holding-Strukturen mit rechtlich unabhängigen Tochtergesellschaften

### 3. ISMS-Kontext-Vererbung
- **Hierarchisch**: Automatische Vererbung des ISMS-Kontexts von der Muttergesellschaft
- **Geteilt**: Automatische Erstellung eines abgeleiteten Kontexts basierend auf der Muttergesellschaft als Vorlage
- **Unabhängig**: Kein Kontext wird geerbt

### 4. Zugriffskontrolle
- Benutzer der Muttergesellschaft können auf alle Tochtergesellschaften zugreifen
- Benutzer von Tochtergesellschaften können nur auf ihren eigenen Mandanten zugreifen
- Bei "Geteilter Verantwortung" können explizite Berechtigungen vergeben werden

## Technische Implementierung

### Datenbankschema

#### Neue Felder in `tenant` Tabelle:
```sql
ALTER TABLE tenant ADD parent_id INT DEFAULT NULL;
ALTER TABLE tenant ADD governance_model VARCHAR(20) DEFAULT NULL;
ALTER TABLE tenant ADD is_corporate_parent TINYINT(1) DEFAULT 0;
ALTER TABLE tenant ADD corporate_notes LONGTEXT DEFAULT NULL;
```

#### Self-Referencing Beziehung:
- `parent_id` → Foreign Key auf `tenant.id`
- Ermöglicht Parent-Child Beziehungen

### Backend-Komponenten

#### 1. Enum: `GovernanceModel`
**Datei**: `src/Enum/GovernanceModel.php`

Definiert die drei Governance-Modelle:
- `HIERARCHICAL`
- `SHARED`
- `INDEPENDENT`

#### 2. Entity: `Tenant` (erweitert)
**Datei**: `src/Entity/Tenant.php`

Neue Felder und Methoden:
- `parent`: ManyToOne Beziehung zu sich selbst
- `subsidiaries`: OneToMany Collection von Tochtergesellschaften
- `governanceModel`: GovernanceModel Enum
- `isCorporateParent`: Boolean Flag
- `corporateNotes`: Optionale Notizen

Hilfsmethoden:
- `isPartOfCorporateStructure()`: Prüft ob Teil einer Struktur
- `getRootParent()`: Gibt die oberste Muttergesellschaft zurück
- `getAllSubsidiaries()`: Gibt alle Tochtergesellschaften rekursiv zurück
- `getHierarchyDepth()`: Gibt die Tiefe in der Hierarchie zurück

#### 3. Service: `CorporateStructureService`
**Datei**: `src/Service/CorporateStructureService.php`

Zentrale Business-Logik für Konzernstrukturen:

**Methoden**:
- `getEffectiveISMSContext(Tenant $tenant)`: Ermittelt den effektiven ISMS-Kontext basierend auf Governance-Modell
- `canAccessTenant(Tenant $userTenant, Tenant $targetTenant)`: Prüft Zugriffsberechtigung
- `isParentOf(Tenant $parent, Tenant $child)`: Prüft Parent-Child Beziehung
- `isInSameCorporateGroup(Tenant $t1, Tenant $t2)`: Prüft ob in gleicher Konzerngruppe
- `getCorporateGroup(Tenant $tenant)`: Gibt alle Mandanten der Konzerngruppe zurück
- `validateStructure(Tenant $tenant)`: Validiert die Struktur (Kreislaufprüfung etc.)
- `getStructureTree(Tenant $root)`: Gibt Hierarchie als Baum zurück
- `propagateContextChanges(Tenant $parent, ISMSContext $context)`: Propagiert Änderungen an Tochtergesellschaften

#### 4. Controller: `CorporateStructureController`
**Datei**: `src/Controller/CorporateStructureController.php`

REST API-Endpunkte:
- `GET /api/corporate-structure/tree/{id}`: Struktur-Baum abrufen
- `GET /api/corporate-structure/groups`: Alle Konzerngruppen abrufen
- `POST /api/corporate-structure/set-parent`: Muttergesellschaft zuweisen
- `PATCH /api/corporate-structure/governance-model/{id}`: Governance-Modell ändern
- `GET /api/corporate-structure/effective-context/{id}`: Effektiven ISMS-Kontext abrufen
- `GET /api/corporate-structure/governance-models`: Alle Governance-Modelle
- `GET /api/corporate-structure/check-access/{targetId}`: Zugriffsberechtigung prüfen
- `POST /api/corporate-structure/propagate-context/{id}`: Kontext an Tochtergesellschaften propagieren

#### 5. Controller: `TenantManagementController` (erweitert)
**Datei**: `src/Controller/TenantManagementController.php`

Neue Routen für die Web-UI:
- `GET /admin/tenants/corporate-structure`: Konzernstruktur-Übersicht
- `POST /admin/tenants/{id}/set-parent`: Muttergesellschaft zuweisen
- `POST /admin/tenants/{id}/update-governance`: Governance-Modell ändern

### Frontend-Komponenten

#### Template: `corporate_structure.html.twig`
**Datei**: `templates/admin/tenants/corporate_structure.html.twig`

Features:
- **Statistik-Cards**: Anzahl Konzerngruppen, eigenständige Mandanten, Gesamt
- **Konzerngruppen-Ansicht**: Hierarchische Baumstruktur mit visueller Darstellung
- **Eigenständige Mandanten**: Tabelle mit Mandanten ohne Parent
- **Set Parent Modal**: Dialog zum Zuweisen einer Muttergesellschaft
- **Governance-Badge**: Visuelle Kennzeichnung des Governance-Modells
- **Aktionen**: Zuweisen, Entfernen, Governance-Modell ändern

#### JavaScript-Funktionalität:
- Modal zum Zuweisen von Muttergesellschaften
- Dynamische Beschreibung des gewählten Governance-Modells
- Verhinderung von Selbstzuweisungen

### Übersetzungen

#### Deutsche Übersetzungen
**Datei**: `translations/messages.de.yaml`

Alle UI-Texte für:
- Titel und Untertitel
- Felder und Labels
- Aktionen
- Flash-Nachrichten
- Hilfetexte

#### Englische Übersetzungen
**Datei**: `translations/messages.en.yaml`

Vollständige englische Übersetzung aller Texte

## Installation & Setup

### 1. Datenbankmigration ausführen

```bash
php bin/console doctrine:migrations:migrate
```

Die Migration fügt die neuen Felder zur `tenant` Tabelle hinzu und erstellt die notwendigen Indizes.

### 2. Cache leeren

```bash
php bin/console cache:clear
```

### 3. Assets neu kompilieren (falls Webpack/Encore verwendet wird)

```bash
npm run build
# oder
yarn build
```

## Verwendung

### 1. Konzernstruktur einrichten

1. Navigiere zu **Admin → Mandanten → Konzernstrukturen**
2. Wähle einen Mandanten aus der "Eigenständige Mandanten"-Liste
3. Klicke auf "Muttergesellschaft zuweisen"
4. Wähle die Muttergesellschaft aus
5. Wähle das Governance-Modell
6. Speichern

### 2. ISMS-Kontext propagieren (Hierarchisch)

Bei hierarchischen Strukturen:

1. Gehe zum ISMS-Kontext der Muttergesellschaft
2. Nach Änderungen wird der Kontext automatisch an alle hierarchischen Tochtergesellschaften weitergegeben
3. Oder manuell über API: `POST /api/corporate-structure/propagate-context/{parentId}`

### 3. Zugriff auf Tochtergesellschaften

Benutzer der Muttergesellschaft:
- Können automatisch auf alle Tochtergesellschaften zugreifen
- Sehen alle Daten der Konzerngruppe

Benutzer von Tochtergesellschaften:
- Sehen nur ihren eigenen Mandanten
- Bei "Geteilter Verantwortung": Können auf Parent-Daten als Referenz zugreifen (Read-Only)

## API-Nutzung

### Beispiel: Muttergesellschaft zuweisen

```bash
curl -X POST /api/corporate-structure/set-parent \
  -H "Content-Type: application/json" \
  -d '{
    "tenantId": 5,
    "parentId": 1,
    "governanceModel": "hierarchical"
  }'
```

### Beispiel: Struktur-Baum abrufen

```bash
curl /api/corporate-structure/tree/1
```

Response:
```json
{
  "id": 1,
  "code": "PARENT",
  "name": "Parent Company",
  "governanceModel": null,
  "isCorporateParent": true,
  "depth": 0,
  "children": [
    {
      "id": 2,
      "code": "SUB1",
      "name": "Subsidiary 1",
      "governanceModel": "hierarchical",
      "governanceLabel": "Hierarchisch (100% Muttergesellschaft)",
      "depth": 1,
      "children": []
    }
  ]
}
```

## Sicherheitsaspekte

### 1. Validierung
- Kreislaufprüfung verhindert inkonsistente Strukturen
- Governance-Modell ist Pflicht bei Parent-Zuordnung
- CSRF-Schutz auf allen Formularen

### 2. Berechtigungen
- Nur `ROLE_ADMIN` kann Konzernstrukturen verwalten
- Tenant-basierte Zugriffskontrolle auf allen API-Endpunkten
- Automatische Tenant-Isolation in der Datenbank

### 3. Audit-Logging
- Alle Änderungen werden geloggt
- Wer, Wann, Was-Informationen in den Logs

## Erweiterungsmöglichkeiten

### 1. Erweiterte Berechtigungen
- Granulare Berechtigungen pro Tochtergesellschaft
- Cross-Tenant Berechtigungen für spezifische Module

### 2. Dashboard-Integration
- Konzern-Dashboard mit aggregierten Metriken
- Drill-Down von Parent zu Subsidiaries

### 3. Berichtswesen
- Konsolidierte Berichte über Konzernstrukturen
- Vergleichsberichte zwischen Tochtergesellschaften

### 4. Automatisierung
- Automatische Zuweisung basierend auf Azure AD Gruppen
- Workflow-Integration für Genehmigungsprozesse

## Troubleshooting

### Problem: Migration schlägt fehl
**Lösung**:
```bash
php bin/console doctrine:schema:update --force
```

### Problem: Kreislaufreferenz erkannt
**Lösung**:
Entferne die Parent-Zuordnung und baue die Struktur neu auf. Die Validierung verhindert Zirkelschlüsse.

### Problem: ISMS-Kontext wird nicht vererbt
**Lösung**:
Prüfe das Governance-Modell der Tochtergesellschaft. Vererbung funktioniert nur bei "Hierarchisch".

### Problem: Benutzer sehen keine Tochtergesellschaften
**Lösung**:
Prüfe die Berechtigungen und stelle sicher, dass der Benutzer zur Muttergesellschaft gehört.

## Dateien-Übersicht

### Neue Dateien:
```
src/
  ├── Enum/
  │   └── GovernanceModel.php                          # Governance-Modell Enum
  ├── Service/
  │   └── CorporateStructureService.php                # Business-Logik
  └── Controller/
      └── CorporateStructureController.php             # REST API Controller

migrations/
  └── Version20250113000001_add_corporate_structure.php # Datenbank-Migration

templates/
  └── admin/
      └── tenants/
          └── corporate_structure.html.twig            # Web-UI Template
```

### Geänderte Dateien:
```
src/
  ├── Entity/
  │   └── Tenant.php                                   # Erweitert mit Corporate-Feldern
  └── Controller/
      └── TenantManagementController.php              # Neue Routen hinzugefügt

translations/
  ├── messages.de.yaml                                 # Deutsche Übersetzungen
  └── messages.en.yaml                                 # Englische Übersetzungen
```

## Support & Kontakt

Bei Fragen oder Problemen:
1. Prüfe die Logs: `var/log/dev.log` oder `var/log/prod.log`
2. Aktiviere Debug-Modus für detaillierte Fehlermeldungen
3. Erstelle ein Issue im Repository

## Lizenz

Dieses Feature ist Teil des Little ISMS Helper Projekts und unterliegt derselben Lizenz.
