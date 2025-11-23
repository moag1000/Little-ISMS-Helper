# Form Layout Patterns - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-23
**Purpose:** Standardized form layouts and patterns

---

## Form Field Component

### Basic Usage

Always use the `_form_field.html.twig` component for consistent, accessible form fields:

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.fieldName,
    'label': 'form.label.field_name'|trans,
    'help': 'form.help.field_name'|trans,
    'required': true
} %}
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `field` | FormView | Yes | The Symfony form field |
| `label` | string | Yes | Translated label text |
| `help` | string | No | Optional help text |
| `required` | boolean | No | Override required state (auto-detected) |

---

## Form Structure

### Standard Form Template

```twig
{% extends 'base.html.twig' %}
{% trans_default_domain 'your_domain' %}

{% block title %}{{ 'page.title'|trans }}{% endblock %}

{% block body %}
<div class="container">
    <div class="page-header">
        <h1>{{ 'page.heading'|trans }}</h1>
    </div>

    {{ form_start(form, {'attr': {'novalidate': 'novalidate'}}) }}

    {# Form fields using component #}
    {% include '_form_partial.html.twig' %}

    {# Form actions #}
    <div class="d-flex gap-1 mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
        </button>
        <a href="{{ path('app_index') }}" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
        </a>
    </div>

    {{ form_end(form) }}
</div>
{% endblock %}
```

---

## Form Sections with Fieldsets

### Grouping Related Fields

```twig
<fieldset class="mb-4">
    <legend class="h5 mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        {{ 'section.basic_information'|trans }}
    </legend>

    <div class="row">
        <div class="col-md-6">
            {% include '_components/_form_field.html.twig' with {
                'field': form.firstName,
                'label': 'user.field.first_name'|trans,
                'required': true
            } %}
        </div>

        <div class="col-md-6">
            {% include '_components/_form_field.html.twig' with {
                'field': form.lastName,
                'label': 'user.field.last_name'|trans,
                'required': true
            } %}
        </div>
    </div>
</fieldset>
```

**Best practices:**
- Use `<fieldset>` to group related fields
- Use `<legend>` with icon for section headers
- Add descriptive `class="h5 mb-3"` for consistent styling

---

## Grid Layouts

### Two-Column Layout

```twig
<div class="row">
    <div class="col-md-6">
        {% include '_components/_form_field.html.twig' with {
            'field': form.fieldLeft,
            'label': 'form.field_left'|trans
        } %}
    </div>

    <div class="col-md-6">
        {% include '_components/_form_field.html.twig' with {
            'field': form.fieldRight,
            'label': 'form.field_right'|trans
        } %}
    </div>
</div>
```

### Three-Column Layout

```twig
<div class="row">
    <div class="col-md-4">
        {% include '_components/_form_field.html.twig' with {
            'field': form.field1,
            'label': 'form.field1'|trans
        } %}
    </div>

    <div class="col-md-4">
        {% include '_components/_form_field.html.twig' with {
            'field': form.field2,
            'label': 'form.field2'|trans
        } %}
    </div>

    <div class="col-md-4">
        {% include '_components/_form_field.html.twig' with {
            'field': form.field3,
            'label': 'form.field3'|trans
        } %}
    </div>
</div>
```

### Full-Width Fields

```twig
<div class="row">
    <div class="col-12">
        {% include '_components/_form_field.html.twig' with {
            'field': form.description,
            'label': 'form.description'|trans,
            'help': 'form.help.description'|trans
        } %}
    </div>
</div>
```

---

## Form Validation

### Error Display

The `_form_field.html.twig` component automatically handles error display. No additional code needed!

**Features:**
- ✅ Automatic error message display below field
- ✅ Red border on invalid fields (`is-invalid` class)
- ✅ ARIA attributes for screen readers
- ✅ Icon indicators

### Validation Messages

Error messages are handled by Symfony validation constraints. Ensure translations exist:

```yaml
# translations/validators.de.yaml
This value should not be blank.: Dieses Feld darf nicht leer sein.
This value is too short.: Dieser Wert ist zu kurz.
```

---

## Required Field Indicators

Required fields automatically show a red asterisk `*`:

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'user.field.email'|trans,
    'required': true  {# Shows asterisk #}
} %}
```

The asterisk has `aria-label="{{ 'form.required'|trans }}"` for accessibility.

---

## Help Text

### Inline Help Text

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.password,
    'label': 'user.field.password'|trans,
    'help': 'user.help.password_requirements'|trans,
    'required': true
} %}
```

### Info Boxes for Section Help

```twig
<div class="alert alert-info" role="status">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    {{ 'form.help.section_description'|trans }}
</div>
```

---

## Special Field Types

### Select Dropdowns

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.country,
    'label': 'user.field.country'|trans,
    'help': 'user.help.select_country'|trans
} %}
```

### Checkboxes

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.agreeToTerms,
    'label': 'user.field.agree_to_terms'|trans,
    'required': true
} %}
```

### Date Fields

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.birthDate,
    'label': 'user.field.birth_date'|trans,
    'help': 'user.help.date_format'|trans
} %}
```

### Textarea

```twig
{% include '_components/_form_field.html.twig' with {
    'field': form.notes,
    'label': 'form.field.notes'|trans,
    'help': 'form.help.max_500_chars'|trans
} %}
```

---

## Form Submit Actions

### Standard Submit Buttons

```twig
<div class="d-flex gap-1 mt-3">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
    </button>
    <a href="{{ path('app_index') }}" class="btn btn-secondary">
        <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
    </a>
</div>
```

### Save and Continue

```twig
<div class="d-flex gap-1 mt-3">
    <button type="submit" name="save" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
    </button>
    <button type="submit" name="save_and_continue" class="btn btn-success">
        <i class="bi bi-arrow-right-circle"></i> {{ 'action.save_and_continue'|trans }}
    </button>
    <a href="{{ path('app_index') }}" class="btn btn-secondary">
        <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
    </a>
</div>
```

### Delete Button in Edit Form

```twig
<div class="d-flex gap-1 mt-3 justify-content-between">
    <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
        </button>
        <a href="{{ path('app_index') }}" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
        </a>
    </div>

    {% if is_granted('ROLE_ADMIN') %}
    <form method="post" action="{{ path('app_delete', {id: item.id}) }}" class="d-inline" onsubmit="return confirm('{{ 'action.confirm_delete'|trans }}');">
        <input type="hidden" name="_token" value="{{ csrf_token('delete' ~ item.id) }}">
        <button type="submit" class="btn btn-danger">
            <i class="bi bi-trash"></i> {{ 'action.delete'|trans }}
        </button>
    </form>
    {% endif %}
</div>
```

---

## Accessibility Features

The `_form_field.html.twig` component provides WCAG 2.1 AA compliance:

### Built-in Features

1. **`aria-invalid`**: Indicates validation state
2. **`aria-describedby`**: Links help text and errors
3. **`aria-required`**: Announces required fields
4. **`role="alert"`**: Error messages announced immediately
5. **`aria-live="assertive"`**: Errors interrupt screen reader
6. **Unique IDs**: All elements properly associated

### WCAG Criteria Met

- ✅ 1.3.1 Info and Relationships (Level A)
- ✅ 3.3.1 Error Identification (Level A)
- ✅ 3.3.2 Labels or Instructions (Level A)
- ✅ 3.3.3 Error Suggestion (Level AA)
- ✅ 4.1.3 Status Messages (Level AA)

---

## Anti-Patterns (DO NOT USE)

### ❌ Direct form_row Usage

```twig
{# DON'T DO THIS #}
{{ form_row(form.email) }}
```

### ✅ Use Form Field Component

```twig
{# DO THIS INSTEAD #}
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'user.field.email'|trans,
    'required': true
} %}
```

---

### ❌ Hardcoded Labels

```twig
{# DON'T DO THIS #}
<label>Email Address</label>
{{ form_widget(form.email) }}
```

### ✅ Use Translations

```twig
{# DO THIS INSTEAD #}
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'user.field.email'|trans
} %}
```

---

### ❌ Missing Error Handling

```twig
{# DON'T DO THIS #}
{{ form_widget(form.email) }}
```

### ✅ Component Handles Errors

```twig
{# DO THIS INSTEAD - errors handled automatically #}
{% include '_components/_form_field.html.twig' with {
    'field': form.email,
    'label': 'user.field.email'|trans
} %}
```

---

## Form Styling Classes

### Bootstrap 5 Form Classes

- `form-group` - Wrapper for each field (included in component)
- `form-label` - Label styling (included in component)
- `form-control` - Input/select/textarea styling
- `form-text` - Help text styling (included in component)
- `is-invalid` - Error state (auto-applied)
- `invalid-feedback` - Error message (included in component)

### Custom Classes

- `required` - Red asterisk for required fields
- `mb-3` - Margin bottom (spacing between fields)
- `mb-4` - Margin bottom for fieldsets

---

## Quick Migration Guide

### Old Pattern (form_row)

```twig
{{ form_start(form) }}
    {{ form_row(form.title) }}
    {{ form_row(form.description) }}
    {{ form_row(form.status) }}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

### New Pattern (_form_field component)

```twig
{{ form_start(form, {'attr': {'novalidate': 'novalidate'}}) }}

    {% include '_components/_form_field.html.twig' with {
        'field': form.title,
        'label': 'form.field.title'|trans,
        'required': true
    } %}

    {% include '_components/_form_field.html.twig' with {
        'field': form.description,
        'label': 'form.field.description'|trans,
        'help': 'form.help.description'|trans
    } %}

    {% include '_components/_form_field.html.twig' with {
        'field': form.status,
        'label': 'form.field.status'|trans
    } %}

    <div class="d-flex gap-1 mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
        </button>
        <a href="{{ path('app_index') }}" class="btn btn-secondary">
            <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
        </a>
    </div>

{{ form_end(form) }}
```

---

**Last Updated:** 2025-11-23
**Maintained by:** Little ISMS Helper Team
**See Also:** [BUTTON_PATTERNS.md](BUTTON_PATTERNS.md)
