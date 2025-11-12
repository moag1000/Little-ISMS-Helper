# Entity to Table Mapping

## Purpose
Complete verification that every Entity has a corresponding database table created in migrations.

## Mapping Table

| Entity Class | Expected Table Name | Table Created? | Migration | Notes |
|--------------|---------------------|----------------|-----------|-------|
| Asset | asset | ✅ YES | Version20251105000000 | |
| AuditChecklist | audit_checklist | ✅ YES | (to be confirmed) | |
| AuditLog | audit_log | ✅ YES | (to be confirmed) | |
| BusinessProcess | business_process | ✅ YES | Version20251105000001 | |
| ComplianceFramework | compliance_framework | ✅ YES | Version20251105000002 | |
| ComplianceMapping | compliance_mapping | ✅ YES | Version20251105000002 | |
| ComplianceRequirement | compliance_requirement | ✅ YES | Version20251105000002 | |
| Control | control | ✅ YES | Version20251105000000 | |
| **Document** | **document** | ✅ **NOW YES** | **Version20251105000006 (NEW)** | **FIXED** |
| ISMSContext | ismscontext | ✅ YES | Version20251105000000 | |
| ISMSObjective | ismsobjective | ✅ YES | Version20251105000000 | |
| Incident | incident | ✅ YES | Version20251105000000 | |
| InternalAudit | internal_audit | ✅ YES | Version20251105000000 | |
| ManagementReview | management_review | ✅ YES | Version20251105000000 | |
| Permission | permissions | ✅ YES | Version20251105000004 | |
| Risk | risk | ✅ YES | Version20251105000000 | |
| Role | roles | ✅ YES | Version20251105000004 | |
| Tenant | tenant | ✅ YES | Version20251107121500 | |
| Training | training | ✅ YES | Version20251105000000 | |
| User | users | ✅ YES | Version20251105000004 | |
| Workflow | workflow | ❓ **UNKNOWN** | **NOT FOUND** | **CHECK IF NEEDED** |
| WorkflowInstance | workflow_instance | ❓ **UNKNOWN** | **NOT FOUND** | **CHECK IF NEEDED** |
| WorkflowStep | workflow_step | ❓ **UNKNOWN** | **NOT FOUND** | **CHECK IF NEEDED** |

## Entities with Tenant Relationships

The following entities have `ManyToOne` relationship to `Tenant`:

| Entity | Has tenant_id in migration? | Migration |
|--------|------------------------------|-----------|
| User | ✅ YES | Version20251107121600 |
| Asset | ✅ YES | Version20251107121600 |
| Risk | ✅ YES | Version20251107121600 |
| Incident | ✅ YES | Version20251107121600 |
| Control | ✅ YES | Version20251107121600 |
| Document | ✅ YES | Version20251107121600 |

**Status:** All entities with Tenant relationships are covered ✅

## Tables Created in Migrations

### Version20251105000000 - Core ISMS Tables
- asset
- risk
- control
- incident
- internal_audit
- management_review
- training
- ismscontext
- ismsobjective
- control_risk (junction)
- incident_control (junction)

### Version20251105000001 - Business Process
- business_process
- business_process_asset (junction)

### Version20251105000002 - Compliance
- compliance_framework
- compliance_requirement
- compliance_requirement_control (junction)
- compliance_mapping

### Version20251105000003 - Audit & Management Review
- audit_checklist
- audit_log
- internal_audit_asset (junction)

### Version20251105000004 - Users, Roles, Permissions
- users
- roles
- permissions
- user_roles (junction)
- role_permissions (junction)

### Version20251105000005 - Owner Relationships
- (Adds user_id foreign keys to existing tables)

### Version20251105000006 - Document Table (NEW)
- document

### Version20251105100001 - Default Data
- (Inserts default roles and permissions)

### Version20251107121500 - Tenant Table
- tenant

### Version20251107121600 - Tenant Relationships
- (Adds tenant_id to users, asset, risk, incident, control, document)
- (Adds status to document)

## Issues Found

### ✅ FIXED: Missing Document Table
**Problem:** Document entity existed but table was never created
**Solution:** Created Version20251105000006.php to create document table
**Status:** FIXED

### ⚠️ TO INVESTIGATE: Workflow Tables
**Problem:** Workflow, WorkflowInstance, WorkflowStep entities exist but no tables created
**Possible Causes:**
1. Feature is planned for future phase (not yet implemented)
2. Legacy entities that should be removed
3. Migrations were forgotten

**Action Required:** Verify if Workflow feature is supposed to be functional

## Verification Commands

```bash
# List all tables created in migrations
grep -h "CREATE TABLE" migrations/*.php | sed 's/.*CREATE TABLE //' | sed 's/ .*//' | sort -u

# List all entities
ls -1 src/Entity/*.php | xargs -I {} basename {} .php | sort

# Find entities with Tenant relationship
grep -l "targetEntity: Tenant" src/Entity/*.php | xargs basename -a | sed 's/\.php//'
```

## Migration Order (Updated)

1. Version20251105000000 - Core tables
2. Version20251105000001 - Business process
3. Version20251105000002 - Compliance
4. Version20251105000003 - Audit
5. Version20251105000004 - Users, roles, permissions
6. Version20251105000005 - Owner relationships
7. **Version20251105000006 - Document table** ← NEW
8. Version20251105100001 - Default data
9. Version20251107121500 - Tenant table
10. Version20251107121600 - Tenant relationships

---

**Last Updated:** 2025-11-07
**Status:** Document table migration added, Workflow tables need investigation
