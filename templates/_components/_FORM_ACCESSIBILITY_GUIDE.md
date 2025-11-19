# Form Accessibility Guide

This guide explains how to create WCAG 2.1 AA compliant forms in Little ISMS Helper.

## 📚 Table of Contents

- [Quick Start](#quick-start)
- [Using the Form Field Component](#using-the-form-field-component)
- [ARIA Attributes Reference](#aria-attributes-reference)
- [Manual Form Implementation](#manual-form-implementation)
- [Testing Accessibility](#testing-accessibility)
- [Common Pitfalls](#common-pitfalls)

---

## 🚀 Quick Start

### Use the Accessible Form Field Component

Instead of manually rendering form fields, use our pre-built accessible component:

```twig
{# OLD WAY (Not accessible) #}
{{ form_label(form.email) }}
{{ form_widget(form.email) }}
{{ form_errors(form.email) }}

{# NEW WAY (Fully accessible) #}
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'form.label.email'|trans,
    'help': 'form.help.email'|trans,
    'required': true
} %}
```

---

## 🧩 Using the Form Field Component

### Basic Usage

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.firstName,
    'label': 'user.field.first_name'|trans,
    'required': true
} %}
```

### With Help Text

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.password,
    'label': 'user.field.password'|trans,
    'help': 'user.help.password'|trans,
    'required': true
} %}
```

### Optional Field

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.phoneNumber,
    'label': 'user.field.phone'|trans,
    'help': 'user.help.phone'|trans,
    'required': false
} %}
```

### Complete Form Example

```twig
{# templates/user/edit.html.twig #}
{% extends 'base.html.twig' %}

{% block content %}
<h1>{{ 'user.title.edit'|trans }}</h1>

{{ form_start(form, {'attr': {'novalidate': 'novalidate'}}) }}

    {# First Name #}
    {% include '_components/_form_field.html.twig' with {
        'field': form.firstName,
        'label': 'user.field.first_name'|trans,
        'required': true
    } %}

    {# Last Name #}
    {% include '_components/_form_field.html.twig' with {
        'field': form.lastName,
        'label': 'user.field.last_name'|trans,
        'required': true
    } %}

    {# Email with help text #}
    {% include '_components/_form_field.html.twig' with {
        'field': form.email,
        'label': 'user.field.email'|trans,
        'help': 'user.help.email'|trans,
        'required': true
    } %}

    {# Password with security help #}
    {% include '_components/_form_field.html.twig' with {
        'field': form.password,
        'label': 'user.field.password'|trans,
        'help': 'user.help.password_requirements'|trans,
        'required': false
    } %}

    {# Submit Button #}
    <div class="form-actions mt-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save" aria-hidden="true"></i>
            {{ 'form.action.save'|trans }}
        </button>
        <a href="{{ path('app_user_index') }}" class="btn btn-secondary">
            {{ 'form.action.cancel'|trans }}
        </a>
    </div>

{{ form_end(form) }}
{% endblock %}
```

---

## 🏷️ ARIA Attributes Reference

### Essential ARIA Attributes

| Attribute | Purpose | Example |
|-----------|---------|---------|
| `aria-invalid` | Indicates validation state | `aria-invalid="true"` when errors exist |
| `aria-describedby` | Links help text/errors to input | `aria-describedby="email_help email_error"` |
| `aria-required` | Announces required fields | `aria-required="true"` for mandatory fields |
| `aria-label` | Provides accessible name | `aria-label="Close dialog"` for icon buttons |
| `role="alert"` | Announces errors immediately | Applied to error messages |
| `aria-live="assertive"` | Interrupts screen reader | For critical error announcements |

### Implementation in Component

Our `_form_field.html.twig` component automatically adds:

```html
<!-- Input field -->
<input
    type="text"
    id="user_email"
    name="user[email]"
    aria-invalid="false"
    aria-describedby="user_email_help"
    aria-required="true"
    class="form-control"
/>

<!-- Help text -->
<div id="user_email_help" class="form-text">
    Enter your email address for account recovery
</div>

<!-- Error message (when invalid) -->
<div id="user_email_error" class="invalid-feedback" role="alert" aria-live="assertive">
    This email address is already in use
</div>
```

---

## 🛠️ Manual Form Implementation

If you can't use the component, follow this pattern:

### Step 1: Form Field Structure

```twig
<div class="form-group mb-3">
    {# Label #}
    <label for="user_email" class="form-label">
        {{ 'user.field.email'|trans }}
        <span class="required" aria-label="{{ 'form.required'|trans }}">*</span>
    </label>

    {# Help text (optional) #}
    <div id="user_email_help" class="form-text text-muted">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        {{ 'user.help.email'|trans }}
    </div>

    {# Input field #}
    {{ form_widget(form.email, {
        'attr': {
            'class': 'form-control' ~ (form.email.vars.errors|length > 0 ? ' is-invalid' : ''),
            'aria-invalid': form.email.vars.errors|length > 0 ? 'true' : 'false',
            'aria-describedby': 'user_email_help' ~ (form.email.vars.errors|length > 0 ? ' user_email_error' : ''),
            'aria-required': 'true'
        }
    }) }}

    {# Error messages #}
    {% if form.email.vars.errors|length > 0 %}
        <div id="user_email_error" class="invalid-feedback d-block" role="alert" aria-live="assertive">
            {{ form_errors(form.email) }}
        </div>
    {% endif %}
</div>
```

### Step 2: Add Required Translations

```yaml
# translations/messages.de.yaml
form:
  required: 'Pflichtfeld'
  action:
    save: 'Speichern'
    cancel: 'Abbrechen'
    submit: 'Absenden'

user:
  field:
    first_name: 'Vorname'
    last_name: 'Nachname'
    email: 'E-Mail-Adresse'
    password: 'Passwort'
  help:
    email: 'Geben Sie eine gültige E-Mail-Adresse ein'
    password_requirements: 'Mindestens 8 Zeichen, inkl. Großbuchstaben, Kleinbuchstaben und Zahl'
```

---

## 🧪 Testing Accessibility

### Automated Tools

1. **axe DevTools** (Chrome/Firefox Extension)
   ```
   1. Install axe DevTools extension
   2. Open your form page
   3. Run "Scan this page"
   4. Fix all Form-related violations
   ```

2. **WAVE** (Web Accessibility Evaluation Tool)
   ```
   Visit: https://wave.webaim.org/
   Enter your URL
   Check for:
   - Missing labels
   - Invalid ARIA
   - Missing error associations
   ```

### Manual Testing

1. **Keyboard Navigation**
   ```
   - Tab through all form fields
   - Verify tab order is logical
   - Ensure all fields are reachable
   - Test form submission with Enter key
   ```

2. **Screen Reader Testing**
   ```
   macOS: VoiceOver (Cmd + F5)
   Windows: NVDA (free) or JAWS

   Test:
   - Field labels are announced
   - Required status is announced
   - Help text is read
   - Errors are announced immediately
   ```

3. **Error State Testing**
   ```
   1. Submit form with invalid data
   2. Verify errors are announced
   3. Check aria-invalid changes to "true"
   4. Verify error message IDs match aria-describedby
   ```

### Testing Checklist

- [ ] All form fields have associated labels
- [ ] Required fields are marked with `aria-required="true"`
- [ ] Help text is linked via `aria-describedby`
- [ ] Error messages are linked via `aria-describedby`
- [ ] Error messages have `role="alert"`
- [ ] Invalid fields have `aria-invalid="true"`
- [ ] Tab order is logical
- [ ] Focus is visible on all elements
- [ ] Form can be submitted with keyboard only
- [ ] Screen reader announces all relevant information

---

## ⚠️ Common Pitfalls

### ❌ Don't: Missing aria-describedby

```html
<!-- BAD: Error not associated with input -->
<input type="text" id="email" aria-invalid="true" />
<div class="error">Invalid email</div>
```

### ✅ Do: Proper Association

```html
<!-- GOOD: Error linked to input -->
<input type="text" id="email" aria-invalid="true" aria-describedby="email_error" />
<div id="email_error" class="error" role="alert">Invalid email</div>
```

---

### ❌ Don't: Placeholder as Label

```html
<!-- BAD: Placeholder disappears on focus -->
<input type="text" placeholder="Enter your email" />
```

### ✅ Do: Proper Label

```html
<!-- GOOD: Label always visible -->
<label for="email">Email Address</label>
<input type="text" id="email" placeholder="e.g., user@example.com" />
```

---

### ❌ Don't: Generic Error Messages

```html
<!-- BAD: Not helpful -->
<div class="error">Invalid input</div>
```

### ✅ Do: Specific Error Messages

```html
<!-- GOOD: Clear guidance -->
<div class="error" role="alert">
    Email address must contain an @ symbol
</div>
```

---

### ❌ Don't: Missing Required Indicator

```html
<!-- BAD: No visual or programmatic indication -->
<label for="email">Email</label>
<input type="text" id="email" required />
```

### ✅ Do: Clear Required Indicator

```html
<!-- GOOD: Both visual and programmatic -->
<label for="email">
    Email <span class="required" aria-label="required">*</span>
</label>
<input type="text" id="email" aria-required="true" />
```

---

## 📚 Resources

- [WCAG 2.1 Form Guidelines](https://www.w3.org/WAI/WCAG21/Understanding/labels-or-instructions)
- [ARIA Authoring Practices - Forms](https://www.w3.org/WAI/ARIA/apg/patterns/)
- [WebAIM Form Accessibility](https://webaim.org/techniques/forms/)
- [MDN ARIA Best Practices](https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/forms)

---

## 🔄 Migration Guide

### Converting Existing Forms

1. **Identify all forms** in `templates/*/edit.html.twig` and `*/new.html.twig`
2. **Replace manual rendering** with component includes
3. **Add missing translations** for help text
4. **Test with screen reader**
5. **Validate with axe DevTools**

Example migration:

```diff
- {{ form_label(form.email) }}
- {{ form_widget(form.email) }}
- {{ form_errors(form.email) }}

+ {% include '_components/_form_field.html.twig' with {
+     'field': form.email,
+     'label': 'user.field.email'|trans,
+     'help': 'user.help.email'|trans,
+     'required': true
+ } %}
```

---

**Last Updated:** 2025-11-14
**WCAG Compliance Level:** AA
**Maintained by:** Little ISMS Helper Project
