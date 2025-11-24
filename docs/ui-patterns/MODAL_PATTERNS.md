# Modal Dialog Patterns - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-24
**Standard:** Bootstrap 5 Modal + WCAG 2.1 AA
**Purpose:** Accessible and consistent modal dialog usage

---

## Overview

Modal dialogs are used for focused interactions that require user attention. This guide ensures all modals are accessible, consistent, and follow Bootstrap 5 best practices.

### Key Principles

1. **Accessibility First**: All modals must be keyboard-navigable and screen reader friendly
2. **ARIA Attributes**: Required attributes for screen reader compatibility
3. **Focus Management**: Proper focus trapping and restoration
4. **Semantic HTML**: Use proper heading hierarchy and roles

---

## Required ARIA Attributes

### Minimum Requirements

Every modal MUST have these attributes:

```twig
{# ✅ CORRECT - Complete ARIA attributes #}
<div class="modal fade"
     id="exampleModal"
     tabindex="-1"
     aria-labelledby="exampleModalLabel"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">
                    Modal Title
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                Content
            </div>
        </div>
    </div>
</div>

{# ❌ INCORRECT - Missing ARIA attributes #}
<div class="modal fade" id="exampleModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Modal Title</h2>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
        </div>
    </div>
</div>
```

### Required Attributes Checklist

- [ ] `id` - Unique identifier for the modal
- [ ] `tabindex="-1"` - Makes modal programmatically focusable
- [ ] `aria-labelledby` - Points to the modal title element
- [ ] `aria-hidden="true"` - Hides from screen readers when not shown
- [ ] Title element has matching `id` for `aria-labelledby`
- [ ] Close button has `aria-label` for accessibility

---

## Basic Modal Structure

### Standard Modal

```twig
{% set modal_id = 'confirmActionModal' %}
{% set modal_title_id = modal_id ~ 'Label' %}

<div class="modal fade"
     id="{{ modal_id }}"
     tabindex="-1"
     aria-labelledby="{{ modal_title_id }}"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            {# Header #}
            <div class="modal-header">
                <h5 class="modal-title" id="{{ modal_title_id }}">
                    {{ 'modal.confirm.title'|trans }}
                </h5>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="{{ 'action.close'|trans({}, 'messages') }}">
                </button>
            </div>

            {# Body #}
            <div class="modal-body">
                <p>{{ 'modal.confirm.message'|trans }}</p>
            </div>

            {# Footer #}
            <div class="modal-footer">
                <button type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    {{ 'action.cancel'|trans }}
                </button>
                <button type="button" class="btn btn-primary">
                    {{ 'action.confirm'|trans }}
                </button>
            </div>
        </div>
    </div>
</div>
```

---

## Modal Sizes

### Available Sizes

```twig
{# Small modal #}
<div class="modal-dialog modal-sm">
    ...
</div>

{# Default modal (500px) #}
<div class="modal-dialog">
    ...
</div>

{# Large modal (800px) #}
<div class="modal-dialog modal-lg">
    ...
</div>

{# Extra large modal (1140px) #}
<div class="modal-dialog modal-xl">
    ...
</div>

{# Full-width modal #}
<div class="modal-dialog modal-fullscreen">
    ...
</div>
```

### Centered Modal

```twig
{# Vertically centered #}
<div class="modal-dialog modal-dialog-centered">
    ...
</div>

{# Scrollable content #}
<div class="modal-dialog modal-dialog-scrollable">
    ...
</div>
```

---

## Modal Variants

### Confirmation Dialog

```twig
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="confirmDeleteModalLabel">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    {{ 'modal.delete.title'|trans }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                    {{ 'modal.delete.warning'|trans }}
                </div>
                <p>{{ 'modal.delete.confirm_message'|trans({'%name%': item.name}) }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    {{ 'action.cancel'|trans }}
                </button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    {{ 'action.delete'|trans }}
                </button>
            </div>
        </div>
    </div>
</div>
```

### Form Modal

```twig
<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" action="{{ path('app_item_create') }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="addItemModalLabel">
                        {{ 'item.action.add'|trans }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
                </div>
                <div class="modal-body">
                    {{ form_row(form.name) }}
                    {{ form_row(form.description) }}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        {{ 'action.cancel'|trans }}
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                        {{ 'action.add'|trans }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

---

## Colored Modal Headers (Issue 11.2)

**IMPORTANT:** Use colored headers to indicate modal purpose and urgency.

### Standard Header Color Mapping

| Modal Type | Header Class | Text Class | Button Class | Use Case |
|------------|-------------|------------|--------------|----------|
| Delete/Danger | `bg-danger` | `text-white` | `btn-close-white` | Destructive actions, deletions |
| Warning | `bg-warning` | `text-dark` | `btn-close` | Important warnings, confirmations |
| Success | `bg-success` | `text-white` | `btn-close-white` | Success confirmations, completions |
| Info | `bg-info` | `text-white` | `btn-close-white` | Help, information, guidance |
| Primary | `bg-primary` | `text-white` | `btn-close-white` | Standard actions, forms |

### Examples

#### Danger Modal (Delete Confirmation)
```twig
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title" id="deleteModalLabel">
        <i class="bi bi-exclamation-triangle-fill"></i>
        {{ 'modal.delete.title'|trans }}
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans }}"></button>
</div>
```

#### Warning Modal (Important Action)
```twig
<div class="modal-header bg-warning text-dark">
    <h5 class="modal-title" id="warningModalLabel">
        <i class="bi bi-exclamation-circle-fill"></i>
        {{ 'modal.warning.title'|trans }}
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans }}"></button>
</div>
```

#### Success Modal (Completion)
```twig
<div class="modal-header bg-success text-white">
    <h5 class="modal-title" id="successModalLabel">
        <i class="bi bi-check-circle-fill"></i>
        {{ 'modal.success.title'|trans }}
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans }}"></button>
</div>
```

#### Info Modal (Help/Guidance)
```twig
<div class="modal-header bg-info text-white">
    <h5 class="modal-title" id="infoModalLabel">
        <i class="bi bi-info-circle-fill"></i>
        {{ 'modal.info.title'|trans }}
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans }}"></button>
</div>
```

### ❌ Anti-Patterns

```twig
{# ❌ DON'T: Wrong close button color on dark background #}
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title">Delete</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>  <!-- Should be btn-close-white -->
</div>

{# ❌ DON'T: Missing icon for visual recognition #}
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title">Delete</h5>  <!-- Missing icon -->
</div>

{# ❌ DON'T: Inconsistent color usage #}
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title">Create New Item</h5>  <!-- Wrong color for creation action -->
</div>

{# ✅ DO: Correct color for creation action #}
<div class="modal-header bg-primary text-white">
    <h5 class="modal-title">
        <i class="bi bi-plus-circle"></i>
        Create New Item
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
```

### Icon Guidelines

Always include an icon in colored modal headers for visual recognition:

| Modal Type | Recommended Icon | Bootstrap Icon Class |
|------------|------------------|---------------------|
| Delete | ⚠️ Triangle | `bi-exclamation-triangle-fill` |
| Warning | ⚠️ Circle | `bi-exclamation-circle-fill` |
| Success | ✓ Check | `bi-check-circle-fill` |
| Info | ℹ️ Info | `bi-info-circle-fill` |
| Error | ✕ X | `bi-x-circle-fill` |
| Create | + Plus | `bi-plus-circle-fill` |

---

### Information Modal

```twig
<div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="helpModalLabel">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    {{ 'help.title'|trans }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                <h6>{{ 'help.section1.title'|trans }}</h6>
                <p>{{ 'help.section1.content'|trans }}</p>

                <h6 class="mt-3">{{ 'help.section2.title'|trans }}</h6>
                <p>{{ 'help.section2.content'|trans }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                    {{ 'action.close'|trans }}
                </button>
            </div>
        </div>
    </div>
</div>
```

---

## Advanced Patterns

### Modal with Description

For complex modals, use `aria-describedby` to reference additional description:

```twig
<div class="modal fade"
     id="complexModal"
     tabindex="-1"
     aria-labelledby="complexModalLabel"
     aria-describedby="complexModalDescription"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="complexModalLabel">
                    {{ 'modal.title'|trans }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                <p id="complexModalDescription">
                    {{ 'modal.description'|trans }}
                </p>
                {# Rest of content #}
            </div>
        </div>
    </div>
</div>
```

### Loading State Modal

```twig
<div class="modal fade" id="loadingModal" tabindex="-1" aria-labelledby="loadingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">{{ 'common.loading'|trans }}</span>
                </div>
                <h5 id="loadingModalLabel">{{ 'modal.loading.title'|trans }}</h5>
                <p class="text-muted">{{ 'modal.loading.message'|trans }}</p>
            </div>
        </div>
    </div>
</div>
```

---

## JavaScript Interaction

### Opening Modals

```javascript
// Using data attributes (preferred)
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#myModal">
    Open Modal
</button>

// Using JavaScript
const modal = new bootstrap.Modal(document.getElementById('myModal'));
modal.show();
```

### Modal Events

```javascript
const modalEl = document.getElementById('myModal');

modalEl.addEventListener('show.bs.modal', function (event) {
    // Triggered when modal is about to be shown
});

modalEl.addEventListener('shown.bs.modal', function (event) {
    // Triggered when modal is fully shown
    // Good place to set focus
});

modalEl.addEventListener('hide.bs.modal', function (event) {
    // Triggered when modal is about to be hidden
});

modalEl.addEventListener('hidden.bs.modal', function (event) {
    // Triggered when modal is fully hidden
    // Good place to clean up
});
```

### Dynamic Content

```javascript
// Passing data to modal
const buttons = document.querySelectorAll('[data-bs-toggle="modal"]');
buttons.forEach(button => {
    button.addEventListener('click', function() {
        const itemId = this.getAttribute('data-item-id');
        const itemName = this.getAttribute('data-item-name');

        // Update modal content
        const modal = document.getElementById('deleteModal');
        modal.querySelector('.modal-body').textContent =
            `Delete ${itemName}?`;
    });
});
```

---

## Accessibility Guidelines

### Focus Management

1. **Initial Focus**: Bootstrap automatically focuses the modal when opened
2. **Focus Trap**: Tab cycles within modal only
3. **Focus Restoration**: Focus returns to trigger element on close

```javascript
// Manual focus management if needed
modalEl.addEventListener('shown.bs.modal', function () {
    // Focus first input or specific element
    const firstInput = modalEl.querySelector('input, select, textarea');
    if (firstInput) {
        firstInput.focus();
    }
});
```

### Keyboard Navigation

- **ESC**: Close modal (unless `data-bs-keyboard="false"`)
- **Tab**: Navigate between focusable elements
- **Shift+Tab**: Navigate backwards
- **Enter/Space**: Activate focused button

### Screen Reader Announcements

```twig
{# Live region for dynamic updates #}
<div class="modal-body">
    <div aria-live="polite" aria-atomic="true">
        {# Content that may update dynamically #}
    </div>
</div>

{# Alert for errors #}
<div class="modal-body">
    <div class="alert alert-danger" role="alert" aria-live="assertive">
        {{ error_message }}
    </div>
</div>
```

---

## Modal Header Variants

### Colored Headers

```twig
{# Danger (delete/destructive actions) #}
<div class="modal-header bg-danger text-white">
    <h5 class="modal-title" id="modalLabel">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        {{ 'modal.delete.title'|trans }}
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
</div>

{# Success #}
<div class="modal-header bg-success text-white">
    ...
    <button type="button" class="btn-close btn-close-white" ...></button>
</div>

{# Info #}
<div class="modal-header bg-info text-white">
    ...
</div>

{# Warning #}
<div class="modal-header bg-warning">
    ...
</div>
```

---

## Common Patterns

### Delete Confirmation

```twig
{% for item in items %}
    <button type="button"
            class="btn btn-sm btn-danger"
            data-bs-toggle="modal"
            data-bs-target="#deleteModal{{ item.id }}"
            title="{{ 'action.delete'|trans }}">
        <i class="bi bi-trash" aria-hidden="true"></i>
    </button>

    {# Modal #}
    <div class="modal fade" id="deleteModal{{ item.id }}" tabindex="-1" aria-labelledby="deleteModal{{ item.id }}Label" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModal{{ item.id }}Label">
                        {{ 'modal.delete.title'|trans }}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
                </div>
                <div class="modal-body">
                    {{ 'modal.delete.confirm'|trans({'%name%': item.name}) }}
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        {{ 'action.cancel'|trans }}
                    </button>
                    <form method="post" action="{{ path('app_item_delete', {id: item.id}) }}" style="display: inline;">
                        <input type="hidden" name="_token" value="{{ csrf_token('delete' ~ item.id) }}">
                        <button type="submit" class="btn btn-danger">
                            {{ 'action.delete'|trans }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
{% endfor %}
```

### Multi-Step Modal

```twig
<div class="modal fade" id="wizardModal" tabindex="-1" aria-labelledby="wizardModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="wizardModalLabel">
                    {{ 'wizard.title'|trans }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                {# Progress indicator #}
                <div class="progress mb-4" role="progressbar" aria-label="Wizard progress" aria-valuenow="33" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width: 33%">Step 1 of 3</div>
                </div>

                {# Step content #}
                <div data-wizard-step="1">
                    <h6>{{ 'wizard.step1.title'|trans }}</h6>
                    {# Step 1 form fields #}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    {{ 'action.cancel'|trans }}
                </button>
                <button type="button" class="btn btn-outline-secondary" id="prevStep" disabled>
                    {{ 'wizard.previous'|trans }}
                </button>
                <button type="button" class="btn btn-primary" id="nextStep">
                    {{ 'wizard.next'|trans }}
                </button>
            </div>
        </div>
    </div>
</div>
```

---

## Testing Checklist

### Accessibility Testing

- [ ] Modal has unique `id`
- [ ] Modal has `tabindex="-1"`
- [ ] Modal has `aria-labelledby` pointing to title
- [ ] Modal has `aria-hidden="true"` when closed
- [ ] Modal title has matching `id` attribute
- [ ] Close button has `aria-label`
- [ ] Focus moves to modal when opened
- [ ] Focus trapped within modal
- [ ] Focus returns to trigger on close
- [ ] ESC key closes modal
- [ ] Keyboard navigation works (Tab, Shift+Tab)

### Visual Testing

- [ ] Modal centers correctly
- [ ] Modal size appropriate for content
- [ ] Modal readable in light and dark mode
- [ ] Backdrop visible and clickable (if enabled)
- [ ] Animations smooth
- [ ] Mobile responsive

### Screen Reader Testing

- [ ] Modal announced when opened
- [ ] Title announced correctly
- [ ] Form errors announced
- [ ] Close action clear
- [ ] Modal type understood (dialog, alert)

---

## Best Practices

### DO

- ✅ Use unique, descriptive modal IDs
- ✅ Include all required ARIA attributes
- ✅ Use semantic HTML in modal content
- ✅ Provide clear close mechanisms
- ✅ Use appropriate modal size for content
- ✅ Include proper button variants (primary/secondary/danger)
- ✅ Use translated strings, not hardcoded text
- ✅ Test with keyboard only
- ✅ Test with screen readers

### DON'T

- ❌ Nest modals (modal within modal)
- ❌ Auto-open modals on page load (except critical warnings)
- ❌ Use modal for lengthy content (use page instead)
- ❌ Remove close button without good reason
- ❌ Forget to manage focus
- ❌ Use inline styles
- ❌ Hardcode text instead of translations

---

## Quick Reference

### Minimal Modal Template

```twig
<div class="modal fade" id="MODAL_ID" tabindex="-1" aria-labelledby="MODAL_IDLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="MODAL_IDLabel">TITLE</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                CONTENT
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary">Confirm</button>
            </div>
        </div>
    </div>
</div>
```

---

**Last Updated:** 2025-11-24
**Maintained by:** Little ISMS Helper Team
**See Also:** [ACCESSIBILITY.md](ACCESSIBILITY.md), [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md), [FORM_PATTERNS.md](FORM_PATTERNS.md)
