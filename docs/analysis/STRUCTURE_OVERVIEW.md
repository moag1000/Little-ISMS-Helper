# Projektstruktur - Überblick

## Framework & Technologie-Stack

```
Framework:  Symfony 7.x
ORM:        Doctrine
DB:         MySQL/MariaDB (utf8mb4)
Frontend:   Twig Templates (Server-Side Rendering)
Security:   Symfony Security Component (Voters)
API:        API Platform (optional REST API)
```

## Hauptverzeichnisse

```
/home/user/Little-ISMS-Helper/
├── bin/
│   └── console                      # Symfony CLI (php bin/console)
├── src/
│   ├── Entity/                      # Doctrine Entities (DB-Modelle)
│   │   ├── Tenant.php               # Multi-Tenancy Root
│   │   ├── User.php                 # User + Azure SSO Fields
│   │   ├── Role.php                 # Custom Roles
│   │   ├── Permission.php           # Permissions (Category + Action)
│   │   ├── ISMSContext.php          # ISO 27001 Clause 4
│   │   ├── MfaToken.php             # Multi-Factor Authentication
│   │   ├── AuditLog.php             # Audit Logging
│   │   └── [~40 weitere Entities]   # Asset, Risk, Control, etc.
│   │
│   ├── Controller/                  # Web Controller (Request Handling)
│   │   ├── TenantManagementController.php    # /admin/tenants
│   │   ├── UserManagementController.php      # /admin/users
│   │   ├── RoleManagementController.php      # /admin/roles
│   │   ├── PermissionController.php          # /admin/permissions
│   │   ├── ContextController.php             # /context
│   │   └── [~45 weitere Controller]         # Risk, Asset, Incident, etc.
│   │
│   ├── Repository/                  # Doctrine Repositories (DB-Queries)
│   │   ├── TenantRepository.php      # findActive(), findByCode()
│   │   ├── UserRepository.php        # findByAzureObjectId(), findOrCreateFromAzure()
│   │   ├── RoleRepository.php        # findWithPermissions()
│   │   ├── PermissionRepository.php  # findAllGroupedByCategory()
│   │   ├── ISMSContextRepository.php # getCurrentContext()
│   │   └── [~40 weitere]
│   │
│   ├── Service/                     # Business Logic Services
│   │   ├── TenantContext.php         # Multi-Tenancy Service (DI)
│   │   ├── ISMSContextService.php    # ISMS-Context-Verwaltung
│   │   ├── AuditLogger.php           # Audit Logging
│   │   ├── EmailNotificationService.php
│   │   ├── WorkflowService.php
│   │   └── [~20 weitere Services]
│   │
│   ├── Form/                        # Symfony Forms
│   │   ├── UserType.php             # User CRUD Form
│   │   ├── ISMSContextType.php       # ISMS-Context Form
│   │   └── [weitere Forms]
│   │
│   ├── Security/                    # Security & Authorization
│   │   ├── Voter/
│   │   │   ├── UserVoter.php         # Fine-grained User Authorization
│   │   │   ├── RoleVoter.php
│   │   │   ├── RiskVoter.php
│   │   │   └── [Asset, Incident, Control, Document Voter]
│   │   ├── LoginSuccessHandler.php   # Post-Login Processing
│   │   └── SamlAuthFactory.php       # Azure SSO Integration
│   │
│   ├── EventSubscriber/             # Doctrine & Symfony Event Listeners
│   │   ├── AuditLogSubscriber.php    # Auto-Log auf postPersist, postUpdate, postRemove
│   │   ├── SecurityEventSubscriber.php
│   │   └── [weitere Subscriber]
│   │
│   ├── Doctrine/                    # Custom Doctrine Extensions
│   │   └── [Custom DBAL Types, etc.]
│   │
│   ├── Command/                     # CLI Commands (php bin/console)
│   │   └── [Custom Console Commands]
│   │
│   └── Kernel.php                   # Symfony Kernel
│
├── templates/                       # Twig Templates (HTML)
│   ├── user_management/
│   │   ├── index.html.twig          # User-Listung
│   │   ├── new.html.twig            # Neuer User
│   │   ├── edit.html.twig
│   │   ├── show.html.twig
│   │   ├── activity.html.twig
│   │   └── mfa.html.twig
│   │
│   ├── role_management/
│   │   ├── index.html.twig          # Rollen-Listung
│   │   ├── new.html.twig
│   │   ├── show.html.twig
│   │   ├── compare.html.twig        # Rollen-Vergleich
│   │   └── templates.html.twig      # Rollen-Vorlagen
│   │
│   ├── permission/
│   │   ├── index.html.twig
│   │   └── show.html.twig
│   │
│   ├── context/
│   │   ├── index.html.twig
│   │   └── edit.html.twig
│   │
│   ├── admin/
│   │   └── tenants/
│   │       ├── index.html.twig
│   │       ├── form.html.twig
│   │       └── show.html.twig
│   │
│   ├── _components/                 # Shared Twig Components
│   │   └── [Modal, Form, etc.]
│   │
│   ├── bundles/
│   │   └── TwigBundle/Exception/    # Error Pages
│   │
│   └── base.html.twig               # Master Template
│
├── migrations/                      # Doctrine Migration Files
│   └── Version20251113140643.php    # Latest Migration (Vollständiges Schema)
│
├── config/
│   ├── packages/                    # Symfony Service Configuration
│   │   ├── security.yaml            # Security, Firewalls, Access Control
│   │   ├── doctrine.yaml            # Database Configuration
│   │   ├── api_platform.yaml        # API Platform Config
│   │   └── [weitere]
│   │
│   ├── services.yaml                # Service Definition & DI
│   ├── routes.yaml                  # URL Routing
│   └── bundles.php                  # Symfony Bundles
│
├── public/                          # Web Root
│   ├── index.php                    # Entry Point
│   ├── css/                         # Stylesheets
│   ├── js/                          # JavaScript
│   └── uploads/                     # User Uploads
│
├── translations/                    # i18n (Multi-Language)
│   ├── de/                          # German translations
│   ├── en/                          # English translations
│   └── [weitere Sprachen]
│
├── tests/                           # Unit & Functional Tests
│   ├── Unit/
│   ├── Functional/
│   └── Integration/
│
├── docker/                          # Docker Configuration
│   ├── php/                         # PHP Dockerfile
│   └── nginx/                       # Nginx Configuration
│
├── .env                             # Environment Variables
├── .env.local                       # Local Overrides (not in git)
├── .env.dev                         # Development Environment
├── .env.test                        # Test Environment
├── .env.prod.example                # Production Example
├── .env.docker                      # Docker Environment
│
├── docker-compose.yml               # Docker Compose Definition
├── docker-compose.prod.yml          # Production Compose
│
├── composer.json                    # PHP Dependencies
├── composer.lock                    # Lock File
├── package.json                     # Node Dependencies (optional)
├── webpack.config.js                # Webpack Config (optional)
│
├── phpunit.xml.dist                 # Test Configuration
├── phpunit.dist.xml
│
├── ARCHITECTURE_ANALYSIS.md         # (This file location) - Detailed Architecture
├── ARCHITECTURE_QUICK_REFERENCE.md  # Quick Reference Guide
├── STRUCTURE_OVERVIEW.md            # This File
├── README.md                        # Project README
├── ROADMAP.md                       # Development Roadmap
├── CHANGELOG.md                     # Version History
├── MIGRATION_GUIDE.md               # Migration Instructions
│
└── .gitignore                       # Git Ignore Rules
```

---

## Datenfluss-Beispiel: User-Management

```
HTTP Request (GET /admin/users)
      ↓
Symfony Router (routes.yaml)
      ↓
UserManagementController::index()
      ├─ denyAccessUnlessGranted(UserVoter::VIEW_ALL)
      │  └─ UserVoter::voteOnAttribute() → Permission Check
      ├─ $userRepository->findAll()
      │  └─ SQL: SELECT * FROM users WHERE tenant_id = ?
      └─ render('user_management/index.html.twig', ['users' => $users])
           ↓
        Twig Template Rendering
           ↓
        HTTP Response (HTML)
```

---

## Security-Workflow

```
User Login
      ↓
Symfony Security (security.yaml)
      ├─ Form Provider (email/password)
      ├─ OR Azure SSO (oauth/saml)
      ├─ UserRepository::findOneBy(['email' => ...])
      │  OR UserRepository::findOrCreateFromAzure($azureData)
      └─ LoginSuccessHandler::onAuthenticationSuccess()
           ├─ Set lastLoginAt
           ├─ Log audit entry
           └─ Redirect to Dashboard
                ↓
        User is now authenticated
                ↓
        Request -> Controller -> denyAccessUnlessGranted()
                ├─ Voter::supports() checks attribute
                ├─ Voter::voteOnAttribute() checks permission
                └─ Grant/Deny Access
```

---

## Database Schema - Kernentitäten

```
┌─────────────────────────────────────────────────────────┐
│                     TENANT (Root)                       │
├─────────────────────────────────────────────────────────┤
│ id (PK) | code | name | azure_tenant_id | is_active    │
└────────────┬────────────────────────────────────────────┘
             │ 1:N
      ┌──────┴───────┬───────────┬──────────┐
      │              │           │          │
      ▼              ▼           ▼          ▼
   USER      ISMS_CONTEXT    ASSET      CONTROL
   (1:N)       (1:N)         (1:N)      (1:N)
   │
   ├─ email
   ├─ roles (JSON)           ┌──────────┬──────────┐
   ├─ password               │          │          │
   ├─ tenant_id (FK)         ▼          ▼          ▼
   ├─ is_active        ROLE  PERMISSION  MFA_TOKEN
   ├─ auth_provider      │       │
   ├─ azure_object_id    │       │
   └─ custom_roles (M:N)─┼───────┤
                         └─ M:N ─┘
                         
   Beziehung: User -> Role -> Permission
   Junction Tables:
   - user_roles (user_id, role_id)
   - role_permissions (role_id, permission_id)
```

---

## Wichtigste Dateien für Entwicklung

### Täglich benutzte Files

| Datei | Zweck | Häufigkeit |
|-------|-------|-----------|
| `/src/Controller/*.php` | Request Handling | Täglich |
| `/src/Entity/*.php` | Datenmodelle | Täglich |
| `/templates/*.html.twig` | HTML Rendering | Täglich |
| `/src/Service/*.php` | Business Logic | Täglich |
| `/config/services.yaml` | Dependency Injection | Oft |
| `/src/Security/Voter/*.php` | Authorization | Oft |

### Weniger häufig benutzte Files

| Datei | Zweck | Wann? |
|-------|-------|-------|
| `/migrations/*.php` | Database Schema | Schema Changes |
| `/config/packages/security.yaml` | Security Config | Security Changes |
| `docker-compose.yml` | Docker Setup | Environment Setup |
| `/src/EventSubscriber/*.php` | Event Handling | Cross-cutting Concerns |

---

## Standards & Conventions

### Namespacing
```php
namespace App\Entity;              // Entities
namespace App\Controller;          // Controller
namespace App\Service;             // Services
namespace App\Repository;          // Repositories
namespace App\Security\Voter;      // Security Voters
namespace App\Form;                // Form Types
namespace App\EventSubscriber;     // Event Listeners
```

### Naming Conventions
```php
Controller:     EntityNameController.php         (UserManagementController)
Entity:         EntityName.php                   (User, Role, Permission)
Repository:     EntityNameRepository.php         (UserRepository)
Service:        ServiceNameService.php           (ISMSContextService)
Voter:          EntityNameVoter.php              (UserVoter)
Form:           EntityNameType.php               (UserType)
Template:       feature/action.html.twig         (user_management/index.html.twig)
```

### Route Naming
```
GET    /admin/users               → user_management_index
POST   /admin/users               → user_management_index
GET    /admin/users/new           → user_management_new
POST   /admin/users/new           → user_management_new
GET    /admin/users/{id}          → user_management_show
GET    /admin/users/{id}/edit     → user_management_edit
POST   /admin/users/{id}/edit     → user_management_edit
POST   /admin/users/{id}/delete   → user_management_delete
GET    /context/                  → app_context_index
```

---

## Development Workflow

### 1. Neue Feature hinzufügen

```bash
# 1. Entity erstellen/ändern
vim src/Entity/NewEntity.php

# 2. Migration generieren
php bin/console doctrine:migrations:generate

# 3. Migration ausführen
php bin/console doctrine:migrations:migrate

# 4. Repository erstellen (falls benötigt)
php bin/console make:repository

# 5. Controller erstellen
vim src/Controller/NewEntityController.php

# 6. Routes definieren
# (in Controller via #[Route()] Attribute)

# 7. Templates erstellen
mkdir -p templates/new_entity/
vim templates/new_entity/index.html.twig

# 8. Tests schreiben
vim tests/Controller/NewEntityControllerTest.php

# 9. Voter erstellen (falls Authorization needed)
vim src/Security/Voter/NewEntityVoter.php
```

### 2. Bug fixen

```bash
# 1. Test schreiben (TDD)
vim tests/...

# 2. Bug reproduzieren
php bin/console cache:clear
# Test im Browser

# 3. Code fixen
vim src/...

# 4. Test verifizieren
php bin/phpunit

# 5. Commit
git commit -m "fix: Beschreibung"
```

### 3. Deploy zu Produktion

```bash
# 1. Latest Code pullen
git pull origin main

# 2. Abhängigkeiten installieren
composer install --no-dev

# 3. Migrations ausführen
php bin/console doctrine:migrations:migrate --env=prod

# 4. Cache leeren
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

# 5. Assets kompilieren (falls Webpack)
npm install
npm run build

# 6. Webpack manifest aktualisieren
php bin/console asset-map:compile
```

