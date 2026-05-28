# Cross-Framework Compliance Mappings

## Overview

This system provides comprehensive bidirectional mappings between compliance frameworks, enabling **data reuse**: implementing controls for one framework automatically contributes to compliance with others.

## Supported Frameworks

### Core Hub: ISO 27001:2022
- **93 Annex A Controls** (A.5 - A.8)
- Serves as the central hub for all cross-framework mappings
- All other frameworks map to ISO 27001 controls

### International & Regional Standards
- **TISAX** (VDA ISA 6.0.2) - Automotive industry
- **DORA** (EU 2022/2554) - Financial services
- **NIS2** (EU 2022/2555) - EU cybersecurity directive
- **BSI IT-Grundschutz** - German federal standard
- **GDPR** (EU 2016/679) - Data protection
- **ISO 27701:2019** - Privacy management
- **ISO 22301** - Business continuity management

### US & Best Practice Frameworks
- **NIST Cybersecurity Framework 2.0** - 66 requirements across 6 functions
  - GOVERN (8), IDENTIFY (9), PROTECT (22), DETECT (11), RESPOND (12), RECOVER (4)
- **CIS Controls v8** - 45 requirements covering all 18 controls
  - Basic (17), Foundational (17), Organizational (11)
- **SOC 2 Type II** - 55 Trust Services Criteria
  - Common Criteria CC1-CC9 (33), Availability (3), Confidentiality (2), Privacy (12), Processing Integrity (5)

## Framework Statistics

| Framework | Requirements | Mappings to ISO 27001 | Coverage |
|-----------|--------------|----------------------|----------|
| ISO 27001:2022 | 93 controls | - (hub) | 100% |
| NIST CSF 2.0 | 66 | ~85% | High |
| CIS Controls v8 | 45 | ~90% | High |
| SOC 2 | 55 | ~80% | High |
| TISAX | 50+ | ~95% | Very High |
| DORA | 40+ | ~90% | High |
| NIS2 | 40+ | ~90% | High |
| GDPR | 30+ | ~85% | High |

## Commands

### 1. Load Framework Requirements

```bash
# Load ISO 27001 as ComplianceRequirements (for cross-framework mapping)
php bin/console app:load-iso27001-requirements [--update]

# Load additional frameworks
php bin/console app:load-nist-csf-requirements
php bin/console app:load-cis-controls-requirements
php bin/console app:load-soc2-requirements

# Existing frameworks (already available)
php bin/console app:load-tisax-requirements
php bin/console app:load-dora-requirements
php bin/console app:load-nis2-requirements
php bin/console app:load-gdpr-requirements
php bin/console app:load-bsi-requirements
php bin/console app:load-iso27701-requirements
```

**Options:**
- `--update` or `-u`: Update existing requirements instead of skipping them (idempotent)

### 2. Create Cross-Framework Mappings

```bash
# Create comprehensive bidirectional mappings
php bin/console app:create-cross-framework-mappings [--clear]
```

**Options:**
- `--clear`: Clear all existing mappings before creating new ones

**What it does:**
1. **Step 1**: Maps each framework requirement → ISO 27001 controls (based on `data_source_mapping`)
2. **Step 2**: Creates reverse mappings ISO 27001 → framework requirements (bidirectional)
3. **Step 3**: Creates transitive mappings between frameworks (A ↔ B via shared ISO controls)

## Mapping Intelligence

### Mapping Strength

| Type | Percentage | Meaning |
|------|-----------|---------|
| **Weak** | 0-49% | Loose relationship, partial overlap |
| **Partial** | 50-99% | Significant overlap, partially satisfies |
| **Full** | 100% | Fully satisfies the requirement |
| **Exceeds** | 101-150% | Goes beyond the requirement |

### Confidence Levels

- **High**: Well-established frameworks with mature mappings (TISAX, NIS2, DORA, NIST CSF, CIS Controls, SOC 2)
- **Medium**: Good mappings with some interpretation needed (BSI, ISO 27701)
- **Low**: Requires expert review

### Automatic Calculation

Mapping percentage is calculated based on:
- **Number of ISO controls mapped**: More controls = broader scope
- **Requirement priority**: Critical requirements get higher base percentages
- **Control overlap**: Transitive mappings based on shared ISO controls

## Data Reuse Example

### Scenario: Implementing ISO 27001 A.5.1 (Information Security Policy)

When you implement ISO 27001 A.5.1 at 80%:

**Automatic benefits:**
- ✅ TISAX INF-1.1: 80% × 95% = **76% compliance**
- ✅ NIS2-21.2.a: 80% × 90% = **72% compliance**
- ✅ NIST CSF GV.PO-01: 80% × 95% = **76% compliance**
- ✅ CIS-4.1: 80% × 90% = **72% compliance**
- ✅ SOC 2 CC1.1: 80% × 85% = **68% compliance**

**Result**: One control implementation contributes to 5+ frameworks!

## Architecture

### Two-Tier System

1. **Control Entity** (`src/Entity/Control.php`)
   - Tracks actual implementation of ISO 27001 Annex A controls
   - Implementation status, percentage, evidence, etc.
   - Used for day-to-day ISMS operations

2. **ComplianceRequirement Entity** (`src/Entity/ComplianceRequirement.php`)
   - Represents requirements from ANY compliance framework
   - Includes `data_source_mapping` field for ISO control references
   - Used for cross-framework compliance analysis

### ComplianceMapping Entity

Stores bidirectional mappings between requirements:

```php
ComplianceMapping {
    sourceRequirement: ComplianceRequirement    // e.g., NIST CSF GV.PO-01
    targetRequirement: ComplianceRequirement    // e.g., ISO 27001 A.5.1
    mappingPercentage: int                     // 0-150
    mappingType: string                        // weak|partial|full|exceeds
    bidirectional: bool                        // true = A→B and B→A
    confidence: string                         // low|medium|high
    mappingRationale: string                   // Explanation
    verifiedBy: string|null                    // Optional human verification
    verificationDate: DateTime|null
}
```

## Workflow

### Initial Setup

```bash
# 1. Load all frameworks (one-time setup)
php bin/console app:load-iso27001-requirements
php bin/console app:load-nist-csf-requirements
php bin/console app:load-cis-controls-requirements
php bin/console app:load-soc2-requirements

# 2. Create mappings (one-time or after adding new frameworks)
php bin/console app:create-cross-framework-mappings --clear

# 3. Load ISO 27001 Annex A Controls for implementation tracking
php bin/console isms:load-annex-a-controls
```

### Adding New Requirements

```bash
# Update existing framework with new requirements
php bin/console app:load-iso27001-requirements --update

# Recreate mappings to include new requirements
php bin/console app:create-cross-framework-mappings --clear
```

### Verification

```bash
# Check mapping statistics
# (Use the UI or query the database)
SELECT
    sf.name as source_framework,
    tf.name as target_framework,
    COUNT(*) as mapping_count,
    AVG(mapping_percentage) as avg_strength
FROM compliance_mapping cm
JOIN compliance_requirement sr ON cm.source_requirement_id = sr.id
JOIN compliance_requirement tr ON cm.target_requirement_id = tr.id
JOIN compliance_framework sf ON sr.framework_id = sf.id
JOIN compliance_framework tf ON tr.framework_id = tf.id
GROUP BY sf.id, tf.id;
```

## Best Practices

### 1. Idempotency
- Use `--update` flag when re-running load commands
- Prevents duplicate requirements
- Safely updates existing data

### 2. Mapping Maintenance
- Review and verify critical mappings (mark with `verifiedBy`)
- Update mappings when frameworks release new versions
- Use `--clear` when recreating all mappings from scratch

### 3. Data Reuse Analysis
- Use `ComplianceMappingService::getDataReuseAnalysis()` to see cross-framework benefits
- Track transitive compliance in reports
- Communicate savings to stakeholders (e.g., "80% of NIST CSF satisfied by existing ISO 27001 work")

### 4. Framework Priority
- Focus on ISO 27001 as the foundation
- Other frameworks automatically benefit
- Use gap analysis to identify unique requirements

## API Endpoints

```php
// Get cross-framework mappings
GET /api/compliance-mappings?sourceFramework=ISO27001&targetFramework=NIST-CSF

// Calculate framework coverage
GET /api/compliance/coverage?source=ISO27001&target=CIS-CONTROLS

// Get transitive compliance
GET /api/compliance/transitive?source=ISO27001&target=SOC2

// Get data reuse analysis
GET /api/compliance/data-reuse
```

## Limitations

### Not Included Yet

- **ISO 27001 → Frameworks** as primary source
  - Currently other frameworks map TO ISO 27001
  - Reverse direction is generated but may need refinement

- **Framework-to-Framework without ISO 27001**
  - All mappings currently go through ISO 27001 hub
  - Direct mappings (e.g., NIST CSF ↔ CIS Controls) are transitive only

- **Version tracking**
  - Framework versions are stored but not enforced
  - Mappings assume latest versions

### Future Enhancements

- **Machine learning for mapping suggestions**
- **Automated gap analysis reports**
- **Framework version compatibility matrix**
- **Community-contributed mappings**
- **Mapping quality scoring**

## Support

For issues or questions:
- Check existing mappings in UI: `/compliance/cross-framework`
- Review mapping statistics in database
- File issues on GitHub

## Credits

Mappings based on:
- Official framework documentation
- Industry best practices
- Expert analysis
- Community contributions

**Last Updated**: 2026-05-28
**Frameworks Version**: ISO 27001:2022, NIST CSF 2.0, CIS Controls v8, SOC 2 (2017 TSC)

---

## CrossFrameworkLookupService (since 2026-05-28)

`src/Service/CrossFrameworkLookupService.php` provides programmatic forward, reverse, and
transitive requirement lookup without duplicating DB rows.

### Strategy: Symmetric Query

One row per pair in the DB. The service queries both directions in PHP:

- **Outbound** (`cm.sourceRequirement = :req`) — always returned.
- **Inbound** (`cm.targetRequirement = :req`) — only returned when `bidirectional = true`.

This avoids a second materialised "reverse" row and keeps the data model simple.
The repository method `ComplianceMappingRepository::findByEitherSourceOrTarget()` executes
two sub-queries and deduplicates by mapping ID.

### Bidirectional Flag Semantics

A mapping row with `bidirectional = true` means the relationship is symmetric:
if TISAX ISA 1.1.1 ≡ ISO A.5.1, then ISO A.5.1 ≡ TISAX ISA 1.1.1.
ENX-sourced `equivalent`-relationship mappings should have `bidirectional = true`.
Partial/weak mappings are typically directional only.

### In-Memory Cache

`CrossFrameworkLookupService` holds a `private array $cache` keyed by
`"<requirementId>:<frameworkCode|*>"`. Cache lifetime = single HTTP request.
No external cache component needed — avoids invalidation complexity.

### Provenance Tags

`computeProvenanceTags()` returns string tags describing how each relationship
was inferred:

| Tag | Meaning |
|-----|---------|
| `direct_forward` | Mapping row where this req is the source |
| `direct_reverse` | Mapping row where this req is the target (bidirectional) |
| `transitive_via_ISO27001-2022` | Reachable via ISO 27001 as intermediate |

### Transitive Walk (DFS)

`findTransitiveEquivalents($req, maxDepth: 2)` uses depth-first search with a
`$visited` set (requirement IDs as keys) to prevent infinite loops in cyclic graphs.
Default max depth = 2 (grandchild equivalents). Increase cautiously — depth 3+ on
dense mapping graphs can be expensive.

### API

```php
// Direct equivalents, grouped for UI rendering
$grouped = $service->findEquivalentsGroupedByFramework($requirement);
// Returns: array<frameworkCode, list<{requirement, mapping, direction, via, depth}>>

// Forward only
$forward = $service->findMappingsForward($req, 'ISO27001-2022');

// Reverse only (respects bidirectional flag)
$reverse = $service->findMappingsReverse($req, 'VDA-ISA-6');

// All direct equivalents, deduped (strongest wins)
$all = $service->findEquivalents($req);

// Transitive up to depth N
$transitive = $service->findTransitiveEquivalents($req, maxDepth: 2);

// Provenance tags for coverage rollup
$tags = $service->computeProvenanceTags($req);
```

### Controller Integration

`ComplianceRequirementController::show()` passes `cross_framework_equivalents` to
`templates/compliance/requirement/show.html.twig`, which renders the
"Äquivalente in anderen Frameworks" / "Equivalents in Other Frameworks" card
when any equivalents exist.

### Tests

`tests/Service/CrossFrameworkLookupServiceTest.php` — 8 unit tests covering:
forward lookup, framework-code filter, reverse excludes non-bidirectional,
reverse includes bidirectional, deduplication, in-memory cache, transitive depth-2,
and cycle guard.
