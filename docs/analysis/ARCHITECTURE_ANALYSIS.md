# Analyse der bestehenden Architektur: Little-ISMS-Helper

## 1. ORGANISATIONEN UND MULTI-TENANCY

### Aktuelles Modell: Tenant-basierte Multi-Tenancy

**Entity: `Tenant`** (`/src/Entity/Tenant.php`)
- **Datenbankstruktur:**
  - `id` (Primary Key)
  - `code` (Unique, max 100 Zeichen) - Eindeutiger Mandanten-Identifier
  - `name` (VARCHAR 255) - Displayname
  - `description` (TEXT) - Beschreibung
  - `azureTenantId` (VARCHAR 255) - Integration mit Azure AD
  - `isActive` (BOOLEAN, default: true) - Status
  - `settings` (JSON) - Mandanten-spezifische Konfiguration
  - `logoPath` (VARCHAR 255) - Branding
  - `createdAt` / `updatedAt` (Timestamps)

**Beziehungen:**
- 1:N zu `User` (One Tenant has Many Users)
- 1:N zu `ISMSContext`
- Referenziert durch alle anderen Entities (Asset, Risk, Control, etc.)

**Management via TenantManagementController** (`/src/Controller/TenantManagementController.php`)
```
GET  /admin/tenants              - Tenant-Listung mit Filterung (active/inactive)
POST /admin/tenants              - Neuen Tenant erstellen
GET  /admin/tenants/{id}         - Tenant-Details anzeigen
GET  /admin/tenants/{id}/edit    - Edit-Formular
POST /admin/tenants/{id}/edit    - Tenant aktualisieren
POST /admin/tenants/{id}/toggle  - Tenant aktivieren/deaktivieren
POST /admin/tenants/{id}/delete  - Tenant löschen (nur ohne Users)
```

### Service-Ebene: TenantContext
**Klasse:** `App\Service\TenantContext`

Zentraler Service für Tenant-Management:
```php
- getCurrentTenant(): ?Tenant        // Aktiven Tenant vom eingeloggten User abrufen
- getCurrentTenantId(): ?int         // Tenant ID des aktuellen Users
- setCurrentTenant(?Tenant): void    // Tenant manuell setzen (Tenant-Switching)
- hasTenant(): bool                  // Check ob Tenant aktiv
- belongsToTenant(Tenant): bool      // Cross-Tenant-Check
- getActiveTenants(): array          // Alle aktiven Tenants
- reset(): void                      // Reset für Testing
```

**Automatische Initialization:**
- Wird vom eingeloggten User gelesen
- `User::getTenant()` liefert den zugeordneten Tenant

---

## 2. ISMS-KONTEXT (ISO 27001 Clause 4)

### Entity: ISMSContext
**Klasse:** `App\Entity\ISMSContext` (`/src/Entity/ISMSContext.php`)

**Datenbankmodell:**
```
id (PK)
organizationName (VARCHAR 255)      - Organisationsname
ismsScope (TEXT)                    - Geltungsbereich des ISMS
scopeExclusions (TEXT)              - Ausschlüsse vom Scope
externalIssues (TEXT)               - Externe Faktoren
internalIssues (TEXT)               - Interne Faktoren
interestedParties (TEXT)            - Interessenvertreter
interestedPartiesRequirements (TEXT) - Anforderungen der Interessenvertreter
legalRequirements (TEXT)            - Rechtliche Anforderungen
regulatoryRequirements (TEXT)       - Behördliche Anforderungen
contractualObligations (TEXT)       - Vertragliche Verpflichtungen
ismsPolicy (TEXT)                   - ISMS-Richtlinie
rolesAndResponsibilities (TEXT)     - Rollen und Verantwortlichkeiten
lastReviewDate (DATE)               - Letzte Überprüfung
nextReviewDate (DATE)               - Nächste geplante Überprüfung
createdAt / updatedAt (DATETIME)
tenant_id (FK)                      - Multi-Tenant Support
```

**Beziehungen:**
- N:1 zu `Tenant` (optional, nullable)
- 1:N zu `ISMSObjective` (über Service)

**API-Endpunkte:**
```
GET    /api/isms_contexts              - Alle Kontexte (ROLE_USER)
GET    /api/isms_contexts/{id}         - Detail (ROLE_USER)
POST   /api/isms_contexts              - Erstellen (ROLE_ADMIN)
PATCH  /api/isms_contexts/{id}         - Aktualisieren (ROLE_ADMIN)
DELETE /api/isms_contexts/{id}         - Löschen (ROLE_ADMIN)
```

**Web-Controller: ContextController** (`/src/Controller/ContextController.php`)
```
GET  /context/          - Index + Audit-Log + Statistiken
GET  /context/edit      - Edit-Formular
POST /context/edit      - Speichern (ROLE_ADMIN)
```

### Service-Ebene: ISMSContextService
**Klasse:** `App\Service\ISMSContextService`

**Funktionen:**
```php
- getCurrentContext(): ISMSContext        // Aktiven Context laden/erstellen
- saveContext(ISMSContext): void          // Persistieren
- calculateCompleteness(ISMSContext): int // 0-100% Vollständigkeit
- isReviewDue(ISMSContext): bool          // Review-Status prüfen
- getDaysUntilReview(ISMSContext): ?int   // Tage bis nächstes Review
- scheduleNextReview(ISMSContext): void   // Review-Termin setzen (+1 Jahr)
- validateContext(ISMSContext): array     // Validierungsfehler sammeln
```

**Validierungsregeln:**
- Organisationsname: erforderlich
- ISMS-Geltungsbereich: erforderlich
- ISMS-Richtlinie: erforderlich
- Rollen und Verantwortlichkeiten: erforderlich

---

## 3. BERECHTIGUNGEN UND ZUGRIFFSKONTROLLE

### Mehrstufiges Permission-System

#### Ebene 1: Symfony Built-in Roles (String-basiert)
In `User::roles` (JSON Array):
```
ROLE_USER        - Standard-Rolle (automatisch zugewiesen)
ROLE_ADMIN       - Admin-Rolle (Super-Zugriff)
ROLE_SUPER_ADMIN - Super-Admin (für kritische Operationen)
```

#### Ebene 2: Custom Roles & Permissions (Entity-basiert)

**Entity: Role** (`/src/Entity/Role.php`)
```
id (PK)
name (VARCHAR 100, UNIQUE)           - Rollenname (z.B., "Risk Manager")
description (VARCHAR 255)            - Beschreibung
isSystemRole (BOOLEAN)               - Systemrolle (unveränderbar)
createdAt / updatedAt (DATETIME)
permissions (Many-to-Many)           - Zugeordnete Permissions
users (Many-to-Many)                 - Zugeordnete Users
```

**Entity: Permission** (`/src/Entity/Permission.php`)
```
id (PK)
name (VARCHAR 100, UNIQUE)           - Eindeutiger Name (z.B., "risk.view")
description (VARCHAR 255)
category (VARCHAR 50)                - Kategorie (risk, asset, control, user, report, etc.)
action (VARCHAR 50)                  - Aktion (view, create, edit, delete, approve, export)
isSystemPermission (BOOLEAN)         - Systemgenehmigung
createdAt (DATETIME)
roles (Many-to-Many)                 - Rollen mit dieser Permission
```

**Datenbank-Struktur:**
```
user_roles (Many-to-Many Junction Table)
  - user_id (FK) -> User
  - role_id (FK) -> Role

role_permissions (Many-to-Many Junction Table)
  - role_id (FK) -> Role
  - permission_id (FK) -> Permission
```

### Beziehung: User -> Roles -> Permissions

```
User
├── roles[] (String Array) - Built-in Symfony Roles
│   └── ROLE_ADMIN
│       └── Voter: Erlaubt alles
│
├── customRoles (Entity Collection) - Custom Role Entities
│   └── Role: "Risk Manager"
│       └── permissions[] (Entity Collection)
│           ├── Permission: "risk.view"
│           ├── Permission: "risk.create"
│           ├── Permission: "risk.edit"
│           └── Permission: "risk.delete"
```

**Permission-Auflösung in User::getRoles():**
```php
public function getRoles(): array
{
    $roles = $this->roles; // Built-in Roles
    
    foreach ($this->customRoles as $customRole) {
        $roles[] = $customRole->getName(); // Z.B., "Risk Manager"
    }
    
    $roles[] = 'ROLE_USER'; // Garantiert
    return array_unique($roles);
}
```

### Voting System (Security Voters)

**Basis-Voter: UserVoter** (`/src/Security/Voter/UserVoter.php`)

Implementiert fine-grained Authorization:
```php
Supported Attributes:
- UserVoter::VIEW              ("user.view")
- UserVoter::VIEW_ALL          ("user.view_all")
- UserVoter::CREATE            ("user.create")
- UserVoter::EDIT              ("user.edit")
- UserVoter::DELETE            ("user.delete")
- UserVoter::MANAGE_ROLES      ("user.manage_roles")
- UserVoter::MANAGE_PERMISSIONS ("user.manage_permissions")
```

**Voting-Regeln:**
```php
// Inaktive User haben keinen Zugriff
if (!$user->isActive()) return false;

// ROLE_ADMIN bypasst Permissions
if (in_array('ROLE_ADMIN', $user->getRoles())) return true;

// Spezifische Permission-Checks via hasPermission()
return $user->hasPermission($permission);
```

**Beispiel: User-Managemement anfordern**
```php
// Im Controller
$this->denyAccessUnlessGranted(UserVoter::VIEW_ALL);
$this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

// hasPermission() durchsucht Custom Roles
public function hasPermission(string $permission): bool
{
    foreach ($this->customRoles as $role) {
        if ($role->hasPermission($permission)) {
            return true;
        }
    }
    return false;
}
```

### Weitere Voter
- **RoleVoter** - Role-Management
- **RiskVoter** - Risk-Entity-Zugriff
- **AssetVoter** - Asset-Entity-Zugriff
- **IncidentVoter** - Incident-Handling
- **ControlVoter** - Control-Management
- **DocumentVoter** - Document-Access
- **EntityVoter** - Basis-Entity-Zugriff

### Management-Controller

#### RoleManagementController (`/src/Controller/RoleManagementController.php`)
```
GET    /admin/roles               - Alle Rollen anzeigen
POST   /admin/roles/new           - Rolle erstellen
GET    /admin/roles/{id}          - Rollen-Details
POST   /admin/roles/{id}/edit     - Rolle aktualisieren
POST   /admin/roles/{id}/delete   - Rolle löschen (nicht System-Roles)
GET    /admin/roles/compare       - Rollen-Vergleich
GET    /admin/roles/templates     - Vorlagen anwenden
```

**Vordefinierte Rollen-Templates:**
- Auditor (Read-Only)
- Risk Manager (Full Risk Access)
- Compliance Officer (Audit + Compliance)
- Incident Manager (Incident Response)
- Asset Manager (Asset Management)
- Read-Only User (Minimal)

#### PermissionController (`/src/Controller/PermissionController.php`)
```
GET /admin/permissions             - Alle Permissions (gruppiert nach Kategorie)
GET /admin/permissions?category=   - Filtern nach Kategorie
GET /admin/permissions/{id}        - Permission-Detail mit betroffenen Rollen/Usern
```

#### UserManagementController (`/src/Controller/UserManagementController.php`)
```
GET    /admin/users               - User-Listung mit Statistiken
POST   /admin/users/new           - User erstellen
GET    /admin/users/{id}          - User-Details
POST   /admin/users/{id}/edit     - User aktualisieren
POST   /admin/users/{id}/delete   - User löschen
POST   /admin/users/{id}/toggle-active  - Aktivieren/Deaktivieren
POST   /admin/users/bulk-actions  - Bulk-Operationen (activate, deactivate, assign_role, delete)
POST   /admin/users/export        - CSV-Export
POST   /admin/users/import        - CSV-Import
GET    /admin/users/{id}/activity - User-Aktivitäten (Audit-Log)
GET    /admin/users/{id}/mfa      - MFA-Tokens verwalten
POST   /admin/users/{id}/mfa/{tokenId}/reset - Token zurücksetzen
GET    /admin/users/{id}/impersonate - User-Impersonation (SUPER_ADMIN)
```

---

## 4. REPOSITORIES UND QUERIES

### ISMSContextRepository
```php
getCurrentContext(): ?ISMSContext    // Letzter aktualisierter Context
```

### UserRepository
```php
findByAzureObjectId($id): ?User      // Für Azure SSO
findOrCreateFromAzure($data): User   // SSO-Integration
findActiveUsers(): array             // Nur aktive User
findByRole($role): array             // Nach Built-in Rolle
findByCustomRole($name): array       // Nach Custom Role
searchUsers($query): array           // Suchfunktion
getUserStatistics(): array           // {total, active, inactive, azure, local}
getRecentlyActiveUsers($limit): array
```

### TenantRepository
```php
findActive(): array                  // Nur aktive Tenants
findByAzureTenantId($id): ?Tenant    // SSO-Integration
findByCode($code): ?Tenant           // Unique Code
```

### RoleRepository
```php
findCustomRoles(): array             // Nur Custom Roles
findSystemRoles(): array             // System Roles
findByName($name): ?Role             // By Name
findWithPermissions($id): ?Role      // Eager-Load Permissions
getRolesWithUserCount(): array       // Für Role-Übersicht
```

### PermissionRepository
```php
findByCategory($cat): array          // Nach Kategorie
findAllGroupedByCategory(): array    // {category => [Permissions]}
findByName($name): ?Permission       // By Name
getCategories(): array               // Alle Kategorien
getActions(): array                  // Alle möglichen Actions
```

---

## 5. DATENBANK-BEZIEHUNGEN

### Entity-Relationship-Diagram (ISMS-fokussiert)

```
┌─────────────┐
│   Tenant    │ (Multi-Tenant Root)
├─────────────┤
│ id (PK)     │
│ code        │
│ name        │
│ azureTenantId│
│ isActive    │
└──────┬──────┘
       │ 1:N
       │
   ┌───┴─────────────────────────┬──────────────────────┐
   │                             │                      │
   ▼                             ▼                      ▼
┌──────────┐              ┌─────────────┐        ┌──────────┐
│  User    │              │ ISMSContext │        │  Asset   │
├──────────┤              ├─────────────┤        ├──────────┤
│ id (PK)  │              │ id (PK)     │        │ id (PK)  │
│ email    │              │ org_name    │        │ name     │
│ tenant_id│◄─────────────│ scope       │        │ type     │
│ roles[]  │              │ policy      │        │ tenant_id│
│          │              │ tenant_id   │        └──────────┘
└────┬─────┘              └─────────────┘
     │ M:N
     │
  ┌──┴───────────────┐
  │                  │
  ▼                  ▼
┌────────┐     ┌────────────┐
│ Role   │     │ Permission │
├────────┤     ├────────────┤
│ id(PK) │     │ id (PK)    │
│ name   │◄───┤ name       │
│ desc   │ M:N │ category   │
└────────┘     │ action     │
               └────────────┘
```

### Cascade Rules

**User -> Tenant:**
- `onDelete: SET NULL` - User bleibt mit Tenant verknüpft, bis manuell geändert
- Tenant mit aktiven Users kann nicht gelöscht werden

**ISMSContext -> Tenant:**
- `onDelete: SET NULL` - Context bleibt erhalten, Tenant-Zuordnung wird entfernt

**Role -> User:**
- M:N Beziehung - Löschen einer Rolle entfernt sie aus allen Usern

---

## 6. AZURE AD INTEGRATION

### Single Sign-On (SSO)

**User Entity - Azure Fields:**
```php
authProvider: ?string                // 'local', 'azure_oauth', 'azure_saml'
azureObjectId: ?string               // Azure AD Object ID (Unique)
azureTenantId: ?string               // Azure AD Tenant ID
azureMetadata: ?array                // Zusätzliche Azure-Metadaten
```

**Tenant Entity - Azure:**
```php
azureTenantId: ?string               // Azure AD Tenant ID
```

**SSO-Flow:**
1. User wird zu Azure AD weitergeleitet
2. Azure authentifiziert User
3. `UserRepository::findOrCreateFromAzure()` wird aufgerufen
4. User wird nach Azure Object ID gesucht
5. Bei Fehler: Nach Email suchen
6. Neuer User wird erstellt (mit is_verified=true)
7. Azure-Metadaten werden gespeichert

**Security Handler:**
- `App\Security\LoginSuccessHandler` - Post-Login-Verarbeitung
- Setzt Last-Login-Timestamp

---

## 7. SICHERHEITSFEATURES

### MFA (Multi-Factor Authentication)

**Entity: MfaToken** (`/src/Entity/MfaToken.php`)
```
token_type: 'totp', 'webauthn', 'sms'
secret: (encrypted)
backup_codes: JSON
is_active: boolean
is_primary: boolean
usage_count: int
enrolled_at: datetime
expires_at: datetime
last_used_at: datetime
```

**Management:**
- `/admin/users/{id}/mfa` - Token-Übersicht
- `/admin/users/{id}/mfa/{tokenId}/reset` - Token zurücksetzen (SUPER_ADMIN)

### Audit Logging

**Entity: AuditLog**
```
entity_type: string (Klassennamen)
entity_id: int
action: string (CREATE, UPDATE, DELETE, etc.)
user_name: string
ip_address: string
old_values: JSON
new_values: JSON
description: string
created_at: datetime
user_agent: string
```

**Automatisches Logging via AuditLogSubscriber:**
- Doctrined Events (postPersist, postUpdate, postRemove)
- User-Email wird extrahiert
- IP-Address wird erfasst
- Vor/Nach-Werte werden verglichen

---

## 8. TEMPLATES & FRONTEND

### Verzeichnis-Struktur

```
templates/
├── user_management/
│   ├── index.html.twig         - User-Listung
│   ├── new.html.twig           - Neuer User
│   ├── edit.html.twig          - User bearbeiten
│   ├── show.html.twig          - User-Details
│   ├── activity.html.twig      - Audit-Log
│   ├── mfa.html.twig           - MFA-Verwaltung
│   └── import.html.twig        - CSV-Import
│
├── role_management/
│   ├── index.html.twig         - Rollen-Listung
│   ├── new.html.twig           - Neue Rolle
│   ├── edit.html.twig          - Rolle bearbeiten
│   ├── show.html.twig          - Rollen-Details
│   ├── compare.html.twig       - Rollen-Vergleich
│   └── templates.html.twig     - Rollen-Vorlagen
│
├── permission/
│   ├── index.html.twig         - Permission-Übersicht
│   └── show.html.twig          - Permission-Details
│
├── context/
│   ├── index.html.twig         - ISMS-Context-Übersicht
│   └── edit.html.twig          - Context bearbeiten
│
├── admin/
│   └── tenants/
│       ├── index.html.twig     - Tenant-Listung
│       ├── form.html.twig      - Tenant-Formular
│       └── show.html.twig      - Tenant-Details
│
└── _components/
    └── [Shared Components]
```

### Form Types

**UserType** - User-Formular mit:
- Email (unique)
- Vorname, Nachname
- Passwort (optional bei Edit)
- Department, Job Title
- Aktiv-Status
- Custom Roles (Multiple-Select)
- Tenant-Zuordnung

**RoleType** - Über POST-Request (kein Form-Type):
- Name (unique)
- Beschreibung
- System-Flag
- Permissions (Multiple Checkboxes, gruppiert nach Kategorie)

**ISMSContextType** - Formular mit:
- Organisationsname
- ISMS-Scope
- Exclusions
- Issues (extern/intern)
- Anforderungen
- Policy
- Review-Daten

---

## 9. KONFIGURATION

### Security Configuration (`config/packages/security.yaml`)

```yaml
Firewalls:
  - dev
  - main
    - pattern: ^/
    - provider: app_user_provider
    - form_login: /login
    - logout: /logout
    - remember_me: (optional)
    - oauth: (Azure SSO)

Access Control:
  - ^/admin:  ROLE_ADMIN
  - ^/login:  (anonymous)
  - ^/:       ROLE_USER
```

### Doctrine Configuration (`config/packages/doctrine.yaml`)

```yaml
Datenbank: MySQL/MariaDB
Charset: utf8mb4
Collation: utf8mb4_unicode_ci
Prä-Existierende Migrations: 20251113140643
```

---

## 10. ZUSAMMENFASSUNG DER ARCHITEKTUR

### Organisation
- **Monolithic Symfony Application**
- **Multi-Tenant Design** - Ein Instance für mehrere Organisationen
- **Tenant-Isolation** - Daten werden durch tenant_id segmentiert

### Authentifizierung
- **Dual-Mode:** Local (DB-Passwort) + Azure SSO
- **MFA-Support:** TOTP, WebAuthn, SMS
- **User-Impersonation** für Support (SUPER_ADMIN)

### Autorisierung
- **Zwei-Ebenen-System:**
  1. Symfony Roles (ROLE_ADMIN, ROLE_SUPER_ADMIN)
  2. Custom Roles mit Permissions
- **Voting:** Fine-grained Access Control via Voters
- **Basis-Permission:** ROLE_USER (Standard)

### ISMS-Kontext
- **Single ISMSContext per Tenant** (optional)
- **Review-Cycle Management** (letzte/nächste Überprüfung)
- **Completeness-Tracking** (0-100%)
- **Validierung** auf kritische Felder

### Datenschutz
- **Audit Logging** aller Änderungen
- **Encryption:** Sensitive Data (Passwörter via bcrypt, MFA-Secrets)
- **CSRF-Protection** auf allen POST-Requests
- **Rate-Limiting** (via ApiRateLimitSubscriber)

---

## 11. DEPLOYMENT CONSIDERATIONS

### Tenant-Setup
- **Tenant-Code:** Unique Identifier (z.B., "acme", "contoso")
- **Initialization:** Bei Tenant-Erstellung wird automatisch ISMSContext erstellt
- **Azure AD Mapping:** Optional azureTenantId für SSO

### User-Onboarding
- **Manuell:** Admin erstellt User mit Passwort
- **SSO:** User meldet sich mit Azure an → Auto-Provisioning
- **Bulk-Import:** CSV-Upload mit Email, Name, Rollen

### Datenmigrationen
- **Latest Migration:** Version20251113140643
- **Doctrine Migrations:** Automatisch verwaltet
- **Schema-Sync:** `doctrine:migrations:migrate`

---

## 12. SECURITY CONCERNS & BEST PRACTICES

### Implementiert
✅ CSRF-Protection auf Forms
✅ Password-Hashing (bcrypt via UserPasswordHasher)
✅ User-Status (inactive → kein Zugriff)
✅ Audit-Logging
✅ MFA-Support
✅ Multi-Tenancy-Isolation
✅ Permission-based Access Control

### Empfehlungen
⚠️ Tenant-Filter auf allen Queries (nicht automatisiert!)
⚠️ API-Rate-Limiting bei externen API-Zugriffe
⚠️ Session-Timeout für inaktive User
⚠️ Encryption of Sensitive Fields (MFA-Secrets bereits encrypted)
⚠️ Regular Audit-Log Archival

