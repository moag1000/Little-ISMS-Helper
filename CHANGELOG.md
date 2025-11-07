# Changelog

All notable changes to Little ISMS Helper will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Complete Document Management UI (file upload, download, viewer)
- JWT Authentication for REST API
- Advanced API filters and search
- Real-time notifications via WebSocket
- Mobile Progressive Web App (PWA)

---

## [1.5.0] - 2025-11-07 - Phase 5 Complete ✅

### Added - Reporting & Integration

#### PDF/Excel Export System
- Professional PDF reports for 5 core modules (Dashboard, Risk Register, SoA, Incidents, Training)
- Excel exports with styled headers, zebra striping, and auto-sized columns
- ReportController with 11 export endpoints
- PdfExportService using Dompdf 3.1.4
- ExcelExportService using PhpSpreadsheet 5.2.0
- Color-coded risk levels and progress bars in reports

#### Notification Scheduler
- Automated email notifications via cron command (`app:send-notifications`)
- 5 notification types: Upcoming Audits, Trainings, Open Incidents, Controls Nearing Target Date, Overdue Workflows
- Configurable notification windows (--days-ahead, --type, --dry-run)
- Professional HTML email templates with responsive styling
- EmailNotificationService with 6 notification methods

#### REST API
- API Platform 4.2.3 integration
- 30 CRUD endpoints across 6 resources (Assets, Risks, Controls, Incidents, Internal Audits, Trainings)
- OpenAPI 3.0 specification with interactive documentation
- Swagger UI and ReDoc interfaces at `/api/docs`
- Session-based authentication with role-based security
- Pagination (30 items per page, customizable)
- JSON-LD and JSON format support

#### Premium Features - Paket B (Quick View & Global Search)
- Global Search with Cmd+K/Ctrl+K shortcut
- Quick View modal with Space shortcut on list items
- Smart filter presets for quick data filtering
- Search across all entities (Assets, Risks, Controls, Incidents, Trainings)
- Keyboard navigation support

#### Premium Features - Paket C (Dark Mode & User Preferences)
- Dark Mode with automatic system preference detection
- Theme toggle with LocalStorage persistence
- User Preferences system (view density, animations, keyboard shortcuts)
- Notification Center with in-app notifications and history
- Export/Import preferences functionality

### Changed
- Document Management foundation laid (Entity and Repository only, full implementation deferred)

### Statistics
- **~2,050 new lines of code**
- **16 new/modified files**
- **30 API endpoints**
- **10 report types (5 PDF + 5 Excel)**
- **5 notification types**

### Documentation
- [Phase 5 Completeness Report](docs/PHASE5_COMPLETENESS_REPORT.md)
- [Phase 5 Paket B Documentation](docs/PHASE5_PAKET_B.md)
- [Phase 5 Paket C Documentation](docs/PHASE5_PAKET_C.md)
- [API Setup Guide](docs/API_SETUP.md)

---

## [1.4.0] - 2025-11-06 - Phase 4 Complete ✅

### Added - CRUD & Workflows

#### Form Types with Validation
- InternalAuditType.php (163 lines) - ISO 27001 Clause 9.2 compliant
- TrainingType.php (198 lines) - Comprehensive training management
- ControlType.php (179 lines) - ISO 27001:2022 Annex A control management
- ManagementReviewType.php (180 lines) - ISO 27001 Clause 9.3 compliant
- ISMSContextType.php (151 lines) - ISO 27001 Clause 4 compliant

#### Controllers
- TrainingController.php (103 lines) - Full CRUD operations
- AuditController.php (143 lines) - Migrated to form-based architecture
- ManagementReviewController.php (113 lines) - Full CRUD operations
- ISMSObjectiveController.php (135 lines) - KPI tracking with progress visualization
- WorkflowController.php (197 lines) - Complete workflow management
- ContextController.php (65 lines) - Extended with edit functionality

#### Workflow Engine
- Workflow.php Entity (147 lines) - Workflow definitions
- WorkflowStep.php Entity (158 lines) - Individual workflow steps
- WorkflowInstance.php Entity (230 lines) - Running workflow instances
- WorkflowService.php (243 lines) - Workflow execution logic
- WorkflowRepository & WorkflowInstanceRepository with custom queries
- Support for approval/rejection/cancellation with audit trail

#### Risk Assessment Matrix
- RiskMatrixService.php (213 lines) - 5x5 matrix visualization
- Color-coded risk levels (Critical/Red, High/Orange, Medium/Yellow, Low/Green)
- Risk statistics and aggregations
- Matrix cell color calculation

#### Templates
- 30+ professional Bootstrap 5 templates
- Training templates (4 files, ~2,400 lines)
- Audit templates (3 files, ~2,800 lines)
- Management Review templates (4 files, ~2,500 lines)
- ISMS Objectives templates (4 files, ~1,200 lines)
- Turbo integration for real-time updates

### Fixed
- Security import compatibility with Symfony 7 (`Symfony\Bundle\SecurityBundle\Security`)
- API Platform routes deactivated (bundle not installed)

### Statistics
- **~15,000 new lines of code**
- **40+ new/modified files**
- **7 controllers (3 new, 4 updated)**
- **5 form types**
- **30+ templates**

### Documentation
- [Phase 4 Completeness Report](docs/PHASE4_COMPLETENESS_REPORT.md)

---

## [1.3.0] - 2025-11-05 - Phase 3 Complete ✅

### Added - User Management & Security

#### Authentication & Authorization
- Multi-provider authentication (Local, Azure OAuth 2.0, Azure SAML)
- User Entity with Azure AD integration
- Custom authenticators for OAuth and SAML flows
- Remember Me functionality (1 week)
- User impersonation for Super Admins (switch_user)

#### Role-Based Access Control (RBAC)
- Role Entity with system and custom roles support
- Permission Entity with granular access control
- 5 system roles: SUPER_ADMIN, ADMIN, MANAGER, AUDITOR, USER
- 29 default permissions across all modules
- Role hierarchy implementation
- UserVoter for fine-grained access control

#### Audit Logging
- AuditLog Entity with comprehensive change tracking
- AuditLogListener for automatic change detection
- Captures: entity type, entity ID, action, user, IP, user agent, old/new values
- AuditLogController with 5 views (index, detail, entity history, user activity, statistics)
- Audit log UI with filtering and search
- Automatically logs changes to 14+ entity types

#### Multi-Language Support
- Translation system for German (DE) and English (EN)
- 60+ translations in messages.de.yaml and messages.en.yaml
- Language switcher in navigation
- Route-based locale management

#### User Management UI
- UserManagementController with full CRUD (190 lines)
- 4 professional templates (47KB total)
- User activation/deactivation
- Role assignment (system + custom roles)
- Statistics dashboard
- Delete confirmation modals

### Statistics
- **~5,000 new lines of code**
- **25+ new files**
- **4 new entities (User, Role, Permission, AuditLog)**

### Documentation
- [Phase 3 Completeness Report](docs/PHASE3_COMPLETENESS_REPORT.md)
- [Authentication Setup Guide](docs/AUTHENTICATION_SETUP.md)
- [Audit Logging Documentation](docs/AUDIT_LOGGING.md)
- [Audit Logging Quickstart](docs/AUDIT_LOGGING_QUICKSTART.md)

---

## [1.2.0] - 2025-11-05 - Phase 2 Complete ✅

### Added - Data Reuse & Multi-Framework Compliance

#### Business Continuity Management (BCM)
- BusinessProcess Entity with BIA data (RTO, RPO, MTPD)
- Business impact scoring (financial, reputational, regulatory, operational)
- Process criticality assessment
- BCM → Asset protection requirements data reuse
- BusinessProcessController with full CRUD (208 lines)
- BusinessProcessType form (180 lines)
- 9 BCM templates with Turbo integration

#### Multi-Framework Compliance
- ComplianceFramework and ComplianceRequirement entities
- Hierarchical requirements (core → detailed → sub-requirements)
- TISAX (VDA ISA) - 32 requirements loaded
- EU-DORA - 30 requirements loaded
- LoadTisaxRequirementsCommand and LoadDoraRequirementsCommand

#### Cross-Framework Mappings
- ComplianceMapping Entity with bidirectional mappings
- Mapping types: weak, partial, full, exceeds (with percentages)
- ComplianceMappingService for data reuse analysis
- ComplianceAssessmentService for fulfillment calculations
- Transitive compliance calculation

#### Flexible Audit System
- InternalAudit with flexible scope types (full_isms, framework, asset, location, department)
- AuditChecklist Entity with verification status
- Compliance-framework-specific audits
- Asset-scoped audits

#### Entity Relationships (Data Reuse)
- Incident ↔ Asset (affectedAssets, Many-to-Many)
- Incident ↔ Risk (realizedRisks, Many-to-Many)
- Control ↔ Asset (protectedAssets, Many-to-Many)
- Training ↔ Control (coveredControls, Many-to-Many)
- BusinessProcess ↔ Risk (identifiedRisks, Many-to-Many)

#### Automatic KPIs
- Asset: getRiskScore(), getProtectionStatus()
- Risk: wasAssessmentAccurate(), getRealizationCount()
- Control: getEffectivenessScore(), getTrainingStatus()
- Training: getTrainingEffectiveness()
- BusinessProcess: getProcessRiskLevel(), isCriticalityAligned()
- Incident: getTotalAssetImpact(), hasCriticalAssetsAffected()

#### Progressive Disclosure UI
- Tab-based navigation in framework dashboards
- Collapsible sections for hierarchical requirements
- Circular SVG progress charts with color coding
- Always-visible stats bar
- Filter panels (hidden by default)
- Reduced button clutter (~70% reduction)

#### Symfony UX Integration
- Stimulus controllers: toggle, chart, filter, modal, notification, csrf_protection, turbo
- Turbo Drive for fast navigation
- Turbo Frames for lazy loading
- Turbo Streams for real-time updates
- Auto-dismiss notifications

### Statistics
- **~1,600 new lines of code**
- **15+ new entities and services**
- **10 features fully implemented**

### Documentation
- [Phase 2 Completeness Report](docs/PHASE2_COMPLETENESS_REPORT.md)
- [Data Reuse Analysis](docs/DATA_REUSE_ANALYSIS.md)
- [UI/UX Implementation Guide](docs/UI_UX_IMPLEMENTATION.md)

---

## [1.1.0] - 2025-11-04 - Phase 1 Complete ✅

### Added - Core ISMS

#### Core Entities
- Asset Entity with CIA (Confidentiality, Integrity, Availability) ratings
- Risk Entity with likelihood/impact assessment and residual risk calculation
- Control Entity for ISO 27001:2022 Annex A controls (93 controls)
- Incident Entity with severity levels and data breach tracking
- InternalAudit Entity for ISO 27001 Clause 9.2
- ManagementReview Entity for ISO 27001 Clause 9.3
- Training Entity for awareness and competence tracking
- ISMSContext Entity for organizational context (Clause 4)
- ISMSObjective Entity for measurable security objectives

#### Controllers & Views
- AssetController with CRUD operations
- RiskController with risk assessment functionality
- StatementOfApplicabilityController for control management
- IncidentController with incident lifecycle tracking
- HomeController with KPI dashboard
- Basic Twig templates for all modules

#### Commands
- `isms:load-annex-a-controls` - Loads all 93 ISO 27001:2022 Annex A controls

#### Infrastructure
- Symfony 7.3 project setup
- PostgreSQL/MySQL database configuration
- Doctrine ORM with migrations
- Bootstrap 5 UI framework
- Twig templating

### Statistics
- **~8,000 lines of initial code**
- **9 core entities**
- **6 controllers**
- **Basic templates for all modules**

### Documentation
- Initial README.md
- Installation instructions
- Basic usage guide

---

## [1.0.0] - 2025-11-01 - Project Initialization

### Added
- Initial project structure
- Symfony 7.3 framework setup
- Basic configuration files
- Git repository initialization

---

## Version History Summary

| Version | Date | Phase | Status | LOC Added | Major Features |
|---------|------|-------|--------|-----------|----------------|
| 1.5.0 | 2025-11-07 | Phase 5 | ✅ Complete | ~2,050 | Reports, API, Notifications, Premium Features |
| 1.4.0 | 2025-11-06 | Phase 4 | ✅ Complete | ~15,000 | CRUD, Workflows, Risk Matrix |
| 1.3.0 | 2025-11-05 | Phase 3 | ✅ Complete | ~5,000 | User Management, RBAC, Audit Logging |
| 1.2.0 | 2025-11-05 | Phase 2 | ✅ Complete | ~1,600 | BCM, Multi-Framework, Data Reuse |
| 1.1.0 | 2025-11-04 | Phase 1 | ✅ Complete | ~8,000 | Core ISMS Entities |
| 1.0.0 | 2025-11-01 | Init | ✅ Complete | - | Project Setup |

**Total Lines of Code:** ~31,650+ lines

---

## Upgrade Guide

### From 1.4.x to 1.5.0

**New Dependencies:**
```bash
composer require "api-platform/core:^4.0"
composer require dompdf/dompdf
composer require phpoffice/phpspreadsheet
```

**Database Changes:**
```bash
# No new migrations, API attributes added to existing entities
php bin/console cache:clear
```

**New Routes:**
- `/reports` - Report dashboard
- `/reports/*/pdf` - PDF exports
- `/reports/*/excel` - Excel exports
- `/api` - REST API base
- `/api/docs` - API documentation

**Cron Setup:**
```bash
# Add to crontab for notification scheduler
0 8 * * * php /path/to/bin/console app:send-notifications --type=all
```

### From 1.3.x to 1.4.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**New Entities:**
- Workflow
- WorkflowStep
- WorkflowInstance

**New Features:**
- Form-based CRUD for all modules
- Workflow approval system
- Risk assessment matrix

### From 1.2.x to 1.3.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**Setup Permissions:**
```bash
php bin/console app:setup-permissions
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=SecurePassword123!
```

**New Entities:**
- User
- Role
- Permission
- AuditLog

### From 1.1.x to 1.2.0

**Database Migrations:**
```bash
php bin/console doctrine:migrations:migrate
```

**Load Compliance Frameworks:**
```bash
php bin/console app:load-tisax-requirements
php bin/console app:load-dora-requirements
```

**New Entity:**
- BusinessProcess
- ComplianceFramework
- ComplianceRequirement
- ComplianceMapping
- AuditChecklist

---

## Contributors

Special thanks to all contributors who have helped shape Little ISMS Helper!

- Development led by the project maintainers
- Built with assistance from Claude AI (Anthropic)

---

## License

Proprietary - All rights reserved

---

**Maintained by:** moag1000
**Documentation:** [README.md](README.md) | [CONTRIBUTING.md](CONTRIBUTING.md)
**Support:** Open an issue on GitHub
