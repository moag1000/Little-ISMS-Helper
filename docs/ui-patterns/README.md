# UI/UX Pattern Library - Little ISMS Helper

> **Outdated markup (2025-11).** The Bootstrap examples here (`btn btn-*`, `badge bg-*`, `alert alert-*`, Bootstrap modals)
> fail CI Gate 62. Current components: Aurora v4 macros in `templates/_components/_fa_*.html.twig`, listed in `CLAUDE.md`
> ("Aurora v4 Components"), live preview `/dev/design-system`, mapping in
> [`AURORA_v4_FULL_MIGRATION_PLAN.md`](../design_system/AURORA_v4_FULL_MIGRATION_PLAN.md). The accessibility rules still apply.

**Version:** 1.0
**Date:** 2025-11-24
**Purpose:** Centralized UI/UX guidelines for consistent, accessible design

---

## Overview

This pattern library provides comprehensive guidelines for implementing UI components in Little ISMS Helper. All patterns follow Bootstrap 5 conventions and WCAG 2.1 AA accessibility standards.

### Design Principles

1. **Accessibility First**: WCAG 2.1 Level AA compliance
2. **Consistency**: Reusable components and patterns
3. **Responsive**: Mobile-first, adaptive layouts
4. **Dark Mode**: Full support via CSS variables
5. **Semantic HTML**: Proper markup for screen readers

---

## Pattern Guides

### Core Components

| Guide | Status | Description |
|-------|--------|-------------|
| [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md) | ✅ Complete | Button variants, sizes, icons, states |
| [FORM_PATTERNS.md](FORM_PATTERNS.md) | ✅ Complete | Form fields, validation, layouts |
| [BADGE_PATTERNS.md](BADGE_PATTERNS.md) | ✅ Complete | Badge variants, sizes, semantic usage |
| [TABLE_PATTERNS.md](TABLE_PATTERNS.md) | ✅ Complete | Table component, responsive patterns |
| [MODAL_PATTERNS.md](MODAL_PATTERNS.md) | ✅ Complete | Modal dialogs, ARIA attributes |

### Cross-Cutting Concerns

| Guide | Status | Description |
|-------|--------|-------------|
| [ACCESSIBILITY.md](ACCESSIBILITY.md) | ✅ Complete | WCAG 2.1 AA guidelines, testing |
| CARD_PATTERNS.md | 🔄 Planned | Card component standardization |
| NAVIGATION_PATTERNS.md | 🔄 Planned | Navigation consistency |

---

## Quick Start

### For Developers

1. **Read ACCESSIBILITY.md first** - Core accessibility requirements
2. **Use components** - Prefer `_components/` over raw HTML
3. **Check patterns** - Reference guide before implementing UI
4. **Test accessibility** - Use checklist in each guide

### Common Tasks

**Creating a form:**
→ See [FORM_PATTERNS.md](FORM_PATTERNS.md#using-the-form-field-component)

**Adding a button:**
→ See [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md#basic-button-variants)

**Displaying a table:**
→ See [TABLE_PATTERNS.md](TABLE_PATTERNS.md#basic-table)

**Showing a status badge:**
→ See [BADGE_PATTERNS.md](BADGE_PATTERNS.md#semantic-mapping)

**Creating a modal:**
→ See [MODAL_PATTERNS.md](MODAL_PATTERNS.md#basic-modal-structure)

---

## Component Inventory

### Twig Components (templates/_components/)

| Component | Purpose | Documentation |
|-----------|---------|---------------|
| `_form_field.html.twig` | Accessible form fields | [FORM_PATTERNS.md](FORM_PATTERNS.md) |
| `_table.html.twig` | Responsive tables | [TABLE_PATTERNS.md](TABLE_PATTERNS.md) |
| `_badge.html.twig` | Consistent badges | [BADGE_PATTERNS.md](BADGE_PATTERNS.md) |
| `_bulk_delete_modal.html.twig` | Bulk delete confirmation | [MODAL_PATTERNS.md](MODAL_PATTERNS.md) |
| `_bulk_delete_confirmation.html.twig` | Delete confirmation | [MODAL_PATTERNS.md](MODAL_PATTERNS.md) |

### CSS Files

| File | Purpose |
|------|---------|
| `assets/styles/app.css` | App layer: layout, badges, legacy components, dark-mode residue (merged from five sheets, 2026-11) |
| `assets/styles/fairy-aurora-components.css` | Aurora components |
| `assets/styles/analytics.css` | Chart and analytics styles |

---

## Pattern Status

### Completed (Phase 1)

- ✅ **Button Standardization**: 4 buttons fixed, documentation created
- ✅ **Form Field Component**: WCAG 2.1 AA compliant, documented
- ✅ **ARIA Labels**: 30 close buttons fixed, 17 templates updated
- ✅ **Dark Mode Colors**: 43 hardcoded colors replaced with CSS variables
- ✅ **Badge Syntax**: 20 Bootstrap 4 → 5 conversions, component created
- ✅ **Modal Documentation**: Comprehensive ARIA attribute guide
- ✅ **Table Documentation**: Component usage, responsive patterns

### Total Impact

- **91 files changed** (Initial UI/UX commit)
- **278 files changed** (Badge standardization commit)
- **5 comprehensive documentation files**
- **3 reusable Twig components** created
- **3 utility Python scripts** for automation

---

## Accessibility Compliance

All patterns follow WCAG 2.1 Level AA:

### Perceivable
- ✅ Color contrast 4.5:1 for normal text
- ✅ Color contrast 3:1 for large text and UI components
- ✅ Text alternatives for non-text content (alt, aria-label)

### Operable
- ✅ Keyboard accessible (Tab, Shift+Tab, Enter, Space, ESC)
- ✅ Focus indicators visible
- ✅ Skip links for navigation

### Understandable
- ✅ Form labels and instructions clear
- ✅ Error messages associated with fields
- ✅ Consistent navigation and identification

### Robust
- ✅ Semantic HTML5
- ✅ ARIA attributes for dynamic content
- ✅ Valid markup

---

## Testing Standards

### Automated Testing
- **Lighthouse**: Accessibility score ≥ 95
- **axe DevTools**: No violations
- **WAVE**: No errors
- **HTML Validator**: Valid markup

### Manual Testing
- **Keyboard Navigation**: All interactive elements reachable
- **Screen Reader**: NVDA/JAWS/VoiceOver compatible
- **Mobile**: Responsive on 320px-1920px viewports
- **Dark Mode**: Readable contrast in both themes

---

## Contribution Guidelines

### Adding New Patterns

1. **Create pattern document** in `docs/ui-patterns/`
2. **Follow template structure**:
   - Overview
   - Basic usage
   - Variants
   - Accessibility guidelines
   - Common patterns
   - Testing checklist
   - Best practices
3. **Add to this README** under "Pattern Guides"
4. **Create reusable component** if applicable
5. **Update CSS** if needed (use CSS variables)

### Updating Existing Patterns

1. **Check component usage** before changing
2. **Update documentation** alongside code
3. **Test accessibility** after changes
4. **Validate with linter**: `php bin/console lint:twig templates/`

---

## Dark Mode Implementation

All UI patterns support dark mode via CSS variables:

```css
/* Light mode (default) */
:root {
    --text-primary: #1a1a1a;
    --text-secondary: #64748b;
    --bg-primary: #ffffff;
    --bg-secondary: #f8f9fa;
    --border-color: #dee2e6;
}

/* Dark mode */
[data-theme="dark"] {
    --text-primary: #e5e5e5;
    --text-secondary: #94a3b8;
    --bg-primary: #1a1a1a;
    --bg-secondary: #2d2d2d;
    --border-color: #404040;
}
```

**Never use hardcoded colors** - always use CSS variables or Bootstrap utilities.

---

## Resources

### Internal
- [ROADMAP.md](../../ROADMAP.md)
- [CLAUDE.md](../../CLAUDE.md) - Development guidelines

### External
- [Bootstrap 5 Documentation](https://getbootstrap.com/docs/5.3/)
- [WCAG 2.1 Quick Reference](https://www.w3.org/WAI/WCAG21/quickref/)
- [ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/)
- [MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/Accessibility)

---

## Pattern Library Roadmap

### Phase 1: Core Components (✅ Complete)
- [x] Buttons
- [x] Forms
- [x] Badges
- [x] Tables
- [x] Modals
- [x] Accessibility foundation

### Phase 2: Layout & Navigation (🔄 In Progress)
- [ ] Cards
- [ ] Navigation patterns
- [ ] Breadcrumbs
- [ ] Pagination
- [ ] Alerts/Notifications

### Phase 3: Advanced Components (📋 Planned)
- [ ] Charts (existing analytics patterns)
- [ ] Filters
- [ ] Search patterns
- [ ] File uploads
- [ ] Multi-step forms

---

## Support

For questions or issues with UI patterns:

1. **Check documentation** in this directory first
2. **Review component source** in `templates/_components/`
3. **Test with accessibility tools** (Lighthouse, axe)
4. **Validate templates**: `php bin/console lint:twig templates/`

---

**Last Updated:** 2025-11-24
**Maintained by:** Little ISMS Helper Team
**Status:** Phase 1 Complete (5/5 core patterns documented)
