# Accessibility Guidelines - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-24
**Standard:** WCAG 2.1 AA Compliance
**Purpose:** Ensure accessible UI for all users

---

## Overview

Little ISMS Helper strives for WCAG 2.1 Level AA compliance to ensure the application is accessible to users with disabilities.

### WCAG 2.1 Principles (POUR)

1. **Perceivable**: Information must be presentable to users in ways they can perceive
2. **Operable**: UI components and navigation must be operable
3. **Understandable**: Information and UI operation must be understandable
4. **Robust**: Content must be robust enough to be interpreted by assistive technologies

---

## ARIA Labels

### Close Buttons

All close buttons (`btn-close`) MUST have an `aria-label`:

```twig
{# ✅ CORRECT #}
<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>

{# ❌ INCORRECT #}
<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
```

### Icon-Only Buttons

Buttons with only icons MUST have either `aria-label` or `title`:

```twig
{# ✅ CORRECT - Using title #}
<button class="btn btn-sm btn-primary" title="{{ 'action.edit'|trans }}">
    <i class="bi bi-pencil"></i>
</button>

{# ✅ CORRECT - Using aria-label #}
<button class="btn btn-sm btn-primary" aria-label="{{ 'action.edit'|trans }}">
    <i class="bi bi-pencil"></i>
</button>

{# ❌ INCORRECT #}
<button class="btn btn-sm btn-primary">
    <i class="bi bi-pencil"></i>
</button>
```

### Decorative Icons

Icons that are purely decorative MUST be marked with `aria-hidden="true"`:

```twig
{# ✅ CORRECT #}
<button class="btn btn-primary">
    <i class="bi bi-check-circle" aria-hidden="true"></i> {{ 'action.save'|trans }}
</button>

{# ❌ INCORRECT - Screen reader will read "check circle Save" #}
<button class="btn btn-primary">
    <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
</button>
```

---

## Form Accessibility

### Using the Form Field Component

The `_form_field.html.twig` component provides automatic WCAG 2.1 AA compliance:

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'user.field.email'|trans,
    'help': 'user.help.email_format'|trans,
    'required': true
} %}
```

**Built-in Features:**
- ✅ `aria-invalid`: Validation state
- ✅ `aria-describedby`: Links help text and errors
- ✅ `aria-required`: Required field indication
- ✅ `role="alert"`: Error announcements
- ✅ `aria-live="assertive"`: Priority error announcements
- ✅ Unique IDs for proper association

### Form Validation

Error messages MUST be associated with form fields:

```twig
{# ✅ CORRECT - Component handles this automatically #}
{% include '_components/_form_field.html.twig' with {...} %}

{# ❌ INCORRECT - Manual implementation may miss associations #}
<label for="email">Email</label>
<input type="email" id="email" name="email">
<div class="error">Invalid email</div>
```

### Required Fields

Required fields MUST be indicated both visually and programmatically:

```twig
{# ✅ CORRECT - Component handles this #}
<label for="name">
    Name
    <span class="required" aria-label="{{ 'form.required'|trans }}">*</span>
</label>
<input type="text" id="name" aria-required="true">
```

---

## Semantic HTML

### Headings

Use proper heading hierarchy (h1 → h2 → h3):

```twig
{# ✅ CORRECT #}
<h1>Page Title</h1>
<h2>Section Title</h2>
<h3>Subsection</h3>

{# ❌ INCORRECT - Skipping levels #}
<h1>Page Title</h1>
<h3>Section Title</h3>  <!-- Skipped h2 -->
```

### Landmarks

Use semantic HTML5 landmarks:

```html
<header>...</header>
<nav aria-label="Main navigation">...</nav>
<main>...</main>
<aside aria-label="Related information">...</aside>
<footer>...</footer>
```

### Lists

Use proper list markup:

```twig
{# ✅ CORRECT #}
<ul>
    <li>Item 1</li>
    <li>Item 2</li>
</ul>

{# ❌ INCORRECT #}
<div>
    <div>Item 1</div>
    <div>Item 2</div>
</div>
```

---

## Tables

### Table Headers

All tables MUST have proper headers with `scope`:

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col">{{ 'field.name'|trans }}</th>
            <th scope="col">{{ 'field.status'|trans }}</th>
        </tr>
    {% endblock %}
    ...
{% endembed %}
```

### Responsive Tables

Tables MUST be wrapped in `.table-responsive` for mobile accessibility:

```twig
{# ✅ CORRECT - Component provides this automatically #}
{% embed '_components/_table.html.twig' %}
    ...
{% endembed %}

{# ❌ INCORRECT #}
<table class="table">...</table>
```

---

## Skip Links

### Main Content Skip Link

Provide skip links for keyboard navigation:

```twig
<a href="#main-content" class="skip-link">
    {{ 'accessibility.skip_to_main'|trans }}
</a>

<main id="main-content" tabindex="-1">
    ...
</main>
```

### Skip to Filters

For filter-heavy pages:

```twig
<a href="#filter-section" class="skip-link">
    {{ 'accessibility.skip_to_filters'|trans }}
</a>

<div id="filter-section" tabindex="-1">
    ...
</div>
```

**CSS for skip links:**
```css
.skip-link {
    position: absolute;
    top: -40px;
    left: 0;
    background: var(--color-primary);
    color: white;
    padding: 8px;
    text-decoration: none;
    z-index: 100;
}

.skip-link:focus {
    top: 0;
}
```

---

## Modals

### Modal Accessibility

Modals MUST have proper ARIA attributes:

```twig
<div class="modal fade" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">
                    {{ 'modal.title'|trans }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'action.close'|trans({}, 'messages') }}"></button>
            </div>
            <div class="modal-body">
                ...
            </div>
        </div>
    </div>
</div>
```

**Required attributes:**
- ✅ `id` on modal
- ✅ `tabindex="-1"` on modal
- ✅ `aria-labelledby` pointing to title
- ✅ `aria-hidden="true"` when closed
- ✅ `aria-label` on close button

---

## Color Contrast

### WCAG AA Requirements

- **Normal text (< 18pt)**: Minimum 4.5:1 contrast ratio
- **Large text (≥ 18pt)**: Minimum 3:1 contrast ratio
- **UI components**: Minimum 3:1 contrast ratio

### Testing Tools

- Chrome DevTools Lighthouse
- WAVE Browser Extension
- axe DevTools

### Dark Mode

All color combinations MUST maintain contrast in both light and dark modes:

```css
/* Light mode */
--text-primary: #1a1a1a;
--bg-primary: #ffffff;

/* Dark mode */
[data-theme="dark"] {
    --text-primary: #e5e5e5;
    --bg-primary: #1a1a1a;
}
```

---

## Focus Indicators

### Visible Focus

All interactive elements MUST have visible focus indicators:

```css
/* ✅ CORRECT */
button:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

/* ❌ INCORRECT */
button:focus {
    outline: none;  /* Never remove focus indicators! */
}
```

### Focus Order

Tab order MUST follow logical reading order. Use `tabindex` sparingly:

```html
<!-- ✅ CORRECT - Natural DOM order -->
<button>First</button>
<button>Second</button>
<button>Third</button>

<!-- ❌ INCORRECT - Forced tab order -->
<button tabindex="1">First</button>
<button tabindex="3">Third</button>
<button tabindex="2">Second</button>
```

---

## Alerts and Notifications

### Live Regions

Use `role="alert"` or `aria-live` for dynamic content:

```twig
{# ✅ CORRECT - Immediate announcement #}
<div class="alert alert-success" role="alert">
    <i class="bi bi-check-circle" aria-hidden="true"></i>
    {{ 'message.success'|trans }}
</div>

{# ✅ CORRECT - Polite announcement #}
<div aria-live="polite" aria-atomic="true">
    {{ dynamic_content }}
</div>

{# ✅ CORRECT - Assertive announcement (errors) #}
<div class="invalid-feedback d-block" role="alert" aria-live="assertive">
    {{ error_message }}
</div>
```

---

## Images

### Alt Text

All images MUST have alt text:

```twig
{# ✅ CORRECT - Descriptive alt #}
<img src="chart.png" alt="{{ 'chart.risk_distribution'|trans }}">

{# ✅ CORRECT - Decorative image #}
<img src="decoration.png" alt="" role="presentation">

{# ❌ INCORRECT - Missing alt #}
<img src="chart.png">
```

### Icons as Images

SVG icons MUST have proper labels:

```html
<!-- ✅ CORRECT - Icon with title -->
<svg role="img" aria-labelledby="icon-title">
    <title id="icon-title">Settings</title>
    ...
</svg>

<!-- ✅ CORRECT - Decorative icon -->
<svg aria-hidden="true">
    ...
</svg>
```

---

## Testing Checklist

### Automated Testing

- [ ] Run Lighthouse accessibility audit
- [ ] Run axe DevTools scan
- [ ] Validate with WAVE extension
- [ ] Check HTML validation

### Manual Testing

- [ ] Keyboard navigation (Tab, Shift+Tab, Enter, Space, Esc)
- [ ] Screen reader testing (NVDA, JAWS, VoiceOver)
- [ ] Focus order logical
- [ ] All interactive elements reachable
- [ ] Form validation announced
- [ ] Error messages clear
- [ ] Skip links functional
- [ ] Zoom to 200% without loss of functionality

### Color Contrast

- [ ] All text passes 4.5:1 (normal) or 3:1 (large)
- [ ] UI components pass 3:1
- [ ] Dark mode maintains contrast
- [ ] No information conveyed by color alone

---

## Common Violations

### ❌ Missing Form Labels

```html
<!-- BAD -->
<input type="email" name="email">
```

```html
<!-- GOOD -->
<label for="email">Email</label>
<input type="email" id="email" name="email">
```

### ❌ Empty Links/Buttons

```html
<!-- BAD -->
<a href="/edit"><i class="bi bi-pencil"></i></a>
```

```html
<!-- GOOD -->
<a href="/edit" aria-label="Edit">
    <i class="bi bi-pencil" aria-hidden="true"></i>
</a>
```

### ❌ Non-Semantic Markup

```html
<!-- BAD -->
<div onclick="submit()">Submit</div>
```

```html
<!-- GOOD -->
<button type="submit">Submit</button>
```

---

## Resources

### WCAG 2.1 Guidelines

- [WCAG 2.1 Quick Reference](https://www.w3.org/WAI/WCAG21/quickref/)
- [Understanding WCAG 2.1](https://www.w3.org/WAI/WCAG21/Understanding/)
- [ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/)

### Testing Tools

- [Lighthouse](https://developers.google.com/web/tools/lighthouse)
- [axe DevTools](https://www.deque.com/axe/devtools/)
- [WAVE](https://wave.webaim.org/extension/)
- [Color Contrast Checker](https://webaim.org/resources/contrastchecker/)

### Screen Readers

- [NVDA](https://www.nvaccess.org/) (Windows)
- [JAWS](https://www.freedomscientific.com/products/software/jaws/) (Windows)
- [VoiceOver](https://www.apple.com/accessibility/voiceover/) (macOS/iOS)

---

**Last Updated:** 2025-11-24
**Maintained by:** Little ISMS Helper Team
**See Also:** [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md), [FORM_PATTERNS.md](FORM_PATTERNS.md)
