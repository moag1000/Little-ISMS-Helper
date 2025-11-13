# Architektur - Schnellreferenz

## Kritische Dateien nach Bereich

### 1. ORGANISATIONEN & MULTI-TENANCY

| Bereich | Dateipfad | Beschreibung |
|---------|-----------|-------------|
| **Entity** | `/src/Entity/Tenant.php` | Mandanten-Entität (Stammdaten) |
| **Service** | `/src/Service/TenantContext.php` | Tenant-Kontext-Management (DI-Service) |
| **Repository** | `/src/Repository/TenantRepository.php` | DB-Queries für Tenants |
| **Controller** | `/src/Controller/TenantManagementController.php` | Tenant-Admin-Oberfläche |
| **Migration** | `/migrations/Version20251113140643.php` | Datenbankschema (mit Tenant-FK überall) |

### 2. ISMS-KONTEXT (ISO 27001 Clause 4)

| Bereich | Dateipfad | Beschreibung |
|---------|-----------|-------------|
| **Entity** | `/src/Entity/ISMSContext.php` | ISMS-Context mit Tenant-FK |
| **Service** | `/src/Service/ISMSContextService.php` | Kontext-Verwaltung (Review-Cycle, Validierung, Completeness) |
| **Repository** | `/src/Repository/ISMSContextRepository.php` | getCurrentContext() |
| **Controller (Web)** | `/src/Controller/ContextController.php` | Web-UI für Context-Bearbeitung |
| **API Resource** | `/src/ApiResource/` | API-Endpoints mit Security-Decorators |
| **Form Type** | `/src/Form/ISMSContextType.php` | Formular für Context-Edit |

### 3. USERS & AUTHENTIFIZIERUNG

| Bereich | Dateipfad | Beschreibung |
|---------|-----------|-------------|
| **Entity** | `/src/Entity/User.php` | User (roles[], tenant_id, Azure-Fields) |
| **MFA Entity** | `/src/Entity/MfaToken.php` | Multi-Factor-Auth Tokens |
| **Repository** | `/src/Repository/UserRepository.php` | Azure-Integration, findOrCreateFromAzure() |
| **Controller** | `/src/Controller/UserManagementController.php` | User-Admin (CRUD, Bulk, Import/Export, MFA) |
| **Security Handler** | `/src/Security/LoginSuccessHandler.php` | Post-Login Processing |

### 4. ROLLEN & BERECHTIGUNGEN

| Bereich | Dateipfad | Beschreibung |
|---------|-----------|-------------|
| **Role Entity** | `/src/Entity/Role.php` | Custom Rolle (N:M zu Permission) |
| **Permission Entity** | `/src/Entity/Permission.php` | Permission mit Category+Action |
| **Role Repository** | `/src/Repository/RoleRepository.php` | Queries (System vs Custom, with Permissions) |
| **Permission Repository** | `/src/Repository/PermissionRepository.php` | findAllGroupedByCategory(), getActions() |
| **Role Controller** | `/src/Controller/RoleManagementController.php` | Role-Admin (CRUD, Vergleich, Templates) |
| **Permission Controller** | `/src/Controller/PermissionController.php` | Permission-Übersicht |
| **User Voter** | `/src/Security/Voter/UserVoter.php` | Fine-grained Authorization für User |
| **Weitere Voter** | `/src/Security/Voter/{Asset,Risk,Incident,Control,Document}Voter.php` | Entity-spezifische Voters |

### 5. AUDIT & SICHERHEIT

| Bereich | Dateipfad | Beschreibung |
|---------|-----------|-------------|
| **Audit Log Entity** | `/src/Entity/AuditLog.php` | Automatisiertes Logging aller Änderungen |
| **Audit Subscriber** | `/src/EventSubscriber/AuditLogSubscriber.php` | Doctrine Event Listener |
| **Audit Logger Service** | `/src/Service/AuditLogger.php` | Logging-Service |

---

## Datenflusss für typische Operationen

### Szenario 1: User erstellen und Rolle zuweisen

```
POST /admin/users -> UserManagementController::new()
  ├─ $user = new User()
  ├─ $form = createForm(UserType::class, $user)
  │   ├─ Email
  │   ├─ Name
  │   ├─ Password (hashed via UserPasswordHasher)
  │   ├─ Custom Roles (Multiple-Select)
  │   └─ Tenant (Foreign Key)
  ├─ $entityManager->persist($user)
  ├─ $entityManager->flush()
  └─ AuditLogSubscriber::postPersist() -> AuditLog-Eintrag
```

### Szenario 2: Permission-Check für Aktion

```
Controller::action() -> $this->denyAccessUnlessGranted(UserVoter::VIEW, $user)
  ├─ SecurityContext aufrufen
  ├─ UserVoter::supports() prüft Attribute
  ├─ UserVoter::voteOnAttribute() ausführen
  │   ├─ Wenn ROLE_ADMIN: true
  │   ├─ Wenn inactive: false
  │   └─ Sonst: $user->hasPermission()
  │       └─ Durchsuche $user->customRoles -> foreach permission
  └─ Grant/Deny
```

### Szenario 3: ISMS-Context validieren & speichern

```
POST /context/edit -> ContextController::edit()
  ├─ $context = ISMSContextService->getCurrentContext()
  ├─ $form = createForm(ISMSContextType::class, $context)
  ├─ $errors = ISMSContextService->validateContext($context)
  │   ├─ organizationName NOT NULL
  │   ├─ ismsScope NOT NULL
  │   ├─ ismsPolicy NOT NULL
  │   └─ rolesAndResponsibilities NOT NULL
  ├─ ISMSContextService->saveContext($context)
  │   ├─ setUpdatedAt(new DateTime())
  │   ├─ $entityManager->persist/flush()
  │   └─ AuditLogSubscriber logs change
  └─ Redirect mit Success-Flash
```

### Szenario 4: Tenant-Context für Multi-Tenancy

```
Controller besitzt injiziertes TenantContext-Service
  ├─ $tenant = $tenantContext->getCurrentTenant()
  │   └─ Wird automatisch vom eingeloggten User gelesen
  ├─ Alle DB-Queries MÜSSEN tenant_id filtern
  │   ├─ Repository-Methoden sollten tenant_id Parameter annehmen
  │   └─ Oder TenantContext im Service nutzen
  └─ JoinColumn(nullable: true, onDelete: 'SET NULL') auf allen Foreign Keys
```

---

## Security-Hierarchie

```
Symfony Security (lowest level)
  │
  ├─ IsGranted('ROLE_ADMIN')        → ROLE_ADMIN Role
  ├─ IsGranted('ROLE_SUPER_ADMIN')  → ROLE_SUPER_ADMIN Role
  └─ IsGranted(UserVoter::CREATE)   → Permission-Check
      │
      ├─ Bypass: ROLE_ADMIN = true
      ├─ Check: $user->isActive()
      └─ Check: $user->hasPermission('user.create')
          └─ Durchsucht alle customRoles -> permissions
```

---

## Wichtigste Seiten der Architektur

### Tenant Management
- URL: `/admin/tenants`
- Controller: `TenantManagementController`
- Entities: `Tenant`
- Service: `TenantContext`

### User Management
- URL: `/admin/users`
- Controller: `UserManagementController`
- Entities: `User`, `MfaToken`
- Form: `UserType`
- Import/Export: CSV-Format

### Role Management
- URL: `/admin/roles`
- Controller: `RoleManagementController`
- Entities: `Role`, `Permission`
- Templates: Auditor, Risk Manager, Compliance Officer, etc.

### Permission Management
- URL: `/admin/permissions`
- Controller: `PermissionController`
- Entity: `Permission`
- Grouped by: category (risk, asset, control, user, etc.)

### ISMS Context
- URL: `/context`
- Controller: `ContextController`
- Entity: `ISMSContext`
- Service: `ISMSContextService`
- Form: `ISMSContextType`

---

## Environment Setup

### Wichtige ENV-Variablen (in .env)

```env
DATABASE_URL=mysql://user:pass@localhost:3306/isms_db
MAILER_DSN=smtp://...

# Azure SSO (optional)
AZURE_AD_CLIENT_ID=...
AZURE_AD_CLIENT_SECRET=...
AZURE_AD_TENANT_ID=...
```

### Database Migrations

```bash
# Alle Migrations ausführen (einmalig bei Setup)
php bin/console doctrine:migrations:migrate

# Neue Migration erstellen
php bin/console doctrine:migrations:generate
```

---

## Testing & Debugging

### Häufige Debugging-Szenarien

1. **User-Permissions nicht funktionieren**
   - Check: `User::getRoles()` - enthält alle Rollen?
   - Check: `Role::getPermissions()` - sind Permissions zugeordnet?
   - Check: Voter-Logik in `UserVoter::voteOnAttribute()`

2. **Tenant-Isolation nicht funktioniert**
   - Check: Alle Entities haben `tenant_id` FK?
   - Check: Repository-Queries filtern nach `tenant_id`?
   - Check: `TenantContext->getCurrentTenant()` gibt richtigen Tenant?

3. **ISMS-Context fehlerhaft**
   - Check: `ISMSContextService->validateContext()`
   - Check: Review-Datum wird mit `scheduleNextReview()` gesetzt?
   - Check: Completeness wird mit `calculateCompleteness()` berechnet?

### Hilfreiche Console Commands

```bash
# Doctrine-Statistiken
php bin/console doctrine:mapping:info

# Cache-Clearing
php bin/console cache:clear

# Asset Compilation (falls Webpack)
npm run build

# Database Durchsuchen
php bin/console doctrine:database:create
php bin/console doctrine:schema:create
```

