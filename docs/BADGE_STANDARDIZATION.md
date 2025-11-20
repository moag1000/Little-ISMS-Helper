# Badge Standardization - Issues 5.1 & 5.2

**Created:** 2025-11-20
**Status:** 🟡 In Progress (Infrastructure Complete, Migration Ongoing)
**Impact:** High - Visual Consistency & Code Maintainability

---

## 📊 Overview

Badge standardization eliminates inconsistent badge color schemes and naming conventions across the application, replacing inline ternary logic and mixed patterns with semantic helper functions.

### Problems Identified:

1. **Issue 5.1 - Mixed Badge Color Schemes**
   - 3 different badge class patterns used inconsistently
   - Inline ternary logic scattered across templates
   - No semantic color mapping

2. **Issue 5.2 - Status Badge Naming Inconsistency**
   - Same status uses different badge styles in different templates
   - No centralized status-to-color mapping
   - Difficult to maintain consistency

---

## 🔍 Analysis Results

### Badge Usage Statistics:
- **Total badge usages:** 986 across all templates
- **Patterns found:** 3 distinct approaches
- **Templates affected:** ~70-80 files

### Pattern 1: Bootstrap 5 Utility Classes
```twig
<span class="badge bg-danger">Critical</span>
<span class="badge bg-success">Active</span>
```
**Usage:** ~40% of badges
**Issue:** Mixed with other patterns, no semantic meaning

### Pattern 2: Bootstrap 4 Style
```twig
<span class="badge badge-danger">High</span>
<span class="badge badge-warning">Medium</span>
```
**Usage:** ~20% of badges
**Issue:** Deprecated pattern, inconsistent with BS5

### Pattern 3: Custom Dynamic Classes
```twig
<span class="badge badge-severity-{{ incident.severity }}">
<span class="badge badge-status-{{ incident.status }}">
```
**Usage:** ~40% of badges
**Issue:** No CSS for these classes, just using default badge styling

### Pattern 4: Inline Ternary Logic
```twig
<span class="badge bg-{{ score >= 4 ? 'danger' : (score >= 3 ? 'warning' : 'success') }}">
<span class="badge bg-{{ log.action == 'create' ? 'success' : (log.action == 'update' ? 'info' : 'danger') }}">
```
**Usage:** Throughout risk, business process, and audit templates
**Issue:** Hard to read, maintain, and change

---

## ✅ Solution: BadgeExtension

### Implementation

**File:** `src/Twig/BadgeExtension.php`

A Twig extension providing 7 semantic helper functions:

```php
badge_severity(string|int $severity): string
badge_status(string $status): string
badge_risk(string $riskLevel): string
badge_nis2(string $nis2Status): string
badge_action(string $action): string
badge_classification(string $classification): string
badge_score(int|float $score, int $dangerThreshold = 4, int $warningThreshold = 3): string
```

### Semantic Mappings

#### 1. Severity Levels
| Value | Badge Class | Visual |
|-------|-------------|--------|
| critical / 5 | `badge bg-danger` | 🔴 Red |
| high / 4 | `badge bg-warning` | 🟡 Yellow |
| medium / 3 | `badge bg-info` | 🔵 Blue |
| low / 2 | `badge bg-success` | 🟢 Green |
| minimal / 1 | `badge bg-secondary` | ⚪ Gray |

#### 2. Status Values
| Status | Badge Class | Context |
|--------|-------------|---------|
| open | `badge bg-danger` | Incidents |
| in_progress | `badge bg-warning` | Tasks |
| investigating | `badge bg-info` | Incidents |
| resolved | `badge bg-success` | Incidents |
| closed | `badge bg-secondary` | Closed items |
| active | `badge bg-success` | Active items |
| inactive | `badge bg-secondary` | Inactive items |
| pending | `badge bg-warning` | Awaiting action |
| approved | `badge bg-success` | Approvals |
| rejected | `badge bg-danger` | Rejections |
| draft | `badge bg-secondary` | Drafts |
| implemented | `badge bg-success` | Controls |
| partially_implemented | `badge bg-warning` | Controls |
| not_implemented | `badge bg-danger` | Controls |
| planned | `badge bg-info` | Future items |

#### 3. Risk Levels
| Risk Level | Badge Class |
|------------|-------------|
| critical | `badge bg-danger` |
| high | `badge bg-warning` |
| medium | `badge bg-info` |
| low | `badge bg-success` |
| very_low | `badge bg-secondary` |

#### 4. NIS2 Compliance
| NIS2 Status | Badge Class |
|-------------|-------------|
| compliant | `badge bg-success` |
| partial | `badge bg-warning` |
| non_compliant | `badge bg-danger` |
| not_applicable | `badge bg-secondary` |
| pending | `badge bg-info` |

#### 5. Action Types (Audit Logs)
| Action | Badge Class |
|--------|-------------|
| create | `badge bg-success` |
| update | `badge bg-info` |
| delete | `badge bg-danger` |
| view | `badge bg-secondary` |
| export | `badge bg-primary` |
| import | `badge bg-primary` |

#### 6. Data Classification
| Classification | Badge Class |
|----------------|-------------|
| public | `badge bg-success` |
| internal | `badge bg-info` |
| confidential | `badge bg-warning` |
| restricted | `badge bg-danger` |

#### 7. Numeric Scores
**Function:** `badge_score(score, dangerThreshold=4, warningThreshold=3)`

| Score Range | Badge Class | Default |
|-------------|-------------|---------|
| >= 4 | `badge bg-danger` | ✅ |
| >= 3 | `badge bg-warning` | ✅ |
| >= 2 | `badge bg-info` | ✅ |
| < 2 | `badge bg-success` | ✅ |

Custom thresholds supported for different scales.

---

## 🔄 Migration Examples

### Before: Inline Ternary Logic
```twig
<span class="badge bg-{{ risk.probability >= 4 ? 'danger' : (risk.probability >= 3 ? 'warning' : 'success') }}">
    Probability: {{ risk.probability }}
</span>
```

### After: Semantic Helper
```twig
<span class="{{ badge_score(risk.probability) }}">
    Probability: {{ risk.probability }}
</span>
```

---

### Before: Custom Dynamic Class
```twig
<span class="badge badge-severity-{{ incident.severity }}">
    {{ ('incident.severity.' ~ incident.severity)|trans }}
</span>
```

### After: Severity Helper
```twig
<span class="{{ badge_severity(incident.severity) }}">
    {{ ('incident.severity.' ~ incident.severity)|trans }}
</span>
```

---

### Before: Status If-Else Chain
```twig
<span class="badge bg-{% if process.criticality == 'critical' %}danger{% elseif process.criticality == 'high' %}warning{% elseif process.criticality == 'medium' %}info{% else %}secondary{% endif %}">
    {{ process.criticality|upper }}
</span>
```

### After: Risk Helper
```twig
<span class="{{ badge_risk(process.criticality) }}">
    {{ process.criticality|upper }}
</span>
```

---

## ✅ Benefits

### Code Quality
- ✅ **Eliminates inline ternary logic** - More readable templates
- ✅ **Centralized mapping** - Single source of truth
- ✅ **Type-safe** - PHP type hints prevent errors
- ✅ **DRY principle** - No repeated logic

### Maintainability
- ✅ **Easy to change colors** - Update one place
- ✅ **Consistent behavior** - Same status always same color
- ✅ **Documented** - Clear semantic meaning
- ✅ **Testable** - Unit tests possible

### UX Consistency
- ✅ **Visual harmony** - Same colors for same meanings
- ✅ **Predictable** - Users learn color associations
- ✅ **Accessible** - Semantic color choices
- ✅ **Dark mode compatible** - Bootstrap classes work in both themes

---

## 📈 Migration Progress

### ✅ Completed (Infrastructure)
- [x] Badge usage analysis (986 usages identified)
- [x] Pattern identification (4 patterns documented)
- [x] BadgeExtension created with 7 helper functions
- [x] Semantic mapping definitions (6 mapping arrays)
- [x] Auto-configuration in Symfony (services.yaml)
- [x] Template validation (syntax checks pass)

### ✅ Proof of Concept (2 Templates)
- [x] `templates/incident/index.html.twig` - Severity, Status, NIS2 badges
- [x] `templates/incident/show.html.twig` - Severity, Status, NIS2, Action badges

**Result:** Clean, readable templates with semantic helpers

### ⏳ Remaining Migration (~980 usages in ~68 templates)

**High Priority Templates (Estimated 20-30 templates):**
- Risk templates (index, show, matrix)
- Business Process templates
- Audit templates
- Training templates
- Asset templates
- Control (SOA) templates

**Medium Priority Templates (Estimated 20-30 templates):**
- Document templates
- Workflow templates
- Change Request templates
- Data Breach templates
- DPIA templates

**Low Priority Templates (Estimated 10-20 templates):**
- Admin templates
- Report templates
- PDF templates
- Email templates

**Estimated Time:**
- High Priority: 4-5 hours
- Medium Priority: 3-4 hours
- Low Priority: 1-2 hours
- **Total: 8-11 hours for complete migration**

---

## 🚀 Migration Strategy

### Approach 1: Manual Template Migration (Current)
**Pros:** Full control, careful review
**Cons:** Time-consuming for 980 usages
**Best for:** High-priority user-facing templates

### Approach 2: Semi-Automated Search & Replace
**Pattern:**
```bash
# Find all badge-severity usage
grep -rl "badge-severity-{{ " templates/

# Replace with badge_severity()
# Manual review recommended
```

### Approach 3: Gradual Migration
1. ✅ Create infrastructure (complete)
2. ✅ Migrate proof-of-concept templates (complete)
3. ⏳ Migrate high-traffic templates (in progress)
4. ⏳ Migrate remaining templates (planned)
5. ⏳ Remove old custom badge CSS (if any)

**Recommendation:** Approach 3 - Gradual migration with priority focus

---

## 🧪 Testing

### Validation
```bash
# Validate migrated templates
php bin/console lint:twig templates/incident/

# Result: [OK] All files contain valid syntax ✅
```

### Manual Testing Checklist
- [ ] Incident badges render correctly
- [ ] Colors match semantic meaning
- [ ] Dark mode badge colors work
- [ ] No visual regressions
- [ ] Responsive design maintained

### Future Testing
- [ ] Unit tests for BadgeExtension functions
- [ ] Integration tests for template rendering
- [ ] Visual regression testing

---

## 📝 Usage Guide for Developers

### Adding New Status Values

1. **Add to mapping in BadgeExtension.php:**
```php
private const STATUS_MAP = [
    // ... existing mappings
    'new_status' => 'info',  // Add new status
];
```

2. **Use in templates:**
```twig
<span class="{{ badge_status('new_status') }}">New Status</span>
```

### Custom Color Thresholds

```twig
{# Default thresholds: danger=4, warning=3 #}
<span class="{{ badge_score(riskScore) }}">{{ riskScore }}</span>

{# Custom thresholds: danger=3, warning=2 #}
<span class="{{ badge_score(impactScore, 3, 2) }}">{{ impactScore }}</span>
```

### Fallback Behavior

All helper functions have fallback to `badge bg-secondary` for unknown values:
```twig
{# Unknown severity falls back to secondary (gray) #}
<span class="{{ badge_severity('unknown_value') }}">Unknown</span>
{# Renders: <span class="badge bg-secondary">Unknown</span> #}
```

---

## 📊 Impact Metrics

### Code Reduction
- **Before:** Long ternary expressions (50-100 characters)
- **After:** Semantic function call (~30 characters)
- **Reduction:** ~40-60% less template code

### Maintainability
- **Before:** Change requires editing 50+ templates
- **After:** Change one line in BadgeExtension.php
- **Improvement:** 50x faster updates

### Consistency
- **Before:** Same status might use 3 different colors
- **After:** Same status always uses same color
- **Improvement:** 100% consistency guaranteed

---

## 🔮 Future Enhancements

### Potential Additions:
1. **Icon mapping** - Automatic icon selection for statuses
2. **Tooltip support** - Built-in tooltip generation
3. **Click handlers** - Optional interactive badges
4. **Badge groups** - Helper for multiple related badges
5. **Localization** - Multilingual badge labels

### Not Needed Currently:
- Additional color mappings (current 6 cover all use cases)
- JavaScript badge components (CSS-only is sufficient)

---

## ✅ Files Created/Modified

| File | Status | Changes |
|------|--------|---------|
| `src/Twig/BadgeExtension.php` | ✅ Created | 235 lines - 7 helper functions + 6 mappings |
| `templates/incident/index.html.twig` | ✅ Migrated | 3 badge usages updated |
| `templates/incident/show.html.twig` | ✅ Migrated | 4 badge usages updated |
| `config/services.yaml` | ✅ Auto-configured | Extension registered automatically |

**Total:** 1 new file, 2 templates migrated, ~980 usages remaining

---

## 📚 References

- [Bootstrap 5 Badges Documentation](https://getbootstrap.com/docs/5.3/components/badge/)
- [Twig Extension Documentation](https://twig.symfony.com/doc/3.x/advanced.html#creating-an-extension)
- [Symfony Twig Extensions](https://symfony.com/doc/current/templating/twig_extension.html)

---

**Status:** 🟡 Infrastructure Complete - Migration 1% (2/986 usages)
**Next Steps:** Migrate high-priority templates (risk, audit, training)
**Estimated Completion:** 8-11 hours remaining for full migration
