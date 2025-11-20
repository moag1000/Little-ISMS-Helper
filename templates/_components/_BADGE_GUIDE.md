# Badge System Guide

## Overview
Standardized badge system with 4 categories, full dark mode support, and consistent styling.

## Badge Categories

### 1. STATUS BADGES (Bootstrap 5)
Use for general states, completion status, and availability.

```twig
{# Success - Active, Completed, Compliant #}
<span class="badge bg-success">Active</span>
<span class="badge bg-success">Completed</span>
<span class="badge bg-success">Compliant</span>

{# Warning - Pending, In Progress, Attention Needed #}
<span class="badge bg-warning text-dark">Pending</span>
<span class="badge bg-warning text-dark">In Progress</span>

{# Danger - Error, Failed, Overdue #}
<span class="badge bg-danger">Error</span>
<span class="badge bg-danger">Failed</span>
<span class="badge bg-danger">Overdue</span>

{# Info - Information, Optional, Details #}
<span class="badge bg-info">Info</span>
<span class="badge bg-info">Optional</span>

{# Secondary - Neutral, Disabled, N/A #}
<span class="badge bg-secondary">Neutral</span>
<span class="badge bg-secondary">N/A</span>

{# Primary - Feature, New, Important #}
<span class="badge bg-primary">New Feature</span>
```

**Dark Mode:** ✅ Automatic gradient backgrounds with glow effect

---

### 2. SEVERITY BADGES
Use for risks, vulnerabilities, incidents, and threat levels.

```twig
{# Critical - Highest severity #}
<span class="badge badge-critical">Critical</span>

{# High - High priority/severity #}
<span class="badge badge-high">High</span>

{# Medium - Medium priority/severity #}
<span class="badge badge-medium">Medium</span>

{# Low - Low priority/severity #}
<span class="badge badge-low">Low</span>
```

**Styling:** Linear gradients with box-shadow
**Dark Mode:** ✅ Enhanced glow effect (12px shadow)

**Example - Risk Severity:**
```twig
{% if risk.severity == 'critical' %}
    <span class="badge badge-critical">{{ risk.severity|upper }}</span>
{% elseif risk.severity == 'high' %}
    <span class="badge badge-high">{{ risk.severity|upper }}</span>
{% elseif risk.severity == 'medium' %}
    <span class="badge badge-medium">{{ risk.severity|upper }}</span>
{% else %}
    <span class="badge badge-low">{{ risk.severity|upper }}</span>
{% endif %}
```

---

### 3. ACTION BADGES (Audit Logs)
Use for audit log actions and user activity tracking.

```twig
{# Create - New entity created #}
<span class="badge badge-create">Created</span>

{# Update - Entity modified #}
<span class="badge badge-update">Updated</span>

{# Delete - Entity deleted #}
<span class="badge badge-delete">Deleted</span>

{# View - Entity viewed (optional) #}
<span class="badge badge-view">Viewed</span>

{# Export - Data exported (optional) #}
<span class="badge badge-export">Exported</span>

{# Import - Data imported (optional) #}
<span class="badge badge-import">Imported</span>
```

**Location:** `assets/styles/ui-components.css` lines 2218-2283
**Dark Mode:** ✅ Vibrant colors with 8px glow

**Example - Audit Log:**
```twig
<span class="badge badge-{{ log.action }}">
    {{ ('audit.action.' ~ log.action)|trans }}
</span>
```

---

### 4. SPECIAL BADGES
Use for edge cases and custom scenarios.

```twig
{# Neutral - Truly neutral state #}
<span class="badge badge-neutral">Not Applicable</span>

{# Badge with icon #}
<span class="badge bg-success">
    <i class="bi bi-check-circle" aria-hidden="true"></i> Verified
</span>

{# Badge with count (notifications) #}
<span class="badge bg-danger badge-mini">5</span>
```

---

## Utility Classes

### Size Modifiers
```twig
{# Small badge #}
<span class="badge bg-success badge-sm">Small</span>

{# Large badge (custom) #}
<span class="badge bg-success" style="font-size: 1rem; padding: 0.5rem 1rem;">Large</span>
```

### Spacing
```twig
{# Badge with margin #}
<span class="badge bg-info ms-2">Info</span>
<span class="badge bg-success me-2">Success</span>
```

### Rounded Pills
```twig
{# Fully rounded badge #}
<span class="badge bg-primary rounded-pill">12</span>
```

---

## Migration from Bootstrap 4

### ❌ OLD (Bootstrap 4)
```twig
<span class="badge badge-success">Success</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-danger">Danger</span>
```

### ✅ NEW (Bootstrap 5)
```twig
<span class="badge bg-success">Success</span>
<span class="badge bg-warning text-dark">Warning</span>
<span class="badge bg-danger">Danger</span>
```

**Note:** `text-dark` is needed for `bg-warning` in light mode for proper contrast.

---

## Dark Mode Support

All badge types have comprehensive dark mode support:

- **Status Badges:** Gradient backgrounds with glow (8px)
- **Severity Badges:** Enhanced glow (12px) for visibility
- **Action Badges:** Vibrant colors with 8px glow
- **Special Badges:** Adaptive backgrounds using CSS variables

**Test Dark Mode:**
```twig
{# Toggle dark mode #}
<button data-action="theme#toggle">Toggle Dark Mode</button>

{# Test all badge types #}
<div class="badge-test-grid">
    <span class="badge bg-success">Success</span>
    <span class="badge bg-warning text-dark">Warning</span>
    <span class="badge bg-danger">Danger</span>
    <span class="badge badge-critical">Critical</span>
    <span class="badge badge-create">Create</span>
</div>
```

---

## Accessibility

### ARIA Support
```twig
{# Icon-only badge (needs aria-label) #}
<span class="badge bg-success" aria-label="{{ 'status.active'|trans }}">
    <i class="bi bi-check-circle" aria-hidden="true"></i>
</span>

{# Text badge (no aria-label needed) #}
<span class="badge bg-success">Active</span>
```

### Color Contrast
All badges meet WCAG 2.1 AA standards:
- **Light backgrounds:** Use `text-dark` for warning badges
- **Dark backgrounds:** White or high-contrast text
- **Severity badges:** Gradient ensures 4.5:1 contrast ratio

---

## Best Practices

### ✅ DO
- Use `badge bg-*` for status indicators
- Use `badge-{severity}` for risk/vulnerability levels
- Use `badge-{action}` for audit logs
- Always include text, not just icons
- Test in both light and dark modes

### ❌ DON'T
- Mix Bootstrap 4 (`badge-success`) with Bootstrap 5 (`bg-success`)
- Use inline styles for badge colors
- Forget `text-dark` for `bg-warning` in light mode
- Use severity badges for general status (use `bg-*` instead)
- Create custom badge classes without dark mode support

---

## Examples by Use Case

### Asset Status
```twig
<span class="badge bg-{{ asset.status == 'active' ? 'success' : 'secondary' }}">
    {{ asset.status|upper }}
</span>
```

### Risk Severity
```twig
<span class="badge badge-{{ risk.severity }}">
    {{ risk.severity|upper }}
</span>
```

### Compliance Status
```twig
{% if compliancePercent >= 100 %}
    <span class="badge bg-success">✓ Compliant</span>
{% elseif compliancePercent >= 75 %}
    <span class="badge bg-info">Mostly Compliant</span>
{% elseif compliancePercent >= 50 %}
    <span class="badge bg-warning text-dark">Partially Compliant</span>
{% else %}
    <span class="badge bg-danger">Non-Compliant</span>
{% endif %}
```

### User MFA Status
```twig
{% if user.mfaEnabled %}
    <span class="badge bg-success">
        <i class="bi bi-shield-check" aria-hidden="true"></i> MFA Active
    </span>
{% else %}
    <span class="badge bg-warning text-dark">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i> MFA Disabled
    </span>
{% endif %}
```

---

## CSS Files

- **Base Styles:** `assets/styles/app.css` (lines 1249-1306)
- **Dark Mode:** `assets/styles/dark-mode.css` (lines 225-301)
- **Action Badges:** `assets/styles/ui-components.css` (lines 2218-2283)

---

## Testing Checklist

- [ ] All status badges visible in light mode
- [ ] All status badges visible in dark mode with glow
- [ ] Severity badges have proper gradient and shadow
- [ ] Action badges work in audit logs
- [ ] Text contrast meets WCAG 2.1 AA
- [ ] Icons with `aria-hidden="true"`
- [ ] No Bootstrap 4 syntax remaining
- [ ] All templates lint successfully

---

**Last Updated:** 2025-11-19
**Version:** 2.0 (Bootstrap 5 + Dark Mode)
