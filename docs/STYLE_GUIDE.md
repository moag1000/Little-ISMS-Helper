# Little ISMS Helper - UI/UX Style Guide

**Last Updated:** 2025-11-19
**Status:** Production Ready
**WCAG Compliance:** 2.1 AA

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Design Principles](#design-principles)
3. [Color System](#color-system)
4. [Typography](#typography)
5. [Components](#components)
6. [Forms](#forms)
7. [Icons](#icons)
8. [Accessibility](#accessibility)
9. [Dark Mode](#dark-mode)
10. [Best Practices](#best-practices)

---

## Introduction

This style guide defines the visual language and UI patterns for the Little ISMS Helper application. Follow these guidelines to ensure consistency, accessibility, and maintainability.

### Framework Stack
- **Frontend:** Bootstrap 5.3
- **Icons:** Bootstrap Icons
- **JS:** Stimulus + Turbo
- **Template Engine:** Twig
- **Theme:** Cyberpunk-inspired with dual light/dark modes

---

## Design Principles

### 1. Consistency First
- Use standardized components from `templates/_components/`
- Follow established patterns across similar features
- Maintain visual hierarchy

### 2. Accessibility by Default
- WCAG 2.1 AA compliance mandatory
- Screen reader support for all interactive elements
- Keyboard navigation for all features

### 3. Mobile-First Responsive
- Design for 320px mobile first
- Scale up to desktop (1920px+)
- Touch-friendly targets (44px minimum)

### 4. Dark Mode Native
- All components must support dark mode
- Use CSS variables, never hardcoded colors
- Test in both modes before deployment

---

## Color System

### CSS Variables (Light Mode)
```css
:root {
    /* Primary Colors */
    --color-primary: #06b6d4;      /* Cyan - Main brand */
    --color-primary-dark: #0891b2;
    --color-secondary: #8b5cf6;    /* Purple - Accent */

    /* Semantic Colors */
    --color-success: #10b981;
    --color-warning: #f59e0b;
    --color-danger: #ef4444;
    --color-info: #06b6d4;

    /* Backgrounds */
    --bg-primary: #ffffff;
    --bg-secondary: #f8fafc;
    --bg-tertiary: #f1f5f9;
    --bg-elevated: #ffffff;

    /* Text */
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --text-muted: #64748b;

    /* Borders */
    --border-color: #e2e8f0;
    --border-color-light: #f1f5f9;
}
```

### Dark Mode Variables

**Important:** As of Bootstrap 5.3+, we set both `data-theme="dark"` and `data-bs-theme="dark"` for full compatibility.

```css
[data-theme="dark"],
[data-bs-theme="dark"] {
    /* Backgrounds */
    --bg-primary: #0f172a;
    --bg-secondary: #1e293b;
    --bg-tertiary: #334155;
    --bg-elevated: #1e293b;

    /* Text */
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;

    /* Borders */
    --border-color: #334155;
    --border-color-light: #475569;

    /* Bootstrap Variables Override */
    --bs-body-bg: #0f172a;
    --bs-body-color: #f1f5f9;
    --bs-tertiary-bg: #1e293b;
    --bs-secondary-bg: #334155;
    --bs-border-color: #334155;
    --bs-card-bg: #1e293b;
}
```

### Usage Rules

✅ **DO:**
```css
.my-component {
    background: var(--bg-elevated);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}
```

❌ **DON'T:**
```css
.my-component {
    background: #ffffff;  /* Hardcoded! */
    color: #333;          /* Breaks dark mode! */
}
```

---

## Typography

### Heading Hierarchy

**Always follow proper semantic structure:**

```twig
<h1>Page Title</h1>           {# One per page only #}
<h2>Main Section</h2>          {# Major sections #}
<h3>Subsection</h3>            {# Card headers, subsections #}
<h4>Minor Heading</h4>         {# Card content sections #}
<h5>Smallest Heading</h5>      {# Rare, specific use cases #}
```

✅ **DO:**
```twig
<h1>Risk Management</h1>
<h2>Risk Overview</h2>
<h3>High Priority Risks</h3>
```

❌ **DON'T:**
```twig
<h1>Risk Management</h1>
<h3>Risk Overview</h3>  {# Skipped h2! #}
```

### Font Sizes

Use Bootstrap's font size utilities:
```html
<p class="fs-1">Extra large</p>  <!-- 2.5rem -->
<p class="fs-2">Large</p>        <!-- 2rem -->
<p class="fs-3">Medium large</p> <!-- 1.75rem -->
<p class="fs-4">Medium</p>       <!-- 1.5rem -->
<p class="fs-5">Small</p>        <!-- 1.25rem -->
<p class="fs-6">Extra small</p>  <!-- 1rem -->
```

---

## Components

### Cards

**Use the standardized card component:**

```twig
{# Simple card #}
{% include '_components/_card.html.twig' with {
    'title': 'Card Title',
    'headerIcon': 'bi-shield-check',
    'body': 'Card content here'
} %}

{# Card with actions #}
{% include '_components/_card.html.twig' with {
    'title': 'Requirements',
    'headerIcon': 'bi-list-check',
    'actions': '<a href="..." class="btn btn-sm btn-primary">View All</a>',
    'body': content_variable
} %}

{# Complex card with embed #}
{% embed '_components/_card.html.twig' with {
    'title': 'Data Table',
    'noPadding': true
} %}
    {% block card_body %}
        <div class="table-responsive">
            <table class="table">...</table>
        </div>
    {% endblock %}
{% endembed %}
```

**Component Parameters:**
- `title` - Card header title (translation key)
- `headerIcon` - Bootstrap icon class (e.g., 'bi-shield')
- `actions` - HTML for header action buttons
- `body` - Simple content (for includes)
- `noPadding` - Remove card-body padding (for tables/lists)
- `class` - Additional CSS classes

### Buttons

**Standard button patterns:**

```twig
{# Primary action #}
<button class="btn btn-primary">
    <i class="bi bi-plus-circle" aria-hidden="true"></i>
    {{ 'common.create'|trans }}
</button>

{# Secondary action #}
<button class="btn btn-secondary">
    {{ 'common.cancel'|trans }}
</button>

{# Danger/destructive action #}
<button class="btn btn-danger">
    <i class="bi bi-trash" aria-hidden="true"></i>
    {{ 'common.delete'|trans }}
</button>
```

**Button groups in tables:**

```twig
<td>
    <div class="btn-group btn-group-sm">
        <a href="..." class="btn btn-outline-primary"
           aria-label="{{ 'common.view'|trans }}">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </a>
        <a href="..." class="btn btn-outline-secondary"
           aria-label="{{ 'common.edit'|trans }}">
            <i class="bi bi-pencil" aria-hidden="true"></i>
        </a>
    </div>
</td>
```

### Tables

**Always wrap tables for mobile responsiveness:**

```twig
<div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Column 1</th>
                <th>Column 2</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Data 1</td>
                <td>Data 2</td>
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        {# Action buttons #}
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

### Alerts/Info Boxes

**Use semantic alert boxes:**

```twig
{# Info box #}
<div class="info-box">
    <strong>{{ 'info.title'|trans }}:</strong>
    {{ 'info.message'|trans }}
</div>

{# Warning box #}
<div class="warning-box">
    <strong>{{ 'warning.title'|trans }}:</strong>
    {{ 'warning.message'|trans }}
</div>

{# Success box #}
<div class="success-box">
    {{ 'success.message'|trans }}
</div>

{# Danger box #}
<div class="danger-box">
    {{ 'error.message'|trans }}
</div>
```

**Alert boxes use adaptive colors:**
- `rgba()` backgrounds work in both light and dark mode
- Colored left border for visual distinction
- `var(--text-primary)` for consistent text color

### Badges

**Standard badge usage:**

```twig
{# Status badges #}
<span class="badge bg-success">{{ 'status.active'|trans }}</span>
<span class="badge bg-warning">{{ 'status.pending'|trans }}</span>
<span class="badge bg-danger">{{ 'status.inactive'|trans }}</span>
<span class="badge bg-info">{{ 'status.draft'|trans }}</span>

{# Audit action badges (have dark mode support) #}
<span class="badge badge-create">Create</span>
<span class="badge badge-update">Update</span>
<span class="badge badge-delete">Delete</span>
```

---

## Forms

### Form Field Component

**Use the standardized form field component:**

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.title,
    'label': 'risk.field.title'|trans,
    'help': 'risk.help.title'|trans,
    'required': true
} %}
```

### Required Field Indicators

**Standard required field pattern:**

```twig
<label for="field-id" class="form-label">
    {{ 'field.label'|trans }}
    <span class="required">*</span>
</label>
<input type="text" id="field-id" class="form-control" required>
```

### Form Labels

**All form fields MUST have proper label association:**

✅ **DO:**
```twig
<label for="asset-name" class="form-label">
    {{ 'asset.name'|trans }}
</label>
<input type="text" id="asset-name" class="form-control">
```

❌ **DON'T:**
```twig
<label class="form-label">{{ 'asset.name'|trans }}</label>
<input type="text" class="form-control">  {# No ID! #}
```

---

## Icons

### Bootstrap Icons Standard

**Always use full icon class format:**

✅ **DO:**
```twig
<i class="bi bi-shield-check" aria-hidden="true"></i>
```

❌ **DON'T:**
```twig
<i class="bi-shield-check"></i>  {# Missing 'bi' prefix! #}
```

### Icon with Text

**Decorative icons next to text:**

```twig
<button class="btn btn-primary">
    <i class="bi bi-plus-circle" aria-hidden="true"></i>
    {{ 'common.create'|trans }}
</button>
```

### Icon-Only Buttons

**Icon-only buttons MUST have aria-label:**

```twig
<button class="btn btn-sm btn-outline-primary"
        aria-label="{{ 'common.view'|trans }}">
    <i class="bi bi-eye" aria-hidden="true"></i>
</button>
```

---

## Accessibility

### ARIA Labels

**Decorative icons:** Use `aria-hidden="true"`
```twig
<i class="bi bi-shield" aria-hidden="true"></i> Security
```

**Icon-only buttons:** Use `aria-label`
```twig
<button aria-label="{{ 'common.delete'|trans }}">
    <i class="bi bi-trash" aria-hidden="true"></i>
</button>
```

### Skip Links

**Pages with complex filters should have skip links:**

```twig
<a href="#filter-section" class="skip-link">
    {{ 'accessibility.skip_to_filters'|trans }}
</a>

{# Later in the page #}
<div id="filter-section" tabindex="-1">
    <form role="search" aria-label="{{ 'common.filters'|trans }}">
        {# Filter inputs #}
    </form>
</div>
```

### Keyboard Navigation

- All interactive elements must be keyboard accessible
- Tab order must be logical
- Focus states must be visible
- Skip links for complex pages

---

## Dark Mode

### Bootstrap 5.3+ Compatibility

The application uses Bootstrap 5.3+ native dark mode support. The theme controller sets **both** attributes:
- `data-theme="dark"` - For our custom CSS rules
- `data-bs-theme="dark"` - For Bootstrap's native dark mode

This ensures all Bootstrap components (cards, alerts, forms, etc.) automatically adapt to dark mode.

### Testing Requirements

**All new components MUST be tested in both modes:**

1. Toggle dark mode: Click theme toggle in header
2. Verify all colors are readable
3. Check that no hardcoded colors appear
4. Test all interactive states (hover, focus, active)
5. Verify Bootstrap components (alerts, cards) have correct backgrounds

### CSS Variable Usage

**Always use CSS variables for colors:**

```css
/* Component styling */
.my-component {
    background: var(--bg-elevated);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.my-component:hover {
    background: var(--bg-secondary);
}
```

**For transparent/adaptive backgrounds:**

```css
.alert-info {
    background-color: rgba(6, 182, 212, 0.1);  /* Adapts to both modes */
    border-left: 4px solid var(--color-info);
    color: var(--text-primary);
}
```

### Dark Mode CSS Rules

**Always include both selectors for dark mode rules:**

```css
/* CORRECT - supports both custom and Bootstrap dark mode */
[data-theme="dark"] .my-component,
[data-bs-theme="dark"] .my-component {
    background: var(--bg-elevated);
}

/* WRONG - only supports custom dark mode */
[data-theme="dark"] .my-component {
    background: var(--bg-elevated);
}
```

### Dark Mode Checklist

Before deploying new features, verify:

- [ ] No hardcoded colors (#fff, #333, etc.)
- [ ] All backgrounds use CSS variables
- [ ] All text uses CSS variables
- [ ] Hover/focus states work in dark mode
- [ ] Borders/shadows are visible in dark mode
- [ ] Form controls have proper dark mode styling
- [ ] Images/icons are visible in dark mode
- [ ] Tested on actual dark mode toggle

---

## Best Practices

### DO's ✅

1. **Use standardized components** from `templates/_components/`
2. **Follow semantic HTML** (proper heading hierarchy, semantic elements)
3. **Use CSS variables** for all colors
4. **Add ARIA labels** to icon-only buttons
5. **Wrap tables** in `.table-responsive`
6. **Test dark mode** before committing
7. **Use translation keys** for all user-facing text
8. **Follow Bootstrap 5.3** conventions
9. **Validate templates** with `php bin/console lint:twig`
10. **Keep accessibility** in mind from the start

### DON'Ts ❌

1. **Don't use inline styles** for colors
2. **Don't skip heading levels** (h1 → h3)
3. **Don't hardcode colors** (#fff, #333, etc.)
4. **Don't forget aria-hidden** on decorative icons
5. **Don't create custom components** without dark mode support
6. **Don't use class names for headings** (use semantic HTML)
7. **Don't forget label associations** on form fields
8. **Don't mix icon prefixes** (always use `bi bi-icon`)
9. **Don't commit** without running lint:twig
10. **Don't deploy** without testing both light and dark modes

---

## Component Checklist

Use this checklist when creating new components:

### Design Phase
- [ ] Component serves a clear purpose
- [ ] Follows existing patterns where applicable
- [ ] Responsive design planned (mobile → desktop)
- [ ] Dark mode colors defined
- [ ] Accessibility requirements identified

### Development Phase
- [ ] Uses CSS variables (no hardcoded colors)
- [ ] Semantic HTML structure
- [ ] ARIA labels where needed
- [ ] Keyboard navigation works
- [ ] Translation keys for all text
- [ ] Mobile responsive (tested 320px+)

### Testing Phase
- [ ] Light mode tested
- [ ] Dark mode tested
- [ ] Screen reader tested
- [ ] Keyboard navigation tested
- [ ] Mobile viewport tested
- [ ] Template validation passed
- [ ] Cross-browser tested

### Documentation Phase
- [ ] Component usage documented
- [ ] Example code provided
- [ ] Parameters documented
- [ ] Edge cases noted

---

## Resources

### Internal
- Component Library: `templates/_components/`
- Form Accessibility Guide: `templates/_components/_FORM_ACCESSIBILITY_GUIDE.md`
- UI/UX Audit Report: `docs/reports/ui-ux-comprehensive-audit-2025-11-19.md`

### External
- [Bootstrap 5.3 Documentation](https://getbootstrap.com/docs/5.3/)
- [Bootstrap Icons](https://icons.getbootstrap.com/)
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [Stimulus Handbook](https://stimulus.hotwired.dev/)

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.1.0 | 2025-11-26 | Added Bootstrap 5.3+ dark mode compatibility (data-bs-theme), updated CSS variable examples |
| 1.0.0 | 2025-11-19 | Initial style guide creation |

---

**Questions?** Check the component library or UI/UX audit report for examples.

**Need help?** Review similar existing pages for implementation patterns.

**Found an issue?** Update this guide and create a pull request!
