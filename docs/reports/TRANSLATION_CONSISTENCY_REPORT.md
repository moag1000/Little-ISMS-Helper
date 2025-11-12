# Translation Consistency Check Report

**Date:** 2025-11-11
**Files Analyzed:**
- `/home/user/Little-ISMS-Helper/translations/messages.de.yaml` (German)
- `/home/user/Little-ISMS-Helper/translations/messages.en.yaml` (English)

---

## Executive Summary

### Summary Statistics

| Metric | German (DE) | English (EN) |
|--------|-------------|--------------|
| **Total Keys** | 1,624 | 1,615 |
| **Keys Only in DE** | 9 | 0 |
| **Keys Only in EN** | 0 | 9 |
| **Duplicate Keys** | 0 | 0 |
| **Duplicate Parent Keys** | 4 | 6 |
| **Common Keys** | 1,615 | 1,615 |

### Critical Findings

✅ **No duplicate keys** found (same key appearing multiple times with different values)

⚠️ **CRITICAL: Duplicate parent sections found!**
- **English file has 6 duplicate parent keys** causing data loss
- **German file has 4 duplicate parent keys** causing data loss
- **9 keys appear missing** from English due to duplicate sections overriding each other

---

## 1. Duplicate Parent Keys (CRITICAL ISSUE)

### What is a Duplicate Parent Key?

When a parent key (a section containing child keys) appears multiple times at the same indentation level, YAML parsers only keep the **LAST** occurrence. All child keys from earlier occurrences are **LOST**.

### English File - 6 Duplicate Parent Keys

#### 1. `compliance.stats` (Lines 1431 and 1592)

**First occurrence (Line 1431) - LOST:**
```yaml
stats:
  total_relationships: Total Framework Relationships
  transitive_compliance: Transitive Compliance Benefits
  leverage_rate: Leverage Rate
  frameworks_leveraged: Frameworks Leveraged
  common_requirements: Common Requirements
  overlap_percentage: Overlap Percentage
```

**Second occurrence (Line 1592) - KEPT:**
```yaml
stats:
  total_mappings: Total Mappings
  full_exceeds: Full/Exceeds
  partial_mappings: Partial Mappings
  bidirectional_mappings: Bidirectional
```

**Impact:** 6 keys from the first section are LOST!

**Recommendation:** Merge both sections into one:
```yaml
stats:
  total_relationships: Total Framework Relationships
  transitive_compliance: Transitive Compliance Benefits
  leverage_rate: Leverage Rate
  frameworks_leveraged: Frameworks Leveraged
  common_requirements: Common Requirements
  overlap_percentage: Overlap Percentage
  total_mappings: Total Mappings
  full_exceeds: Full/Exceeds
  partial_mappings: Partial Mappings
  bidirectional_mappings: Bidirectional
```

---

#### 2. `compliance.label` (Lines 1452 and 1582)

**First occurrence (Line 1452) - LOST:**
```yaml
label:
  source_target_matrix: "Source ↓ / Target →"
  showing_of: "Showing %count% of"
  mappings_count: "mappings"
```

**Second occurrence (Line 1582) - KEPT:**
```yaml
label:
  code: Code
  yes: Yes
  no: No
  compliance: Compliance
  requirements: Requirements
  fulfilled: Fulfilled
  gaps: Gaps
  time_savings: Time Savings
  days: Days
```

**Impact:** 3 keys from the first section are LOST!

**Recommendation:** Merge both sections.

---

#### 3. `audit.result` (Lines 376 and 407)

**First occurrence (Line 376) - LOST:**
```yaml
result: Result
```

**Second occurrence (Line 407) - KEPT:**
```yaml
result:
  passed: Passed
  passed_with_findings: Passed with Findings
  failed: Failed
  pending: Pending
```

**Impact:** The simple "Result" label is lost (though this is probably intentional as line 407 provides more detail).

---

#### 4. `incident.timeline` (Lines 589 and 698)

**First occurrence (Line 589) - LOST:**
```yaml
timeline: Incident Timeline
```

**Second occurrence (Line 698) - KEPT:**
```yaml
timeline:
  early_warning: Early Warning (24h)
  detailed_notification: Detailed Notification (72h)
  final_report: Final Report (1 month)
  deadline: Deadline
  reported: Reported
  reported_at: Reported at
  overdue: Overdue
  hours_remaining: "%hours% hours remaining"
  days_remaining: "%days% days remaining"
```

**Impact:** The simple "Incident Timeline" label is lost.

---

#### 5. `incident.action` (Lines 590 and 651)

**First occurrence (Line 590) - LOST:**
```yaml
action:
  report_new: Report New Incident
```

**Second occurrence (Line 651) - KEPT:**
```yaml
action:
  download_nis2_report: Download NIS2 Report (PDF)
```

**Impact:** The "Report New Incident" action is LOST!

**Recommendation:** Merge:
```yaml
action:
  report_new: Report New Incident
  download_nis2_report: Download NIS2 Report (PDF)
```

---

#### 6. `incident.placeholder` (Lines 600 and 653)

**First occurrence (Line 600) - LOST:**
```yaml
placeholder:
  category: Select incident category
  severity: Select severity level
```

**Second occurrence (Line 653) - KEPT:**
```yaml
placeholder:
  title: e.g. Data breach customer data
  description: Detailed description of the incident...
  reported_by: Name of reporter
  affected_systems: e.g. CRM system, database
  root_cause: Root cause analysis...
  corrective_actions: Corrective actions taken...
  lessons_learned: What did we learn?
  nis2_category: Select NIS2 category...
  national_authority: e.g. BSI, ENISA
  authority_reference: e.g. BSI-2024-001234
```

**Impact:** 2 placeholder keys are LOST!

**Recommendation:** Merge both sections.

---

### German File - 4 Duplicate Parent Keys

The German file has the same 4 duplicate parent key issues:

1. **`audit.result`** (Lines 376 and 407)
2. **`incident.timeline`** (Lines 589 and 698)
3. **`incident.action`** (Lines 590 and 651)
4. **`incident.placeholder`** (Lines 600 and 653)

**Note:** The German file does NOT have the `compliance.stats` and `compliance.label` duplication issue that the English file has. In German, all these keys are in ONE section.

---

## 2. Keys Only in German (Missing in English)

Due to the duplicate parent key issue in English, these 9 keys appear to be missing:

| Key | German Value | Status |
|-----|--------------|--------|
| `compliance.stats.total_relationships` | Gesamt Framework-Beziehungen | Lost due to duplicate `stats` section |
| `compliance.stats.transitive_compliance` | Transitive Compliance-Vorteile | Lost due to duplicate `stats` section |
| `compliance.stats.leverage_rate` | Nutzungsrate | Lost due to duplicate `stats` section |
| `compliance.stats.frameworks_leveraged` | Genutzte Frameworks | Lost due to duplicate `stats` section |
| `compliance.stats.common_requirements` | Gemeinsame Anforderungen | Lost due to duplicate `stats` section |
| `compliance.stats.overlap_percentage` | Überschneidungs-Prozentsatz | Lost due to duplicate `stats` section |
| `compliance.label.source_target_matrix` | Quelle ↓ / Ziel → | Lost due to duplicate `label` section |
| `compliance.label.showing_of` | Zeige %count% von | Lost due to duplicate `label` section |
| `compliance.label.mappings_count` | Mappings | Lost due to duplicate `label` section |

**These keys actually EXIST in English** (at lines 1432-1437 and 1453-1455) but are being overridden by duplicate sections!

---

## 3. Structural Consistency

✅ **Both files have the same hierarchical structure** (once duplicate parent keys are accounted for).

---

## 4. Recommendations

### Priority 1 - CRITICAL (Data Loss)

#### Fix English File Duplicate Sections

1. **Merge `compliance.stats` sections (Lines 1431 → 1592)**
   - Location: Around line 1592
   - Action: Move all keys from line 1431-1437 into the section at line 1592
   - Add these keys:
     ```yaml
     total_relationships: Total Framework Relationships
     transitive_compliance: Transitive Compliance Benefits
     leverage_rate: Leverage Rate
     frameworks_leveraged: Frameworks Leveraged
     common_requirements: Common Requirements
     overlap_percentage: Overlap Percentage
     ```

2. **Merge `compliance.label` sections (Lines 1452 → 1582)**
   - Location: Around line 1582
   - Action: Move all keys from line 1452-1455 into the section at line 1582
   - Add these keys:
     ```yaml
     source_target_matrix: "Source ↓ / Target →"
     showing_of: "Showing %count% of"
     mappings_count: "mappings"
     ```

3. **Merge `incident.action` sections (Lines 590 → 651)**
   - Add back: `report_new: Report New Incident`

4. **Merge `incident.placeholder` sections (Lines 600 → 653)**
   - Add back:
     ```yaml
     category: Select incident category
     severity: Select severity level
     ```

#### Fix German File Duplicate Sections

Apply the same fixes for `audit.result`, `incident.timeline`, `incident.action`, and `incident.placeholder`.

---

### Priority 2 - Consistency

Once duplicate sections are merged, verify that both files have exactly the same keys.

---

## 5. Verification Steps

After implementing fixes:

1. Run `python3 check_translations.py` again - should show 0 missing keys
2. Run `python3 check_yaml_duplicates.py` again - should show 0 duplicate parent keys
3. Load both YAML files in your application and verify all translations work

---

## Appendix: How to Identify Duplicate Parent Keys

You can manually search for duplicate parent keys using:

```bash
# Find all parent keys at a specific indentation level
grep -n "^  stats:" translations/messages.en.yaml
grep -n "^  label:" translations/messages.en.yaml
grep -n "^  action:" translations/messages.en.yaml
```

If a key appears multiple times, you have a duplicate parent key issue.

---

**Report Generated:** 2025-11-11
**Tools Used:** Python 3, PyYAML, Custom consistency checkers
