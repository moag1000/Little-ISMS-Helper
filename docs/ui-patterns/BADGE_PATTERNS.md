# Badge Patterns - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-24
**Standard:** Bootstrap 5 Badge Component
**Purpose:** Consistent badge usage across the application

---

## Overview

Badges are used to display labels, counts, status indicators, and metadata throughout the application. This guide ensures consistent badge usage following Bootstrap 5 conventions.

### Key Principles

1. **Bootstrap 5 Syntax**: Use `badge bg-{variant}` not `badge-{variant}`
2. **Semantic Colors**: Choose colors based on meaning, not aesthetics
3. **Accessibility**: Always use meaningful text, not just icons
4. **Consistency**: Use the same variant for the same type of information

---

## Basic Usage

### Standard Badge

```twig
{# ✅ CORRECT - Bootstrap 5 syntax #}
<span class="badge bg-primary">New</span>
<span class="badge bg-success">Active</span>
<span class="badge bg-danger">Urgent</span>

{# ❌ INCORRECT - Bootstrap 4 syntax (deprecated) #}
<span class="badge badge-primary">New</span>
```

### Using the Badge Component

For consistency and reusability, use the `_badge.html.twig` component:

```twig
{# Simple badge #}
{% include '_components/_badge.html.twig' with {
    'variant': 'success',
    'content': 'Active'
} %}

{# Badge with icon #}
{% include '_components/_badge.html.twig' with {
    'variant': 'primary',
    'icon': 'bi-check-circle',
    'content': 'Verified'
} %}

{# Large badge with tooltip #}
{% include '_components/_badge.html.twig' with {
    'variant': 'danger',
    'size': 'lg',
    'title': 'Critical risk level',
    'content': risk.riskScore
} %}
```

---

## Badge Variants

### Available Variants

| Variant | Use Case | Example |
|---------|----------|---------|
| `bg-primary` | Primary actions, main items | Entity type, primary category |
| `bg-secondary` | Secondary info, neutral status | Inactive, archived |
| `bg-success` | Positive status, completion | Active, completed, passed |
| `bg-danger` | Errors, critical items | Critical risk, failed, urgent |
| `bg-warning` | Warnings, attention needed | Medium risk, pending review |
| `bg-info` | Informational, metadata | Type indicator, additional info |
| `bg-light` | Count badges, subtle info | Item count, subtle metadata |
| `bg-dark` | Emphasis on light backgrounds | Dark theme emphasis |

### Semantic Mapping

**Status Indicators:**
```twig
{# Active/Inactive #}
{% if entity.active %}
    <span class="badge bg-success">{{ 'common.active'|trans }}</span>
{% else %}
    <span class="badge bg-secondary">{{ 'common.inactive'|trans }}</span>
{% endif %}

{# Approval Status #}
{% if entity.isApproved() %}
    <span class="badge bg-success">{{ 'common.approved'|trans }}</span>
{% elseif entity.isPending() %}
    <span class="badge bg-warning">{{ 'common.pending'|trans }}</span>
{% else %}
    <span class="badge bg-secondary">{{ 'common.draft'|trans }}</span>
{% endif %}
```

---

## Standard Translation Keys (Issue 5.2)

**IMPORTANT:** Always use standardized translation keys for badge text to ensure consistency across the application.

### Common Status Translation Keys

Use these standard keys from `translations/messages.{locale}.yaml`:

| English | German | Translation Key | Badge Color |
|---------|--------|----------------|-------------|
| Active | Aktiv | `common.active` | `bg-success` |
| Inactive | Inaktiv | `common.inactive` | `bg-secondary` |
| Enabled | Aktiviert | `common.enabled` | `bg-success` |
| Disabled | Deaktiviert | `common.disabled` | `bg-secondary` |
| Completed | Abgeschlossen | `common.completed` | `bg-success` |
| Pending | Ausstehend | `common.pending` | `common.warning` |
| In Progress | In Bearbeitung | `common.in_progress` | `bg-info` |
| Draft | Entwurf | `common.draft` | `bg-secondary` |
| Published | Veröffentlicht | `common.published` | `bg-success` |
| Archived | Archiviert | `common.archived` | `bg-secondary` |

### Risk/Severity Translation Keys

Use keys from `translations/risks.{locale}.yaml`:

| English | German | Translation Key | Badge Color |
|---------|--------|----------------|-------------|
| Critical | Kritisch | `risk.severity.critical` | `bg-danger` |
| High | Hoch | `risk.severity.high` | `bg-warning` |
| Medium | Mittel | `risk.severity.medium` | `bg-info` |
| Low | Niedrig | `risk.severity.low` | `bg-secondary` |

### Incident Severity Translation Keys

Use keys from `translations/incidents.{locale}.yaml`:

| English | German | Translation Key | Badge Color |
|---------|--------|----------------|-------------|
| Critical | Kritisch | `incident.severity.critical` | `bg-danger` |
| High | Hoch | `incident.severity.high` | `bg-warning` |
| Medium | Mittel | `incident.severity.medium` | `bg-info` |
| Low | Niedrig | `incident.severity.low` | `bg-secondary` |

### Control Implementation Status

Use keys from `translations/controls.{locale}.yaml`:

| English | German | Translation Key | Badge Color |
|---------|--------|----------------|-------------|
| Implemented | Implementiert | `controls.status.implemented` | `bg-success` |
| In Progress | In Bearbeitung | `controls.status.in_progress` | `bg-warning` |
| Not Started | Nicht begonnen | `controls.status.not_started` | `bg-secondary` |
| Planned | Geplant | `controls.status.planned` | `bg-info` |

### ❌ Anti-Patterns: Inconsistent Naming

```twig
{# ❌ DON'T: Hardcoded text #}
<span class="badge bg-success">Active</span>

{# ❌ DON'T: Inconsistent translations #}
<span class="badge bg-success">{{ 'status.active'|trans }}</span>  <!-- Wrong domain -->
<span class="badge bg-success">{{ 'entity.is_active'|trans }}</span>  <!-- Wrong key -->

{# ✅ DO: Use standard translation keys #}
<span class="badge bg-success">{{ 'common.active'|trans }}</span>
```

### Status Badge Helper Pattern

For complex status logic, create a Twig macro:

```twig
{# _macros/badges.html.twig #}
{% macro status_badge(status) %}
    {% set badgeMap = {
        'active': {color: 'success', key: 'common.active'},
        'inactive': {color: 'secondary', key: 'common.inactive'},
        'pending': {color: 'warning', key: 'common.pending'},
        'completed': {color: 'success', key: 'common.completed'},
        'draft': {color: 'secondary', key: 'common.draft'}
    } %}

    {% set badge = badgeMap[status] ?? {color: 'secondary', key: 'common.unknown'} %}

    <span class="badge bg-{{ badge.color }}">
        {{ badge.key|trans }}
    </span>
{% endmacro %}

{# Usage #}
{% import '_macros/badges.html.twig' as badges %}
{{ badges.status_badge(entity.status) }}
```

**Risk Levels:**
```twig
{# Risk Score Badge #}
{% if risk.riskScore >= 15 %}
    {% set variant = 'danger' %}
{% elseif risk.riskScore >= 10 %}
    {% set variant = 'warning' %}
{% elseif risk.riskScore >= 5 %}
    {% set variant = 'info' %}
{% else %}
    {% set variant = 'success' %}
{% endif %}

<span class="badge bg-{{ variant }}">{{ risk.riskScore }}</span>
```

**Compliance Percentage:**
```twig
{# Compliance Badge #}
{% set variant = framework.compliancePercentage >= 75 ? 'success' : (framework.compliancePercentage >= 50 ? 'warning' : 'danger') %}
<span class="badge bg-{{ variant }}">{{ framework.compliancePercentage }}%</span>
```

---

## Badge Sizes

### Size Variants

```twig
{# Small badge (0.75rem) #}
<span class="badge badge-sm bg-info">SM</span>

{# Default badge (0.875rem) #}
<span class="badge bg-info">Default</span>

{# Large badge (1rem) #}
<span class="badge badge-lg bg-primary">Large</span>
```

### When to Use Each Size

- **Small (`badge-sm`)**: Inline metadata, counts in tight spaces
- **Default**: Most common use cases, standard labels
- **Large (`badge-lg`)**: Prominent metrics, dashboard cards, key indicators

---

## Badge with Icons

### Icon Placement

```twig
{# ✅ CORRECT - Icon with aria-hidden #}
<span class="badge bg-success">
    <i class="bi bi-check-circle" aria-hidden="true"></i> Verified
</span>

{# ✅ CORRECT - Icon-only badge with title for accessibility #}
<span class="badge bg-danger" title="{{ 'status.critical'|trans }}">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
</span>

{# ❌ INCORRECT - Icon without aria-hidden #}
<span class="badge bg-success">
    <i class="bi bi-check-circle"></i> Verified
</span>
```

**Automatic Spacing:**
CSS automatically adds spacing between icon and text via `.badge .bi { margin-right: 0.25rem; }`

---

## Special Badge Patterns

### Pill Badges

```twig
{# Rounded pill shape #}
<span class="badge bg-primary rounded-pill">12 Items</span>

{# Using component #}
{% include '_components/_badge.html.twig' with {
    'variant': 'primary',
    'pill': true,
    'content': '12 Items'
} %}
```

### Count Badges

```twig
{# Light badge for counts #}
<h3>
    Risks
    <span class="badge bg-light text-dark">{{ risks|length }}</span>
</h3>

{# Secondary badge for metadata counts #}
<span class="badge bg-secondary">{{ entity.items|length }}</span>
```

### Multiple Badges

```twig
{# Automatic spacing via .badge + .badge selector #}
<div>
    <span class="badge bg-primary">ISO 27001</span>
    <span class="badge bg-info">GDPR</span>
    <span class="badge bg-success">NIS2</span>
</div>
```

### Linked Badges

```twig
{# Badge as link #}
<a href="{{ path('app_asset_show', {id: risk.asset.id}) }}"
   class="badge bg-primary"
   title="{{ risk.asset.name }}">
    <i class="bi bi-server" aria-hidden="true"></i> {{ risk.asset.name|u.truncate(20, '...') }}
</a>

{# Ensure proper contrast and hover states #}
```

---

## Conditional Badge Classes

### Template Conditionals

```twig
{# ✅ CORRECT - Conditional variant in class attribute #}
<span class="badge bg-{{ entity.isActive ? 'success' : 'secondary' }}">
    {{ entity.status }}
</span>

{# ✅ CORRECT - Multi-condition variant #}
<span class="badge bg-{% if percentage >= 75 %}success{% elseif percentage >= 50 %}warning{% else %}danger{% endif %}">
    {{ percentage }}%
</span>

{# ❌ INCORRECT - Conditional entire badge (inefficient) #}
{% if entity.isActive %}
    <span class="badge bg-success">{{ entity.status }}</span>
{% else %}
    <span class="badge bg-secondary">{{ entity.status }}</span>
{% endif %}
```

---

## Severity Badges (Custom)

For risk assessment, vulnerability management, and incident handling, use custom severity badges:

```twig
{# Custom severity badges with gradients #}
<span class="badge badge-critical">Critical</span>  <!-- Red gradient -->
<span class="badge badge-high">High</span>          <!-- Orange gradient -->
<span class="badge badge-medium">Medium</span>      <!-- Yellow gradient -->
<span class="badge badge-low">Low</span>            <!-- Blue gradient -->
```

**CSS Definitions:**
```css
.badge-critical { background: linear-gradient(135deg, #dc2626, #991b1b); }
.badge-high { background: linear-gradient(135deg, #f97316, #ea580c); }
.badge-medium { background: linear-gradient(135deg, #eab308, #ca8a04); }
.badge-low { background: linear-gradient(135deg, #3b82f6, #2563eb); }
```

---

## Dark Mode Support

### CSS Variables

All badge colors use CSS variables for dark mode compatibility:

```css
/* Badge backgrounds automatically adapt via Bootstrap utilities */
.badge.bg-primary { background-color: var(--bs-primary) !important; }
.badge.bg-success { background-color: var(--bs-success) !important; }

/* Custom badge colors are defined in dark-mode.css */
[data-theme="dark"] {
    --bs-primary: #60a5fa;
    --bs-success: #22c55e;
    /* ... */
}
```

**Never hardcode hex colors:**
```twig
{# ✅ CORRECT #}
<span class="badge bg-success">Active</span>

{# ❌ INCORRECT #}
<span class="badge" style="background: #28a745;">Active</span>
```

---

## Accessibility Guidelines

### WCAG 2.1 Compliance

1. **Meaningful Text**: Badges must contain text or have proper ARIA labels
2. **Color Contrast**: Ensure 4.5:1 contrast ratio (handled by Bootstrap)
3. **Icons**: Always use `aria-hidden="true"` on decorative icons
4. **Tooltips**: Add `title` attribute for icon-only badges

### Examples

```twig
{# ✅ CORRECT - Text with decorative icon #}
<span class="badge bg-success">
    <i class="bi bi-check" aria-hidden="true"></i>
    {{ 'status.approved'|trans }}
</span>

{# ✅ CORRECT - Icon-only with tooltip #}
<span class="badge bg-danger" title="{{ 'risk.critical'|trans }}">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
    <span class="visually-hidden">{{ 'risk.critical'|trans }}</span>
</span>

{# ❌ INCORRECT - Icon-only without accessibility #}
<span class="badge bg-danger">
    <i class="bi bi-exclamation-triangle"></i>
</span>
```

---

## Common Patterns

### Entity Type Badges

```twig
{# Asset type #}
<span class="badge bg-primary">
    <i class="bi bi-server" aria-hidden="true"></i> {{ 'asset.type.server'|trans }}
</span>

{# Risk subject type #}
<span class="badge bg-secondary">{{ risk.riskSubjectType|upper }}</span>

{# Document type #}
<span class="badge bg-info">
    <i class="bi bi-file-text" aria-hidden="true"></i> {{ document.type }}
</span>
```

### Framework Badges

```twig
{# Framework identification #}
<span class="badge bg-primary">ISO 27001</span>
<span class="badge bg-success">GDPR</span>
<span class="badge bg-warning text-dark">NIS2</span>

{# Mandatory frameworks #}
{% if framework.mandatory %}
    <span class="badge bg-danger">{{ 'compliance.framework.badge.mandatory'|trans }}</span>
{% endif %}
```

### Workflow Status

```twig
{# Workflow instance status #}
<span class="badge bg-{% if instance.status == 'completed' %}success{% elseif instance.status == 'active' %}primary{% elseif instance.status == 'failed' %}danger{% else %}secondary{% endif %}">
    {{ ('workflow.status.' ~ instance.status)|trans }}
</span>
```

### Time-Based Badges

```twig
{# Session activity #}
{% if session.isActive %}
    <span class="badge bg-success">{{ 'session.active'|trans }}</span>
{% else %}
    {% set minutesAgo = session.lastActivityMinutesAgo %}
    <span class="badge bg-info">{{ 'session.ago'|trans({'%minutes%': minutesAgo}) }}</span>
{% endif %}

{# Overdue indicator #}
{% if task.isOverdue %}
    <span class="badge bg-danger">
        <i class="bi bi-clock-history" aria-hidden="true"></i> {{ 'task.overdue'|trans }}
    </span>
{% endif %}
```

---

## Testing Checklist

### Visual Testing

- [ ] Badge color matches semantic meaning
- [ ] Badge size appropriate for context
- [ ] Badge readable in both light and dark mode
- [ ] Badge spacing correct (adjacent badges, icon spacing)
- [ ] Badge doesn't break layout on mobile

### Accessibility Testing

- [ ] Text or ARIA label present
- [ ] Icons have `aria-hidden="true"`
- [ ] Color contrast passes WCAG AA (4.5:1)
- [ ] Screen reader announces badge content correctly
- [ ] Tooltips present for icon-only badges

### Browser Testing

- [ ] Chrome: Badge renders correctly
- [ ] Firefox: Badge renders correctly
- [ ] Safari: Badge renders correctly
- [ ] Mobile browsers: Badge readable and sized appropriately

---

## Migration Guide

### From Bootstrap 4 to Bootstrap 5

```twig
{# Before (Bootstrap 4) #}
<span class="badge badge-primary">Label</span>
<span class="badge badge-pill badge-success">Pill</span>

{# After (Bootstrap 5) #}
<span class="badge bg-primary">Label</span>
<span class="badge bg-success rounded-pill">Pill</span>
```

### Using the Badge Component

```twig
{# Before (inline) #}
<span class="badge bg-success">
    <i class="bi bi-check-circle" aria-hidden="true"></i> Active
</span>

{# After (component) #}
{% include '_components/_badge.html.twig' with {
    'variant': 'success',
    'icon': 'bi-check-circle',
    'content': 'Active'
} %}
```

---

## Quick Reference

### Most Common Badge Patterns

| Use Case | Code |
|----------|------|
| Active status | `<span class="badge bg-success">Active</span>` |
| Inactive status | `<span class="badge bg-secondary">Inactive</span>` |
| Critical risk | `<span class="badge bg-danger">{{ risk.score }}</span>` |
| Item count | `<span class="badge bg-light text-dark">{{ count }}</span>` |
| Entity type | `<span class="badge bg-primary">{{ type }}</span>` |
| Compliance % | `<span class="badge bg-{{ percentage >= 75 ? 'success' : 'warning' }}">{{ percentage }}%</span>` |
| Icon + text | `<span class="badge bg-info"><i class="bi bi-info-circle" aria-hidden="true"></i> Info</span>` |

---

**Last Updated:** 2025-11-24
**Maintained by:** Little ISMS Helper Team
**See Also:** [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md), [ACCESSIBILITY.md](ACCESSIBILITY.md)
