# Phase 5: Advanced Features - Completeness Report

**Project:** Little ISMS Helper
**Phase:** 5 - Advanced Features (Reports, Notifications, API)
**Status:** ✅ **COMPLETE**
**Date:** November 6, 2025
**Symfony Version:** 7.3
**PHP Version:** 8.4

---

## Table of Contents
1. [Phase 5 Overview](#phase-5-overview)
2. [Part 1: PDF/Excel Export System](#part-1-pdfexcel-export-system)
3. [Part 2: Document Management (Deferred)](#part-2-document-management-deferred)
4. [Part 3: Notification Scheduler](#part-3-notification-scheduler)
5. [Part 4: REST API](#part-4-rest-api)
6. [Installation & Configuration](#installation--configuration)
7. [Testing Checklist](#testing-checklist)
8. [Statistics](#statistics)

---

## Phase 5 Overview

Phase 5 adds advanced features for reporting, automation, and integration:

- **PDF/Excel Export System** - Generate professional compliance reports
- **Notification Scheduler** - Automated email reminders for due dates
- **REST API** - JSON API for external integrations
- **Document Management** - Evidence storage system (foundation only, deferred)

**Key Technologies:**
- **Dompdf** 3.1.4 - PDF generation
- **PhpSpreadsheet** 5.2.0 - Excel export
- **API Platform** 4.2.3 - REST API framework
- **Symfony Mailer** - Email notifications
- **Symfony Console** - CLI commands

---

## Part 1: PDF/Excel Export System

### Overview
Complete reporting system for ISO 27001:2022 compliance documentation with professional PDF and Excel exports.

### Implementation

#### 1. Services (2 files)

**src/Service/PdfExportService.php** (59 lines)
```php
class PdfExportService
{
    public function generatePdf(string $template, array $data, array $options = []): string
    public function downloadPdf(string $template, array $data, string $filename, array $options = []): void
    public function streamPdf(string $template, array $data, string $filename, array $options = []): void
}
```
- Uses Dompdf with DejaVu Sans font
- Supports A4/Letter paper, portrait/landscape
- Remote resources enabled for logos
- Twig template rendering

**src/Service/ExcelExportService.php** (115 lines)
```php
class ExcelExportService
{
    public function createSpreadsheet(string $title = 'Export'): Spreadsheet
    public function addHeaderRow(Spreadsheet $spreadsheet, array $headers, int $row = 1): void
    public function addDataRows(Spreadsheet $spreadsheet, array $data, int $startRow = 2): void
    public function generateExcel(Spreadsheet $spreadsheet): string
    public function exportArray(array $data, array $headers, string $sheetName = 'Export'): Spreadsheet
}
```
- Professional headers with gray background (#4A5568)
- Auto-sized columns
- Zebra striping for readability
- PhpSpreadsheet XLSX format

#### 2. Controller

**src/Controller/ReportController.php** (320 lines)
```php
#[Route('/reports')]
#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    // Dashboard Reports
    #[Route('/', name: 'app_reports_index')]
    #[Route('/dashboard/pdf', name: 'app_reports_dashboard_pdf')]
    #[Route('/dashboard/excel', name: 'app_reports_dashboard_excel')]

    // Risk Reports
    #[Route('/risks/pdf', name: 'app_reports_risks_pdf')]
    #[Route('/risks/excel', name: 'app_reports_risks_excel')]

    // Control Reports (Statement of Applicability)
    #[Route('/controls/pdf', name: 'app_reports_controls_pdf')]
    #[Route('/controls/excel', name: 'app_reports_controls_excel')]

    // Incident Reports
    #[Route('/incidents/pdf', name: 'app_reports_incidents_pdf')]
    #[Route('/incidents/excel', name: 'app_reports_incidents_excel')]

    // Training Reports
    #[Route('/trainings/pdf', name: 'app_reports_trainings_pdf')]
    #[Route('/trainings/excel', name: 'app_reports_trainings_excel')]
}
```

**Features:**
- 11 export endpoints (5 PDF + 5 Excel + 1 index)
- Dynamic filename generation (includes date)
- Professional report templates
- Statistics aggregation

#### 3. Templates (6 files)

**templates/reports/index.html.twig** (215 lines)
- Professional reports dashboard
- 5 report cards (Dashboard, Risk Register, SoA, Incidents, Training)
- PDF and Excel download buttons
- Best practices guidance
- ISO 27001:2022 compliance notes

**PDF Templates:**

**templates/reports/dashboard_pdf.html.twig** (180 lines)
- Executive summary format
- Statistics grid (8 metrics)
- Color-coded risk levels
- Compliance percentage
- Generated timestamp

**templates/reports/controls_pdf.html.twig** (195 lines)
- **Landscape format** (wide tables)
- Statement of Applicability (SoA)
- All 93 ISO 27001:2022 controls
- Progress bars for implementation
- Applicability status
- Target dates and responsible persons

**templates/reports/risks_pdf.html.twig** (165 lines)
- Risk Register format
- Color-coded risk scores:
  - Critical (≥20): Red #dc2626
  - High (12-19): Orange #f97316
  - Medium (6-11): Yellow #eab308
  - Low (<6): Green #22c55e
- Treatment plan summaries
- Risk owner information

**templates/reports/incidents_pdf.html.twig** (150 lines)
- Incident log format
- Severity badges (critical, high, medium, low)
- Status tracking
- Detection and resolution dates
- Reporter information

**templates/reports/trainings_pdf.html.twig** (145 lines)
- Training attendance log
- Mandatory training indicator
- Participant counts
- Duration tracking
- Status (planned, confirmed, completed, cancelled)

#### 4. Routes

All routes require `ROLE_USER` authentication:

| Route | Method | Description | Format |
|-------|--------|-------------|---------|
| /reports | GET | Reports dashboard | HTML |
| /reports/dashboard/pdf | GET | Executive dashboard | PDF |
| /reports/dashboard/excel | GET | Dashboard statistics | XLSX |
| /reports/risks/pdf | GET | Risk register | PDF |
| /reports/risks/excel | GET | Risk register | XLSX |
| /reports/controls/pdf | GET | Statement of Applicability | PDF (Landscape) |
| /reports/controls/excel | GET | ISO 27001 controls | XLSX |
| /reports/incidents/pdf | GET | Incident log | PDF |
| /reports/incidents/excel | GET | Incident log | XLSX |
| /reports/trainings/pdf | GET | Training log | PDF |
| /reports/trainings/excel | GET | Training log | XLSX |

---

## Part 2: Document Management (Deferred)

**Status:** Foundation implemented, full functionality deferred per user request

### Implemented

**src/Entity/Document.php** (150 lines)
```php
class Document
{
    private ?int $id = null;
    private ?string $filename = null;
    private ?string $originalFilename = null;
    private ?string $mimeType = null;
    private ?int $fileSize = null;
    private ?string $fileHash = null;  // SHA-256 integrity
    private ?string $entityType = null; // Polymorphic relationship
    private ?int $entityId = null;
    private ?User $uploadedBy = null;
    private ?\DateTimeInterface $uploadedAt = null;
    private bool $isArchived = false;

    // Helper methods
    public function getFileSizeFormatted(): string  // "2.5 MB"
    public function getFileExtension(): ?string     // "pdf"
}
```

**src/Repository/DocumentRepository.php** (45 lines)
```php
public function findByEntity(string $entityType, int $entityId): array
public function findByUploader(User $user): array
public function findRecent(int $limit = 10): array
```

### Deferred Components
- FileStorageService (upload/download handling)
- DocumentController (CRUD operations)
- Upload templates and forms
- Entity integrations (Risk, Control, Incident, etc.)

**Reason for Deferral:** User prioritized Notification Scheduler and REST API over document management.

---

## Part 3: Notification Scheduler

### Overview
Automated email notification system for ISMS due dates and reminders using Symfony Console commands for cron execution.

### Implementation

#### 1. Command

**src/Command/SendNotificationsCommand.php** (337 lines)
```php
#[AsCommand(
    name: 'app:send-notifications',
    description: 'Send scheduled email notifications for due dates and reminders'
)]
class SendNotificationsCommand extends Command
{
    // Configuration
    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL,
                'Notification type (audits, trainings, incidents, controls, workflows, all)', 'all')
            ->addOption('days-ahead', 'd', InputOption::VALUE_OPTIONAL,
                'Days ahead to check for upcoming items', 7)
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Dry run - show what would be sent without actually sending');
    }

    // Notification methods
    private function sendAuditNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    private function sendTrainingNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    private function sendIncidentNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    private function sendControlNotifications(int $daysAhead, bool $dryRun, SymfonyStyle $io): int
    private function sendWorkflowNotifications(bool $dryRun, SymfonyStyle $io): int
}
```

**Notification Types:**

1. **Upcoming Audits**
   - Checks: plannedDate between today and +7 days
   - Status: planned, in_progress
   - Recipients: leadAuditor + auditTeam
   - Template: emails/audit_due_notification.html.twig

2. **Upcoming Trainings**
   - Checks: scheduledDate between today and +7 days
   - Status: planned, confirmed
   - Recipients: participants + trainer (if non-mandatory)
   - Mandatory trainings sent to all participants
   - Template: emails/training_due_notification.html.twig

3. **Open Incidents**
   - Checks: detectedDate older than 7 days
   - Status: new, investigating, in_progress
   - Recipients: assignedTo + all admins
   - Template: emails/incident_notification.html.twig

4. **Controls Nearing Target Date**
   - Checks: targetDate between today and +7 days
   - Status: NOT implemented
   - Recipients: responsiblePerson
   - Template: emails/control_due_notification.html.twig

5. **Overdue Workflow Approvals**
   - Checks: WorkflowInstanceRepository::findOverdue()
   - Recipients: currentStep approverUsers
   - Template: emails/workflow_overdue_notification.html.twig

#### 2. Extended EmailNotificationService

**src/Service/EmailNotificationService.php** (134 lines)
```php
class EmailNotificationService
{
    // Existing methods (from Phase 4)
    public function sendIncidentNotification(Incident $incident, array $recipients): void
    public function sendIncidentUpdateNotification(Incident $incident, array $recipients, string $changeDescription): void
    public function sendAuditDueNotification(InternalAudit $audit, array $recipients): void
    public function sendTrainingDueNotification(Training $training, array $recipients): void

    // New methods (Phase 5)
    public function sendControlDueNotification(Control $control, array $recipients): void
    public function sendWorkflowOverdueNotification(WorkflowInstance $instance, array $recipients): void

    // Generic method
    public function sendGenericNotification(string $subject, string $template, array $context, array $recipients): void
}
```

#### 3. Email Templates (2 new)

**templates/emails/control_due_notification.html.twig** (68 lines)
```html
<div class="header" style="background: #0891b2;">
    <h1>🛡️ Control Target Date Approaching</h1>
</div>
```
- Control ID and name
- Target date alert
- Category and implementation status
- Progress bar (visual)
- Implementation notes (truncated)

**templates/emails/workflow_overdue_notification.html.twig** (75 lines)
```html
<div class="header" style="background: #dc2626;">
    <h1>⚠️ Overdue Workflow Approval</h1>
</div>
```
- Red alert styling (urgent)
- Workflow name and related entity
- Current step information
- Step type and status
- Started date and due date
- Action required message

#### 4. Cron Setup

**Recommended Configuration:**
```bash
# Daily at 8 AM - Send all notifications
0 8 * * * php /path/to/bin/console app:send-notifications --type=all

# Separate schedules for different types
0 8 * * * php /path/to/bin/console app:send-notifications --type=audits
0 9 * * * php /path/to/bin/console app:send-notifications --type=trainings
0 10 * * * php /path/to/bin/console app:send-notifications --type=incidents
```

**Usage Examples:**
```bash
# Send all notifications (dry run)
php bin/console app:send-notifications --dry-run

# Send only audit notifications (7 days ahead)
php bin/console app:send-notifications --type=audits --days-ahead=7

# Send incident notifications (14 days old)
php bin/console app:send-notifications --type=incidents --days-ahead=14

# Production run
php bin/console app:send-notifications --type=all
```

**Output Format:**
```
ISMS Notification Service
=========================

 [WARNING] DRY RUN MODE - No emails will be sent

Checking Upcoming Audits
------------------------

  - Audit "Q4 2024 Internal Audit" planned for 15.11.2024 → 3 recipients

 [OK] Sent 1 audit notifications

Checking Upcoming Trainings
----------------------------

  - Training "ISO 27001 Awareness Training" [MANDATORY] scheduled for 12.11.2024 10:00 → 15 recipients

 [OK] Sent 1 training notifications

 [OK] Total notifications sent: 2
```

---

## Part 4: REST API

### Overview
Complete REST API implementation using API Platform 4.2 for external integrations and mobile applications.

### Implementation

#### 1. Configuration

**config/packages/api_platform.yaml** (36 lines)
```yaml
api_platform:
    title: 'Little ISMS Helper API'
    description: 'REST API for Information Security Management System (ISO 27001:2022)'
    version: '1.0.0'

    # Enable Swagger UI documentation
    enable_swagger_ui: true
    enable_re_doc: true

    # API path configuration
    defaults:
        stateless: false  # Use session-based auth (already configured)
        cache_headers:
            vary: ['Content-Type', 'Authorization', 'Origin']
        pagination_enabled: true
        pagination_items_per_page: 30

    # OpenAPI documentation
    openapi:
        contact:
            name: 'Little ISMS Helper'
            email: 'support@little-isms.local'
        license:
            name: 'Proprietary'

    # Formats
    formats:
        jsonld: ['application/ld+json']
        json: ['application/json']
        html: ['text/html']
```

**config/routes/api_platform.yaml** (5 lines)
```yaml
api_platform:
    resource: .
    type: api_platform
    prefix: /api
```

**config/bundles.php** (enabled)
```php
ApiPlatform\Symfony\Bundle\ApiPlatformBundle::class => ['all' => true],
```

#### 2. API Resources (6 entities)

Each entity configured with `#[ApiResource]` attribute:

**src/Entity/Asset.php**
```php
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['asset:read']],
    denormalizationContext: ['groups' => ['asset:write']]
)]
class Asset { ... }
```

**src/Entity/Risk.php**
```php
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['risk:read']],
    denormalizationContext: ['groups' => ['risk:write']]
)]
class Risk { ... }
```

**src/Entity/Control.php** (NEW)
```php
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['control:read']],
    denormalizationContext: ['groups' => ['control:write']]
)]
class Control { ... }
```

**src/Entity/Incident.php**
```php
#[ApiResource(...)]
class Incident { ... }
```

**src/Entity/InternalAudit.php** (NEW)
```php
#[ApiResource(...)]
class InternalAudit { ... }
```

**src/Entity/Training.php** (NEW)
```php
#[ApiResource(...)]
class Training { ... }
```

#### 3. API Endpoints

**Base URL:** `/api`

All endpoints require authentication (session-based). Security is enforced at two levels:
1. **Global:** config/packages/security.yaml - `{ path: ^/, roles: ROLE_USER }`
2. **Resource:** #[ApiResource] security attributes

**Assets API:**
```
GET    /api/assets           - List all assets (paginated)
GET    /api/assets/{id}      - Get single asset
POST   /api/assets           - Create new asset (ROLE_USER)
PUT    /api/assets/{id}      - Update asset (ROLE_USER)
DELETE /api/assets/{id}      - Delete asset (ROLE_ADMIN)
```

**Risks API:**
```
GET    /api/risks            - List all risks
GET    /api/risks/{id}       - Get single risk
POST   /api/risks            - Create new risk (ROLE_USER)
PUT    /api/risks/{id}       - Update risk (ROLE_USER)
DELETE /api/risks/{id}       - Delete risk (ROLE_ADMIN)
```

**Controls API:**
```
GET    /api/controls         - List all ISO 27001 controls
GET    /api/controls/{id}    - Get single control
POST   /api/controls         - Create new control (ROLE_USER)
PUT    /api/controls/{id}    - Update control (ROLE_USER)
DELETE /api/controls/{id}    - Delete control (ROLE_ADMIN)
```

**Incidents API:**
```
GET    /api/incidents        - List all incidents
GET    /api/incidents/{id}   - Get single incident
POST   /api/incidents        - Create new incident (ROLE_USER)
PUT    /api/incidents/{id}   - Update incident (ROLE_USER)
DELETE /api/incidents/{id}   - Delete incident (ROLE_ADMIN)
```

**Internal Audits API:**
```
GET    /api/internal_audits     - List all audits
GET    /api/internal_audits/{id} - Get single audit
POST   /api/internal_audits     - Create new audit (ROLE_USER)
PUT    /api/internal_audits/{id} - Update audit (ROLE_USER)
DELETE /api/internal_audits/{id} - Delete audit (ROLE_ADMIN)
```

**Trainings API:**
```
GET    /api/trainings        - List all trainings
GET    /api/trainings/{id}   - Get single training
POST   /api/trainings        - Create new training (ROLE_USER)
PUT    /api/trainings/{id}   - Update training (ROLE_USER)
DELETE /api/trainings/{id}   - Delete training (ROLE_ADMIN)
```

#### 4. API Documentation

**Swagger UI:**
```
URL: /api/docs
Features:
- Interactive API testing
- Try-it-now functionality
- Request/response examples
- Schema definitions
```

**ReDoc:**
```
URL: /api/docs?ui=re-doc
Features:
- Professional documentation
- Three-panel layout
- Code samples
- Download OpenAPI spec
```

**OpenAPI Specification:**
```
URL: /api/docs.json
Format: OpenAPI 3.0 (JSON)
Use case: Import into Postman, Insomnia, etc.
```

#### 5. Authentication

**Method:** Session-based authentication (uses existing Symfony Security)

**Why session-based?**
- ✅ Already configured in Phase 1
- ✅ Works with web UI seamlessly
- ✅ No JWT key management needed
- ✅ Suitable for small organization tools
- ✅ Can upgrade to JWT later if needed

**Authentication Flow:**
1. User logs in via web UI (/login, /oauth/azure, /saml/login)
2. Session cookie created
3. API requests include session cookie
4. API Platform validates session
5. Security expressions check roles

**Future Enhancement:**
Can add JWT authentication bundle (lexik/jwt-authentication-bundle) for:
- Mobile apps
- Third-party integrations
- Microservices architecture

#### 6. Formats

**JSON-LD (default):**
```json
{
  "@context": "/api/contexts/Risk",
  "@id": "/api/risks/1",
  "@type": "Risk",
  "id": 1,
  "title": "Unauthorized Access to Database",
  "category": "information_security",
  "likelihood": 4,
  "impact": 5,
  "riskScore": 20
}
```

**JSON (simple):**
```json
{
  "id": 1,
  "title": "Unauthorized Access to Database",
  "category": "information_security",
  "likelihood": 4,
  "impact": 5,
  "riskScore": 20
}
```

**HTML:**
Browser-friendly view with navigation

#### 7. Security Configuration

All API operations enforce role-based access control:

| Operation | Required Role | Rationale |
|-----------|---------------|-----------|
| GET (list) | ROLE_USER | Read access for all authenticated users |
| GET (single) | ROLE_USER | Read access for all authenticated users |
| POST (create) | ROLE_USER | Users can create records |
| PUT (update) | ROLE_USER | Users can modify records |
| DELETE | ROLE_ADMIN | Only admins can delete |

**Security is enforced at:**
1. **Firewall level** - config/packages/security.yaml
2. **Resource level** - #[ApiResource] security attribute
3. **Operation level** - Individual operation security

#### 8. Pagination

Default: 30 items per page

**Query Parameters:**
```
GET /api/risks?page=1          # First page
GET /api/risks?page=2          # Second page
GET /api/risks?itemsPerPage=50 # Custom page size
```

**Response Headers:**
```
Link: </api/risks?page=1>; rel="first",
      </api/risks?page=2>; rel="next",
      </api/risks?page=5>; rel="last"
```

---

## Installation & Configuration

### 1. Install Dependencies

```bash
composer require "api-platform/core:^4.0"
composer require dompdf/dompdf
composer require phpoffice/phpspreadsheet
```

### 2. Enable API Platform

Already enabled in:
- `config/bundles.php` - ApiPlatformBundle
- `config/packages/api_platform.yaml` - Configuration
- `config/routes/api_platform.yaml` - Routes

### 3. Configure Cron for Notifications

```bash
# Edit crontab
crontab -e

# Add notification scheduler (daily at 8 AM)
0 8 * * * cd /path/to/Little-ISMS-Helper && php bin/console app:send-notifications --type=all >> /var/log/isms-notifications.log 2>&1
```

### 4. Test Notification Command

```bash
# Dry run (no emails sent)
php bin/console app:send-notifications --dry-run

# Test specific type
php bin/console app:send-notifications --type=audits --dry-run --days-ahead=14

# Production run
php bin/console app:send-notifications --type=all
```

### 5. Access API Documentation

After starting the development server:
```bash
symfony server:start
```

Visit:
- **Swagger UI:** http://localhost:8000/api/docs
- **ReDoc:** http://localhost:8000/api/docs?ui=re-doc
- **OpenAPI JSON:** http://localhost:8000/api/docs.json

---

## Testing Checklist

### Part 1: PDF/Excel Exports

- [ ] **Dashboard PDF**
  - Navigate to /reports
  - Click "Download PDF" under Dashboard Summary
  - Verify PDF contains: statistics, generated date
  - Check formatting: headers, tables, colors

- [ ] **Dashboard Excel**
  - Click "Download Excel" under Dashboard Summary
  - Open in Excel/LibreOffice
  - Verify: headers styled, data accurate, auto-sized columns

- [ ] **Risk Register PDF**
  - Click "Download PDF" under Risk Register
  - Verify: all risks listed, color-coded scores, treatment plans
  - Check: critical (red), high (orange), medium (yellow), low (green)

- [ ] **Risk Register Excel**
  - Click "Download Excel" under Risk Register
  - Verify: 9 columns (ID, Title, Category, Likelihood, Impact, Score, Treatment, Status, Owner)
  - Check: zebra striping, proper data types

- [ ] **Statement of Applicability PDF**
  - Click "Download PDF" under Statement of Applicability
  - Verify: landscape orientation, all controls listed
  - Check: control IDs, names, categories, implementation status, progress bars

- [ ] **Statement of Applicability Excel**
  - Click "Download Excel" under Statement of Applicability
  - Verify: 8 columns (Control ID, Name, Category, Applicability, Status, Progress, Responsible, Target Date)

- [ ] **Incident Log PDF**
  - Click "Download PDF" under Incident Log
  - Verify: all incidents, severity badges, status, dates

- [ ] **Incident Log Excel**
  - Click "Download Excel" under Incident Log
  - Verify: 8 columns, proper formatting

- [ ] **Training Log PDF**
  - Click "Download PDF" under Training Log
  - Verify: trainings listed, mandatory indicator, participant counts

- [ ] **Training Log Excel**
  - Click "Download Excel" under Training Log
  - Verify: 8 columns, mandatory column (Yes/No)

### Part 2: Notification Scheduler

- [ ] **Command Registration**
  ```bash
  php bin/console list | grep notifications
  # Should show: app:send-notifications
  ```

- [ ] **Help Text**
  ```bash
  php bin/console app:send-notifications --help
  # Verify: description, options, examples
  ```

- [ ] **Dry Run Mode**
  ```bash
  php bin/console app:send-notifications --dry-run
  # Should show: "[WARNING] DRY RUN MODE - No emails will be sent"
  ```

- [ ] **Audit Notifications**
  - Create audit with plannedDate = today + 3 days
  - Run: `php bin/console app:send-notifications --type=audits --dry-run`
  - Verify output shows audit notification

- [ ] **Training Notifications**
  - Create training with scheduledDate = today + 5 days
  - Run: `php bin/console app:send-notifications --type=trainings --dry-run`
  - Verify output shows training notification
  - Check: [MANDATORY] label if training is mandatory

- [ ] **Incident Notifications**
  - Create incident with detectedDate = today - 10 days, status = new
  - Run: `php bin/console app:send-notifications --type=incidents --dry-run`
  - Verify output shows incident notification

- [ ] **Control Notifications**
  - Create control with targetDate = today + 4 days, implementationStatus != implemented
  - Run: `php bin/console app:send-notifications --type=controls --dry-run`
  - Verify output shows control notification

- [ ] **Workflow Notifications**
  - Create overdue workflow instance
  - Run: `php bin/console app:send-notifications --type=workflows --dry-run`
  - Verify output shows workflow notification

- [ ] **Email Templates**
  - Remove `--dry-run` flag (ensure mailer configured)
  - Verify emails sent with correct formatting
  - Check: control_due_notification.html.twig (cyan header, progress bar)
  - Check: workflow_overdue_notification.html.twig (red header, urgent styling)

### Part 3: REST API

- [ ] **API Platform Bundle**
  ```bash
  php bin/console debug:config api_platform
  # Should show configuration without errors
  ```

- [ ] **API Routes**
  ```bash
  php bin/console debug:router | grep api
  # Should show: /api, /api/assets, /api/risks, etc.
  ```

- [ ] **Swagger UI**
  - Navigate to: /api/docs
  - Verify: "Little ISMS Helper API" title
  - Check: 6 sections (Assets, Controls, Incidents, Internal Audits, Risks, Trainings)
  - Expand GET /api/risks
  - Click "Try it out" → Execute
  - Verify response (requires login)

- [ ] **ReDoc**
  - Navigate to: /api/docs?ui=re-doc
  - Verify: professional three-panel layout
  - Check: all 6 resources listed
  - Verify: request/response schemas

- [ ] **OpenAPI Spec**
  - Navigate to: /api/docs.json
  - Verify: valid JSON
  - Check: openapi: "3.0.0", info.title, paths

- [ ] **Assets API**
  - Login to web UI
  - GET /api/assets (Postman/curl with session cookie)
  - Verify: pagination, hydra:member array
  - POST /api/assets with valid data
  - Verify: 201 Created, location header
  - GET /api/assets/{id}
  - PUT /api/assets/{id}
  - DELETE /api/assets/{id} (requires ROLE_ADMIN)

- [ ] **Risks API**
  - GET /api/risks
  - Verify: risk scores calculated
  - POST /api/risks
  - PUT /api/risks/{id}
  - DELETE /api/risks/{id} (ROLE_ADMIN)

- [ ] **Controls API**
  - GET /api/controls
  - Verify: ISO 27001 controls, implementation status
  - POST /api/controls
  - PUT /api/controls/{id}
  - DELETE /api/controls/{id} (ROLE_ADMIN)

- [ ] **Incidents API**
  - GET /api/incidents
  - Verify: severity, status, dates
  - POST /api/incidents
  - PUT /api/incidents/{id}
  - DELETE /api/incidents/{id} (ROLE_ADMIN)

- [ ] **Internal Audits API**
  - GET /api/internal_audits
  - Verify: audit details, team members
  - POST /api/internal_audits
  - PUT /api/internal_audits/{id}
  - DELETE /api/internal_audits/{id} (ROLE_ADMIN)

- [ ] **Trainings API**
  - GET /api/trainings
  - Verify: scheduled dates, participants, mandatory flag
  - POST /api/trainings
  - PUT /api/trainings/{id}
  - DELETE /api/trainings/{id} (ROLE_ADMIN)

- [ ] **Security**
  - Try API request without login
  - Verify: 401 Unauthorized or redirect
  - Login as ROLE_USER
  - Try DELETE operation
  - Verify: 403 Forbidden (requires ROLE_ADMIN)
  - Login as ROLE_ADMIN
  - Try DELETE operation
  - Verify: 204 No Content

- [ ] **Pagination**
  - GET /api/risks?page=1
  - Verify: hydra:view with first, last, next
  - GET /api/risks?page=2
  - Verify: different results
  - GET /api/risks?itemsPerPage=10
  - Verify: 10 items returned

- [ ] **Formats**
  - GET /api/risks (default JSON-LD)
  - Verify: @context, @id, @type
  - GET /api/risks with Accept: application/json
  - Verify: simple JSON without @context
  - GET /api/risks in browser
  - Verify: HTML view with navigation

---

## Statistics

### Code Metrics

**Total Phase 5 Files Created:** 16

**By Type:**
- PHP Services: 2 (PdfExportService, ExcelExportService)
- PHP Controller: 1 (ReportController)
- PHP Command: 1 (SendNotificationsCommand)
- PHP Entity: 1 (Document)
- PHP Repository: 1 (DocumentRepository)
- PHP Entities Extended: 3 (Control, InternalAudit, Training - API attributes)
- Twig Templates: 6 (reports)
- Email Templates: 2 (control_due, workflow_overdue)
- Config Files: 2 (api_platform.yaml, api_platform routes)

**Lines of Code:**
- Services: ~170 lines (PDF: 59, Excel: 115)
- ReportController: 320 lines
- SendNotificationsCommand: 337 lines
- EmailNotificationService: +34 lines (2 new methods)
- Templates: ~1,050 lines
- Email Templates: ~140 lines
- **Total Phase 5 Code: ~2,050 lines**

### Features Delivered

**✅ Implemented (100%):**
1. PDF Export System - 5 report types
2. Excel Export System - 5 report types
3. Report Dashboard - Professional UI
4. Notification Scheduler - 5 notification types
5. Cron Command - Full configuration
6. Email Templates - Professional styling
7. REST API - 6 resources
8. API Documentation - Swagger UI + ReDoc
9. Session Authentication - Integrated with existing security

**⏸️ Deferred (Foundation Only):**
1. Document Management - Entity and repository created, full implementation deferred per user request

### Composer Packages Added

| Package | Version | Purpose |
|---------|---------|---------|
| api-platform/core | 4.2.3 | REST API framework |
| dompdf/dompdf | 3.1.4 | PDF generation |
| phpoffice/phpspreadsheet | 5.2.0 | Excel export |
| willdurand/negotiation | 3.1.0 | Content negotiation (API Platform dependency) |
| vich/uploader-bundle | 2.8.1 | File upload handling |

### API Endpoints

**Total API Endpoints:** 30

**By Resource:**
- Assets: 5 (GET collection, GET item, POST, PUT, DELETE)
- Risks: 5
- Controls: 5
- Incidents: 5
- Internal Audits: 5
- Trainings: 5

**Documentation Endpoints:** 3
- Swagger UI: /api/docs
- ReDoc: /api/docs?ui=re-doc
- OpenAPI JSON: /api/docs.json

### Report Endpoints

**Total Report Endpoints:** 11

**By Format:**
- PDF Exports: 5
- Excel Exports: 5
- Dashboard: 1

### Email Notifications

**Notification Types:** 5
- Upcoming Audits
- Upcoming Trainings
- Open Incidents
- Controls Nearing Target Date
- Overdue Workflow Approvals

**Email Templates:** 6 total
- 4 from Phase 4 (incident, incident_update, audit_due, training_due)
- 2 from Phase 5 (control_due, workflow_overdue)

---

## Comparison with ISO 27001:2022

### Clause Coverage

**Clause 7.5: Documented Information**
- ✅ PDF exports for evidence retention
- ✅ Excel exports for data analysis
- ✅ Automated report generation
- ✅ Timestamped documentation

**Clause 9.2: Internal Audit**
- ✅ Audit due reminders
- ✅ Audit reports (PDF/Excel)
- ✅ Audit tracking via API

**Clause 9.3: Management Review**
- ✅ Dashboard summary reports
- ✅ Executive reporting (PDF)
- ✅ Performance metrics (Excel)

**Clause 6.2: Information Security Objectives**
- ✅ Training notifications
- ✅ Training logs and reports
- ✅ Awareness tracking

**Clause 8.2: Information Security Risk Assessment**
- ✅ Risk register exports
- ✅ Risk notifications
- ✅ API access for risk data

**Clause 8.3: Information Security Risk Treatment**
- ✅ Control notifications (target dates)
- ✅ Statement of Applicability exports
- ✅ Implementation progress tracking

---

## Future Enhancements

### Potential Phase 6 Features

1. **Complete Document Management**
   - FileStorageService implementation
   - DocumentController with CRUD
   - Upload UI and templates
   - Entity integrations (attach documents to risks, controls, etc.)

2. **JWT Authentication for API**
   - Install lexik/jwt-authentication-bundle
   - Generate JWT keys
   - Add /api/login_check endpoint
   - Mobile app support

3. **Advanced API Features**
   - Filtering (GET /api/risks?category=technical)
   - Sorting (GET /api/risks?order[riskScore]=desc)
   - Search (GET /api/risks?title=database)
   - Partial responses (fields parameter)

4. **Batch Operations**
   - Bulk risk assessment
   - Bulk control implementation status updates
   - CSV import/export

5. **Real-time Notifications**
   - WebSocket integration (Mercure)
   - Browser push notifications
   - Slack/Teams integrations

6. **Advanced Reporting**
   - Custom report builder
   - Scheduled reports (monthly, quarterly)
   - Chart generation (risk heat maps, trend analysis)
   - Multi-format export (Word, PowerPoint)

7. **Audit Trail**
   - API request logging
   - Change tracking for all entities
   - Compliance audit reports

---

## Conclusion

Phase 5 is **100% COMPLETE** with the following achievements:

✅ **Professional Export System**
- 5 PDF report types with color-coded formatting
- 5 Excel exports with styled headers and zebra striping
- Report dashboard with best practices guidance

✅ **Automated Notification System**
- 5 notification types covering all major ISMS activities
- Cron-ready console command with dry-run mode
- Professional email templates with responsive styling

✅ **Complete REST API**
- 6 resources (Asset, Risk, Control, Incident, InternalAudit, Training)
- 30 CRUD endpoints with role-based security
- Interactive API documentation (Swagger UI + ReDoc)
- Session-based authentication (easily upgradeable to JWT)

✅ **ISO 27001:2022 Compliance**
- Evidence retention (PDF exports)
- Audit documentation (reports + notifications)
- Risk management (reports + API)
- Control tracking (SoA reports + notifications)

**Document Management** has a solid foundation (entity and repository) but full implementation is deferred as requested by the user. It can be completed as Phase 6 if needed.

**Total Phase 5 Code:** ~2,050 lines
**Files Created/Modified:** 16
**API Endpoints:** 30
**Report Types:** 10 (5 PDF + 5 Excel)
**Notification Types:** 5

The Little ISMS Helper now has complete **export**, **notification**, and **API integration** capabilities suitable for small to medium organizations implementing ISO 27001:2022.

---

**Report Generated:** November 6, 2025
**Phase Status:** ✅ COMPLETE
**Next Phase:** Phase 6 (optional: Complete Document Management, Advanced Features)
