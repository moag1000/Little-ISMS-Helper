# Changelog

All notable changes to Little ISMS Helper will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- JWT Authentication for REST API
- Advanced API filters and search
- Real-time notifications via WebSocket
- Mobile Progressive Web App (PWA)

---

## [1.10.0] - 2025-11-20

### Added

#### Risk Management (6 Priorities)

#### Priority 1.4: Risk Owner Mandatory (ISO 27001 Compliance)
- **Mandatory Risk Owner** - All risks must have an assigned owner (ISO 27001 requirement)
- **Validation** - Form validation ensures risk owner is set
- **Migration Support** - Existing risks updated to require owner

#### Priority 1.5: Periodic Review Workflow (ISO 27001 Clause 6.1.3.d)
- **Automated Review Tracking** - Periodic risk review reminders
- **Review Date Management** - Track last and next review dates
- **Overdue Notifications** - Alert on overdue risk reviews
- **Dashboard Widgets** - Overdue and upcoming review visibility

#### Priority 2.1: Risk Acceptance Workflow
- **Risk Acceptance Process** - Formal risk acceptance workflow
- **Acceptance Documentation** - Track acceptance decisions and justifications
- **Management Approval** - Workflow for management sign-off
- **Audit Trail** - Complete acceptance history

#### Priority 2.2: GDPR/DSGVO Risk Fields (High Impact)
- **Personal Data Flags** - Track if risk involves personal data
- **Special Category Data** - Flag for Art. 9 GDPR special categories
- **DPIA Requirement** - Automatic DPIA requirement detection
- **Risk Categories** - Financial, operational, compliance, strategic, reputational, security

#### Priority 2.3: Risk Assessment Guidance
- **Interactive Guidance** - Step-by-step risk assessment help
- **Best Practices** - ISO 27005 aligned guidance
- **Context Help** - Field-level tooltips and explanations

#### Priority 2.4: Treatment Plan Monitoring
- **Treatment Plan Widgets** - Dashboard monitoring for treatment plans
- **Overdue Tracking** - Identify overdue treatment plans
- **Progress Monitoring** - Track treatment plan completion
- **Approaching Deadlines** - 7-day warning for due plans

#### Multi-Subject Risk Support
- **Extended Risk Relationships** - Risks can now be linked to:
  - Assets (existing)
  - Persons (new)
  - Locations (new)
  - Suppliers (new)
- **Flexible Risk Modeling** - Better real-world risk representation
- **Template Updates** - All risk templates support multi-subject display

#### GDPR/DSGVO Features

#### CRITICAL-06: ProcessingActivity (VVT/ROPA - Art. 30 GDPR)
- **Complete VVT/ROPA Implementation** - Article 30 GDPR compliance
- **ProcessingActivity Entity** - Full data processing registry
- **VVT Controller** - CRUD operations for processing activities
- **VVT Service** - Business logic for Art. 30 compliance
- **PDF Export** - Professional VVT/ROPA reports
- **UI Integration** - Complete user interface

#### CRITICAL-07: DPIA (Data Protection Impact Assessment)
- **DPIA Entity** - Complete DPIA implementation
- **DPIA Workflow** - Structured assessment process
- **Risk Scoring** - Automated DPIA risk calculation
- **Threshold Triggers** - Automatic DPIA requirement detection
- **DPIA Templates** - Index, show, and form templates
- **Integration** - Links to ProcessingActivity and Risks

#### CRITICAL-08: DataBreach (72h Notification Requirement)
- **DataBreach Entity** - Art. 33/34 GDPR compliance
- **72-Hour Tracking** - Automatic deadline calculation
- **Severity Assessment** - Impact and likelihood scoring
- **Notification Management** - Track authority and data subject notifications
- **DataBreach Service** - Business logic and deadline tracking
- **Complete UI** - Index, show, create, edit templates

#### Business Continuity Management
- **Incident ↔ BusinessProcess** - Link incidents to affected processes
- **Impact Analysis** - Calculate incident impact on BCM
- **IncidentBCMImpactService** - Automated impact assessment
- **RTO/RPO Impact** - Track recovery time/point objectives

#### UI/UX Improvements

**Badge Standardization** (Issues 5.1 & 5.2)
- **BadgeExtension Twig Helper** - Centralized badge rendering
- **Consistent Styling** - Standardized colors and icons across all modules
- **Dark Mode Support** - All badges work in light and dark themes
- **32+ Table Migrations** - All major tables migrated to standardized components
  - Batch 3-27 completed (Admin, GDPR, BCM, Compliance, Risk, Asset, Audit, Workflow, etc.)

**Accessibility** (WCAG 2.1 AA)
- **Form Accessibility** - Complete form migration to WCAG 2.1 AA (Issues 2.1 & 2.3)
- **Skip Links** - Keyboard navigation improvements (Issue 13.1)
- **Screen Reader Support** - Proper ARIA labels and descriptions
- **Keyboard Navigation** - Full keyboard accessibility

**Navigation**
- **10 Missing Menu Entries** - Added navigation for implemented features
- **Privacy Hub** - New GDPR/Privacy central navigation point
- **Breadcrumb Fixes** - Improved navigation breadcrumbs
- **Menu Organization** - Better feature discoverability

#### Internationalization
- **Risk Module** - 94+ new translations (CRITICAL-04)
- **Risk Matrix Translations** - Complete German support
- **Person & Risk Appetite Pages** - Fully translated
- **Risk Treatment Plan** - All form sections translated
- **Risk Show Page** - Replaced all hardcoded English text

### Fixed
- **Null Tenant Checks** - Prevent TypeError when user has no tenant (4 controllers)
- **Security Event Handling** - Fixed null passport in login failures
- **PDF Template Fixes** - Corrected Risk entity property references
- **Form Type Fixes** - Control entity method corrections
- **GDPR Template Fixes** - VVT, DPIA, DataBreach template corrections

**Database & Migrations**
- **ProcessingActivity Migration** - Fixed missing table (deployment blocker)
- **Sequential Migration Fix** - Migrations now run cleanly from empty database
- **Reset Script Reliability** - Improved table detection (COUNT vs SHOW TABLES)
- **Foreign Key Order** - Correct entity dependency ordering

**Backup & Restore**
- **Risk Field Defaults** - Backward compatibility for old backups
  - Default category: 'operational'
  - Default GDPR flags: false
- **No Data Loss** - Old backups restore successfully with sensible defaults

**Testing**
- Test coverage improved to 60% (1618 tests, 4711 assertions)
- Fixed 32 test errors (non-existent entity method mocks)
- Suppressed expected warnings in backup/restore tests

### Changed
- Badge styling standardized across all modules (32 batches)
- Forms migrated to WCAG 2.1 AA accessibility standard
- Navigation menu expanded with 10 previously missing entries

### Technical Details
- 119 commits since v1.9.1
- 6 Risk Management priorities completed
- 3 GDPR features completed (VVT, DPIA, DataBreach)
- 94 German translations added
- All changes backward compatible

### Notes
Versions 1.8.x - 1.9.1 were not documented in CHANGELOG. This release consolidates those undocumented changes.

---

## [1.7.1] - 2025-11-17 - Critical Hotfix: Backup Restore Functionality

### Fixed

#### Critical Restore Issues
- **Foreign Key Constraint Violations** - Disabled FK checks during restore (SET FOREIGN_KEY_CHECKS = 0)
- **Entity ID Preservation** - Original IDs from backup are now preserved using AssignedGenerator
- **DateTime Type Mismatches** - Automatic conversion between DateTime and DateTimeImmutable
- **Lifecycle Callback Conflicts** - Disabled PrePersist/PreUpdate listeners during restore
- **Entity Dependency Ordering** - Fixed Asset, Supplier, InterestedParty to load before Risk

#### Entity DateTime Fixes
- **Supplier Entity** - Fixed updateTimestamps() to use DateTime instead of DateTimeImmutable
- **InterestedParty Entity** - Fixed updateTimestamps() to use DateTime instead of DateTimeImmutable

#### Password Security
- **Admin Password Option** - Option to set admin password during restore (passwords not stored in backups for security)
- **Password Warning** - Clear warning when no admin password is provided
- **Setup Wizard Support** - Required admin password field in setup wizard restore

### Added

#### User Experience
- **Admin Password Field** - Form field to set admin password during restore
- **Security Information** - Clear explanation why passwords are not in backups
- **Automatic Password Setting** - First admin user gets password set after restore

---

## [1.7.0] - 2025-11-17 - Backup/Restore System Overhaul & Setup Wizard Integration

### Added

#### Backup Restore in Setup Wizard
- **Setup Step 9 Integration** - Restore backups directly during initial setup
- **Migration Support** - Easy migration from other Little ISMS Helper instances
- **Clear Before Restore Option** - Clean restore for consistent database state
- **File Upload Support** - Upload .json or .gz backup files during setup

#### Enhanced Restore Service
- **ManyToOne Relation Support** - Associations are now restored (not just scalar fields)
- **Unique Constraint Detection** - Prevents duplicate key errors for Role, Permission, User, Tenant, ComplianceFramework, Control, ComplianceRequirement
- **Entity Dependency Ordering** - 30+ entities ordered by foreign key dependencies
- **Clear Before Restore** - Option to delete all existing data before restore
- **Robust Error Recovery** - EntityManager state checks and safe rollback

#### Project Support
- **BuyMeACoffee Link** - Support development via donations (README.md)

### Fixed

#### Backup Modal Display Issues
- **Custom Modal Pattern** - Replaced Bootstrap Modal JS with custom CSS-based modals
- **Consistent with Global Search** - Same pattern as working command palette
- **Proper Scrolling** - Modal body scrolls, header/footer fixed
- **Backdrop and ESC Handling** - Click outside or ESC to close

#### Database Restore Reliability
- **EntityManager Close Prevention** - Checks if EM is open before operations
- **Safe Rollback Logic** - Handles closed EntityManager during rollback
- **Flush Error Handling** - Catches and logs constraint violations without crashing
- **Transaction Safety** - Proper transaction management throughout restore

#### Unique Constraint Conflicts
- **ID + Unique Field Lookup** - Finds existing entities by both primary key and unique constraints
- **Conflict Detection** - Warns when backup ID wants a value owned by different existing ID
- **Skip with Warning** - Gracefully skips conflicts instead of crashing

### Changed

#### CLAUDE.md Optimization
- **Pre-Commit/Push Checklist** - Mandatory quality checks before commits
- **Token Efficiency** - Reduced from ~254 to 134 lines (47% reduction)
- **Common Pitfalls Section** - Documents solved issues for future reference
- **Focused Content** - Essential information only, external docs when needed

#### RestoreService Improvements
- **Entity Priority Map** - Extended to 30+ entities with proper dependency order
- **Association Restoration** - Uses `getReference()` for ManyToOne relations
- **Better Logging** - Debug logs for conflict detection and entity processing
- **Statistics Tracking** - Tracks cleared, created, updated, skipped, error counts

### Security

- **Backup File Validation** - Only .json and .gz files accepted
- **CSRF Protection** - All restore forms protected
- **Tenant Isolation** - Backup/restore respects multi-tenant boundaries
- **Audit Logging** - All restore operations logged

### Statistics
- **~800 new lines of code** in RestoreService
- **~200 new lines** in DeploymentWizardController
- **~120 lines** of custom modal CSS
- **6 files modified** (RestoreService, AdminBackupController, DeploymentWizardController, backup.html.twig, step9_sample_data.html.twig, README.md)
- **1 file optimized** (CLAUDE.md - 47% smaller)
- **909 tests passing** (2573 assertions)

### Documentation
- Updated CLAUDE.md with pre-commit/push quality checklist
- Added common pitfalls and troubleshooting guide
- Documented Modal and Turbo patterns
- Security checklist for new features

---

## [1.6.4] - 2025-11-16 - Compliance Framework CRUD & Workflow Builder

### Added - Phase 6C & 6D Complete

#### Compliance Framework CRUD (Phase 6D)
- **ComplianceFrameworkController** - Full CRUD operations for frameworks
- **Framework Index Page** - List, search, and manage compliance frameworks
- **Framework Show Page** - Detailed view with requirements and mappings
- **Framework Create/Edit** - Form-based framework management
- **Cache Invalidation Subscriber** - Automatic cache clearing on framework changes
- **ComplianceExtension Twig** - Helper functions for compliance templates

#### Workflow Builder (Phase 6C)
- **Visual Workflow Builder** - Drag-and-drop step management
- **WorkflowStepApiController** - RESTful API for workflow steps (554 lines)
- **WorkflowStepType Form** - Comprehensive step configuration
- **Sidebar Dropdown Controller** - Interactive navigation for workflows
- **workflow_builder_controller.js** - Frontend logic (494 lines)

#### Enhanced Service Tests (~5,000 new test lines)
- **AssetServiceTest** (282 lines)
- **AuditLoggerTest** (420 lines)
- **AutomatedGapAnalysisServiceTest** (309 lines)
- **ComplianceAssessmentServiceTest** (481 lines)
- **ControlServiceTest** (258 lines)
- **CorporateStructureServiceTest** (280 lines)
- **DashboardStatisticsServiceTest** (228 lines)
- **DocumentServiceTest** (300 lines)
- **ISMSContextServiceTest** (486 lines)
- **ISMSObjectiveServiceTest** (289 lines)
- **MfaServiceTest** (428 lines)
- **RiskServiceTest** (331 lines)
- **SecurityEventLoggerTest** (473 lines)
- **SupplierServiceTest** (399 lines)
- **WorkflowServiceTest** (333 lines)
- **WorkflowStepApiControllerTest** (459 lines)

#### Navigation & UX Improvements
- **Navigation UX Analysis** - Comprehensive documentation
- **Navigation Patterns Quick Reference** - Best practices guide
- **Improved Breadcrumb Component** - Better hierarchy display
- **Enhanced Page Headers** - Consistent styling

### Fixed

#### Test Infrastructure (59 failures resolved)
- **Symfony 7 Compatibility** - Exception handling updates
- **PHPUnit 10 Compatibility** - Test method signatures fixed
- **Entity Validation** - Constraints aligned with controller expectations
- **Mock Object Improvements** - Better test isolation

#### Security Improvements
- **CSRF Protection** - Added to all form submissions
- **XSS Protection** - Input sanitization for all user data
- **Entity Validation** - Server-side validation for all entities
- **Transaction Management** - Database operations wrapped in transactions
- **Robust Error Handling** - Graceful failure modes

#### Critical Runtime Fixes
- **Null Safety Checks** - Prevent runtime errors
- **AuditLog Property Names** - Corrected to `userName` in templates
- **ISMS Context Tenant Isolation** - Respects current user's tenant
- **MfaToken Entity** - Added missing properties
- **WorkflowStep Entity** - Extended with new fields

### Changed
- **ISMSContextService** - Improved tenant awareness (36+ lines)
- **MfaService** - Enhanced token validation (24+ lines)
- **AuditLogger** - Better error handling
- **BC Exercise Templates** - Improved forms (edit/new)
- **Business Continuity Plan Templates** - Better UX
- **Compliance Mapping/Requirement Templates** - Enhanced forms

### Statistics
- **~11,000+ new lines of code**
- **83 files modified**
- **16 new service test files** (~5,000 test lines)
- **4 new Compliance Framework templates**
- **1 new Workflow Builder UI**
- **59 test failures fixed**
- **137 new translation keys** (DE/EN each)

### Documentation
- [Navigation UX Analysis](NAVIGATION_UX_ANALYSIS.md)
- [Navigation Patterns Quick Reference](NAVIGATION_PATTERNS_QUICK_REFERENCE.md)
- [Updated ROADMAP - Phase 6C & 6D complete](ROADMAP.md)

---

## [1.6.2] - 2025-11-15 - ARM64 Support & CI/CD Fixes

### Added
- **ARM64/ARM Support** - Multi-architecture Docker builds (linux/amd64 + linux/arm64)
- **QEMU Integration** for cross-platform compilation in CI/CD
- Support for Raspberry Pi, Apple Silicon, and other ARM-based systems

### Fixed
- **Trivy SARIF Upload** - Added security-events permission for vulnerability scan uploads
- **Docker Build Timeout** - Increased to 60 minutes for multi-architecture builds
- **Docker Hub Logo Upload** - Automated logo upload on release tags

### Changed
- Build timeout increased from 30 to 60 minutes for multi-arch support
- CI/CD pipeline now properly uploads security scan results to GitHub Security tab

### Technical Details
- Uses docker/setup-qemu-action@v3 for ARM64 emulation
- Uses docker/build-push-action@v5 with platforms: linux/amd64,linux/arm64
- Permissions block added: security-events: write, contents: read, actions: read

---

## [1.6.0] - 2025-11-15 - Enterprise Features ✅

### Added - Multi-Tenancy & Enterprise Management

#### Multi-Tenancy System
- **Corporate Structure Management** with parent-subsidiary relationships
- **Tenant Management UI** with logo upload and configuration
- **Corporate Governance System** with granular rules per control/scope
- **3-Level View Filters** (Own/Inherited/All) across all modules
- **Inheritance Indicators** showing data origin (parent/subsidiary)
- **Subsidiary View Support** in all repositories
- Automatic tenant isolation and data segregation
- Tenant-aware statistics (own/inherited/subsidiaries breakdown)

#### Unified Admin Panel
- **Admin Dashboard** with system overview and health metrics
- **System Configuration UI** with 50+ configurable settings
- **Tenant Management** (CRUD operations, logo upload)
- **User & Access Management** (user impersonation, session tracking)
- **Data Management** (backup, export, import functionality)
- **System Monitoring & Health Checks** with auto-fix capabilities
- **Module Management** with dependency-aware activation
- Vertical sidebar navigation for improved admin UX

#### Security & Access Control
- **Session Management System** with user_sessions table
- **Multi-Factor Authentication (MFA)** with TOTP and backup codes
- **Granular Permission System** with 100+ specific permissions
- **User Impersonation** for troubleshooting (audited)
- **Security Event Logging** to AuditLog database
- Enhanced CSRF protection and session security
- Comprehensive audit trail for all admin actions

#### German Compliance Frameworks
- **BSI IT-Grundschutz** (Security baseline for German organizations)
- **BaFin BAIT/VAIT** (Banking and insurance IT requirements)
- **DSGVO/GDPR** (Data protection compliance)
- **KRITIS** (Critical infrastructure security)
- **NIS2 Directive** (Network and information security)
- **TISAX** (Automotive industry security)
- **EU-DORA** (Digital operational resilience)
- **ISO 27701:2025** with 2019 ↔ 2025 version mapping

#### Compliance Enhancements
- **Module Dependency System** for compliance frameworks
- **Framework Version Support** (e.g., ISO 27701:2019 vs 2025)
- **Incremental Cross-Framework Mapping** generation
- **Click-Through Workflow** for framework compliance
- **Framework Comparison** with bidirectional coverage analysis
- **Gap Analysis** with priority-weighted risk scoring
- **Transitive Compliance** with impact scoring and ROI analysis
- **Mapping Quality Analysis** with Chart.js visualizations

#### Internationalization (i18n)
- **Complete German Translations** (~5,000 translation keys)
- **Complete English Translations** (~5,000 translation keys)
- **Validator Translations** for all form fields (DE/EN)
- **Message Translations** for all UI components (DE/EN)
- Translation verification tools and reports
- Zero missing translations across all modules

#### Accessibility (WCAG 2.1 AA)
- **WCAG 2.1 AA Compliant Forms** across all modules
- **Accessible Bulk Delete Dialogs** with confirmation
- **Table Scope Attributes** and ARIA labels
- **Keyboard Navigation** support throughout
- **Screen Reader Optimization** for all interactive elements
- Semantic HTML and proper heading hierarchy

#### Testing & Quality Assurance
- **60%+ Test Coverage** (up from 40%)
- **400+ Unit Tests** across all services
- **Entity Tests** for all 31 entities
- **Service Tests** for critical business logic
- Comprehensive validation and security tests
- CI/CD with automated test runs

#### Reports & Exports
- **Professional PDF Reports** with clean layout
- **Excel Exports** with multi-tab support
- **CSV Exports** for all compliance modules
- Framework comparison reports
- Gap analysis with root cause analysis
- Data reuse insights with ROI calculations

#### UI/UX Improvements
- **Vertical Sidebar Navigation** replacing horizontal menu
- **Intensified Cyberpunk Fairy Theme** with enhanced effects
- **Clean CSS** - removed 500+ inline styles
- **Responsive Design** improvements for mobile
- **Advanced Filters** on all major modules
- **Audit History Integration** on detail pages
- Standardized page headers and layouts
- Improved table readability and sorting

#### Database & Infrastructure
- **Database Setup Wizard** with web-based installation
- **Automatic Directory Creation** on install/update
- **Health Monitoring** with auto-fix scripts
- **Migration System** for tenant_id setup (31 entities)
- Rollback scripts and comprehensive upgrade guides
- Idempotent migrations with safety checks

#### Docker & DevOps
- **Docker Health Checks** with HTTP redirect support
- **Dedicated /health Endpoint** for container monitoring
- **CI/CD Improvements** - Docker builds only on release tags
- **Docker Hub Integration** with automated logo upload
- **Security Hardening** and data persistence guarantees
- **Trivy Vulnerability Scanning** on all images
- Simplified deployment with wizard integration

### Changed
- **Navigation Structure** - Horizontal menu → Vertical sidebar
- **Admin Features** - Centralized in unified admin panel
- **Module Management** - Moved to admin panel with dependencies
- **Framework Management** - Centralized in admin compliance section
- **CI/CD Pipeline** - Docker builds only on version tags (not every PR)
- **License Compliance** - Enhanced with graceful error handling
- **Session Security** - SameSite=Lax for better compatibility

### Fixed
- **500+ Critical Bug Fixes** from community testing
- **Docker Compose CI/CD** pipeline issues resolved
- **CSRF Token Issues** on login page (cache-control headers)
- **Migration Timestamp** ordering for proper execution
- **Duplicate Translation Keys** (100+ duplicates removed)
- **YAML Syntax Errors** in translation files
- **Null Safety** in SQL queries and entity relationships
- **Doctrine DBAL 4.x** compatibility (replaced deprecated methods)
- **Open Basedir Restrictions** for session storage
- **EntityManager Detached Entity** errors in batch processing
- **Workflow Step 3** field reference (statistics.fulfilled)
- **User Form** - Pre-fill roles and status when editing
- **Risk/Asset Forms** - Improved validation and error handling

### Security
- **Session Hijacking Prevention** with session fingerprinting
- **MFA/TOTP** for enhanced account security
- **Granular Permissions** replacing coarse ROLE_* system
- **Audit Logging** for all security-relevant events
- **CVE Analysis** and mitigation for all dependencies
- **License Compliance** enforcement in CI/CD
- **Rate Limiting** and secrets management
- **HTTPS Support** with automatic HTTP redirect

### Statistics
- **~25,000 new lines of code**
- **300+ new/modified files**
- **31 entities** with multi-tenancy support
- **7 new German compliance frameworks**
- **100+ granular permissions**
- **5,000+ translation keys** (DE/EN)
- **60%+ test coverage**
- **400+ unit tests**
- **🎉 Enterprise-Ready** - Multi-tenancy, MFA, comprehensive admin panel!

### Documentation
- [Corporate Structure Documentation](docs/corporate-structure/)
- [Multi-Tenancy Setup Guide](docs/multi-tenancy/)
- [Admin Panel User Guide](docs/admin-panel.md)
- [MFA/TOTP Setup](docs/mfa-setup.md)
- [Translation System](docs/i18n-system.md)
- [WCAG 2.1 AA Compliance](docs/accessibility.md)
- [License Compliance Report](docs/reports/license-report.md)
- [Phase 6 Implementation](docs/PHASE6_*)

### Upgrade Guide

**From 1.5.x to 1.6.0:**

⚠️ **BREAKING CHANGES** - This is a major update with multi-tenancy!

**1. Database Migration:**
```bash
# Backup your database first!
php bin/console doctrine:migrations:migrate

# Add tenant_id to all entities (automated)
php bin/console app:migrate-tenant-columns
```

**2. Initial Tenant Setup:**
```bash
# Create your first tenant (required!)
php bin/console app:setup-tenant --name="Your Company" --code="COMPANY"
```

**3. Setup Granular Permissions:**
```bash
# Migrate from role-based to permission-based access
php bin/console app:setup-permissions
```

**4. MFA Setup (Optional):**
```bash
# Enable MFA for enhanced security
# Users can activate TOTP in their profile settings
```

**5. Load German Frameworks (Optional):**
```bash
php bin/console app:load-framework bsi
php bin/console app:load-framework bafin
php bin/console app:load-framework dsgvo
# ... etc
```

**6. Clear Cache:**
```bash
php bin/console cache:clear
```

**New Routes:**
- `/admin` - Unified admin panel
- `/admin/dashboard` - Admin dashboard
- `/admin/tenants` - Tenant management
- `/admin/system` - System configuration
- `/admin/monitoring` - Health checks
- `/health` - Docker health endpoint

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

#### Premium Features - Paket E (Drag & Drop Interactions) ✨ NEW!
- **Dashboard Widget Drag & Drop** with native HTML5 API
  - Widget reordering via drag and drop
  - Visual drag feedback with CSS animations
  - LocalStorage persistence of widget order
  - Automatic restoration on page load
  - Extended dashboard_customizer_controller.js (+120 lines to 276 total)
- **File Upload Drag & Drop** for Document Management
  - Modern drag & drop zone with visual feedback
  - Multi-file upload support (upload multiple files simultaneously)
  - File type validation (PDF, Word, Excel, Images, Text)
  - File size validation (max 10MB per file)
  - File preview list with MIME-type icons
  - Remove individual files before upload
  - Error toast notifications
  - Dark mode support
  - Mobile responsive design
  - New file_upload_controller.js (346 lines)
  - New document/new_modern.html.twig template (378 lines)

#### Bulk Actions Integration
- Bulk Actions for 4 modules: Asset, Risk, Incident, Training Management
- Select All checkbox with individual item selection
- Floating action bar (appears on selection)
- Bulk operations: Export (CSV), Assign, Delete
- Confirmation dialogs for destructive actions
- Success notifications

#### Audit Log Timeline View
- Timeline component with vertical timeline visualization
- Tab navigation between Table and Timeline views
- Grouped entries by date
- Color-coded action markers:
  - 🟢 Create (Green #28a745)
  - 🟡 Update (Yellow #ffc107)
  - 🔴 Delete (Red #dc3545)
  - 🔵 View (Blue #17a2b8)
  - ⚫ Export/Import (Gray/Purple #6c757d / #6f42c1)
- User attribution and entity links
- Dark mode compatible
- Mobile responsive

### Changed
- Document Management foundation laid (Entity and Repository only, full implementation deferred)
- DocumentController now uses modern templates (index_modern.html.twig, new_modern.html.twig)

### Statistics
- **~3,500 new lines of code** (Phase 5 total including Drag & Drop)
- **21 new/modified files**
- **30 API endpoints**
- **10 report types (5 PDF + 5 Excel)**
- **5 notification types**
- **2 new Stimulus controllers** (file_upload_controller.js, dashboard_customizer extended)
- **🎉 100% Feature Complete** - All planned Phase 5 features implemented!

### Technical Highlights
- **Zero Heavy Dependencies** - Native HTML5 Drag & Drop APIs only
- **Progressive Enhancement** - Works without JavaScript fallback
- **Dark Mode Support** - All features dark mode compatible
- **Mobile Responsive** - Touch-optimized for mobile devices
- **LocalStorage Persistence** - Client-side state management

### Documentation
- [Phase 5 Final Features](docs/PHASE5_FINAL_FEATURES.md) - **100% Complete!**
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
