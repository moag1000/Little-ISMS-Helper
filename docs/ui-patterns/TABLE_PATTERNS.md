# Table Patterns - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-24
**Standard:** Bootstrap 5 Tables + WCAG 2.1 AA
**Purpose:** Consistent, accessible, and responsive tables

---

## Overview

Tables are used throughout the application to display structured data. All non-PDF tables should use the `_table.html.twig` component for consistency and accessibility.

### Key Principles

1. **Use the Component**: Always use `{% embed '_components/_table.html.twig' %}` for HTML tables
2. **Responsive by Default**: Component includes `.table-responsive` wrapper automatically
3. **Semantic HTML**: Proper `<thead>`, `<tbody>`, and scope attributes
4. **Accessible**: WCAG 2.1 AA compliant with proper headers

---

## Table Component Usage

### Basic Table

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col">{{ 'field.name'|trans({}, 'field') }}</th>
            <th scope="col">{{ 'field.status'|trans({}, 'field') }}</th>
            <th scope="col" class="text-end">{{ 'field.actions'|trans({}, 'field') }}</th>
        </tr>
    {% endblock %}

    {% block table_body %}
        {% for item in items %}
        <tr>
            <td>{{ item.name }}</td>
            <td>
                <span class="badge bg-{{ item.active ? 'success' : 'secondary' }}">
                    {{ item.active ? 'Active' : 'Inactive' }}
                </span>
            </td>
            <td class="text-end">
                <a href="{{ path('app_item_edit', {id: item.id}) }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
            </td>
        </tr>
        {% endfor %}
    {% endblock %}
{% endembed %}
```

---

## Table Variants

### Component Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `variant` | string | `'default'` | Table style: `default`, `striped`, `small`, `bordered`, `borderless` |
| `responsive` | bool | `true` | Enable responsive wrapper |
| `stickyHeader` | bool | `false` | Enable sticky header for scrolling |
| `hover` | bool | `true` | Enable row hover effect |
| `noMargin` | bool | `false` | Remove bottom margin |
| `theadClass` | string | `null` | Additional thead classes (`table-light`, `table-dark`) |
| `class` | string | `null` | Additional table classes |
| `wrapperClass` | string | `null` | Additional wrapper classes |
| `id` | string | `null` | HTML id attribute |

### Striped Table

```twig
{% embed '_components/_table.html.twig' with {'variant': 'striped'} %}
    {% block table_head %}
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Value</th>
        </tr>
    {% endblock %}
    {% block table_body %}
        {# Rows will have alternating background #}
        <tr><td>Item 1</td><td>Value 1</td></tr>
        <tr><td>Item 2</td><td>Value 2</td></tr>
    {% endblock %}
{% endembed %}
```

### Small/Compact Table

```twig
{% embed '_components/_table.html.twig' with {'variant': 'small'} %}
    {# Reduced padding for dense data display #}
    ...
{% endembed %}
```

### Bordered Table

```twig
{% embed '_components/_table.html.twig' with {'variant': 'bordered'} %}
    {# Borders on all sides of cells #}
    ...
{% endembed %}
```

### Sticky Header Table

```twig
{% embed '_components/_table.html.twig' with {
    'stickyHeader': true,
    'theadClass': 'table-light'
} %}
    {# Header stays visible while scrolling #}
    ...
{% endembed %}
```

### Table in Card (No Margin)

```twig
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">{{ 'title'|trans }}</h5>
    </div>
    {% embed '_components/_table.html.twig' with {'noMargin': true} %}
        {# No margin-bottom, flush with card #}
        ...
    {% endembed %}
</div>
```

---

## Accessibility Guidelines

### Required Header Attributes

All table headers MUST use `scope="col"`:

```twig
{# ✅ CORRECT #}
<th scope="col">{{ 'field.name'|trans }}</th>

{# ❌ INCORRECT - Missing scope #}
<th>{{ 'field.name'|trans }}</th>
```

### Row Headers

For tables with row headers, use `scope="row"`:

```twig
<tr>
    <th scope="row">{{ category.name }}</th>
    <td>{{ category.count }}</td>
    <td>{{ category.percentage }}%</td>
</tr>
```

### Complex Tables

For complex tables with multiple header levels, use `id` and `headers` attributes:

```twig
<thead>
    <tr>
        <th scope="col" id="name">Name</th>
        <th scope="colgroup" colspan="2" id="stats">Statistics</th>
    </tr>
    <tr>
        <th scope="col" headers="stats" id="count">Count</th>
        <th scope="col" headers="stats" id="percent">Percentage</th>
    </tr>
</thead>
<tbody>
    <tr>
        <td headers="name">Item 1</td>
        <td headers="stats count">42</td>
        <td headers="stats percent">65%</td>
    </tr>
</tbody>
```

---

## Responsive Tables

### Automatic Responsiveness

The `_table.html.twig` component automatically wraps tables in `.table-responsive` div:

```html
<!-- Automatically generated by component -->
<div class="table-responsive">
    <table class="table table-hover">
        ...
    </table>
</div>
```

### Disable Responsive Wrapper

For tables that don't need horizontal scrolling (e.g., very simple 2-column tables):

```twig
{% embed '_components/_table.html.twig' with {'responsive': false} %}
    ...
{% endembed %}
```

### Mobile Optimization

For complex tables on mobile, consider:

1. **Reduce columns**: Hide non-essential columns on mobile
2. **Stacked layout**: Use custom CSS to stack cells
3. **Card view**: Alternative card-based layout for mobile

```css
/* Hide column on mobile */
@media (max-width: 768px) {
    .table th.d-none-mobile,
    .table td.d-none-mobile {
        display: none;
    }
}
```

```twig
<th scope="col" class="d-none-mobile">{{ 'field.description'|trans }}</th>
<td class="d-none-mobile">{{ item.description }}</td>
```

---

## Common Table Patterns

### Action Column

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col">{{ 'field.name'|trans }}</th>
            <th scope="col" class="text-end" style="width: 150px;">{{ 'field.actions'|trans }}</th>
        </tr>
    {% endblock %}

    {% block table_body %}
        {% for item in items %}
        <tr>
            <td>{{ item.name }}</td>
            <td class="text-end">
                <div class="btn-group" role="group" aria-label="{{ 'aria.item_actions'|trans }}">
                    <a href="{{ path('app_item_show', {id: item.id}) }}"
                       class="btn btn-sm btn-info"
                       title="{{ 'action.view'|trans }}">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </a>
                    <a href="{{ path('app_item_edit', {id: item.id}) }}"
                       class="btn btn-sm btn-primary"
                       title="{{ 'action.edit'|trans }}">
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                    </a>
                </div>
            </td>
        </tr>
        {% endfor %}
    {% endblock %}
{% endembed %}
```

### Status Badge Column

```twig
<td>
    {% if item.status == 'active' %}
        <span class="badge bg-success">{{ 'status.active'|trans }}</span>
    {% elseif item.status == 'pending' %}
        <span class="badge bg-warning">{{ 'status.pending'|trans }}</span>
    {% else %}
        <span class="badge bg-secondary">{{ 'status.inactive'|trans }}</span>
    {% endif %}
</td>
```

### Empty State

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col">{{ 'field.name'|trans }}</th>
            <th scope="col">{{ 'field.status'|trans }}</th>
        </tr>
    {% endblock %}

    {% block table_body %}
        {% if items|length > 0 %}
            {% for item in items %}
            <tr>
                <td>{{ item.name }}</td>
                <td>{{ item.status }}</td>
            </tr>
            {% endfor %}
        {% else %}
            <tr>
                <td colspan="2" class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
                    {{ 'table.no_data'|trans }}
                </td>
            </tr>
        {% endif %}
    {% endblock %}
{% endembed %}
```

### Sortable Headers

```twig
{% block table_head %}
    <tr>
        <th scope="col">
            <a href="{{ path('app_items', {sort: 'name', direction: sortDirection == 'asc' ? 'desc' : 'asc'}) }}">
                {{ 'field.name'|trans }}
                {% if sortField == 'name' %}
                    <i class="bi bi-arrow-{{ sortDirection == 'asc' ? 'up' : 'down' }}" aria-hidden="true"></i>
                {% endif %}
            </a>
        </th>
        <th scope="col">{{ 'field.status'|trans }}</th>
    </tr>
{% endblock %}
```

### Expandable Rows

```twig
{% for item in items %}
<tr data-bs-toggle="collapse" data-bs-target="#details{{ item.id }}" style="cursor: pointer;">
    <td>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        {{ item.name }}
    </td>
    <td>{{ item.status }}</td>
</tr>
<tr class="collapse" id="details{{ item.id }}">
    <td colspan="2" class="bg-light">
        <div class="p-3">
            <h6>{{ 'table.details'|trans }}</h6>
            <p>{{ item.description }}</p>
        </div>
    </td>
</tr>
{% endfor %}
```

### Selectable Rows (Bulk Actions)

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col" style="width: 50px;">
                <input type="checkbox"
                       class="form-check-input"
                       id="selectAll"
                       aria-label="{{ 'aria.select_all'|trans }}">
            </th>
            <th scope="col">{{ 'field.name'|trans }}</th>
            <th scope="col">{{ 'field.status'|trans }}</th>
        </tr>
    {% endblock %}

    {% block table_body %}
        {% for item in items %}
        <tr>
            <td>
                <input type="checkbox"
                       class="form-check-input row-checkbox"
                       value="{{ item.id }}"
                       aria-label="{{ 'aria.select_item'|trans({'%name%': item.name}) }}">
            </td>
            <td>{{ item.name }}</td>
            <td>{{ item.status }}</td>
        </tr>
        {% endfor %}
    {% endblock %}
{% endembed %}
```

---

## Dark Mode Support

All table styles support dark mode via CSS variables:

```css
/* Light mode */
.table {
    --bs-table-bg: transparent;
    --bs-table-striped-bg: rgba(0, 0, 0, 0.05);
    --bs-table-hover-bg: rgba(0, 0, 0, 0.075);
}

/* Dark mode */
[data-theme="dark"] .table {
    --bs-table-bg: transparent;
    --bs-table-striped-bg: rgba(255, 255, 255, 0.05);
    --bs-table-hover-bg: rgba(255, 255, 255, 0.075);
    --bs-table-color: var(--text-primary);
    --bs-table-border-color: var(--border-color);
}
```

---

## Testing Checklist

### Accessibility

- [ ] All headers have `scope="col"` or `scope="row"`
- [ ] Complex tables use `id` and `headers` attributes
- [ ] Table has descriptive caption or preceding heading
- [ ] Sortable links have clear aria-labels
- [ ] Checkbox inputs have aria-labels
- [ ] Empty states are clear and accessible

### Responsiveness

- [ ] Table wrapped in `.table-responsive` (or uses component)
- [ ] Horizontal scroll works on mobile
- [ ] Critical columns visible on mobile
- [ ] Text doesn't overflow cells
- [ ] Action buttons accessible on mobile

### Visual

- [ ] Hover states work correctly
- [ ] Striping visible and consistent
- [ ] Borders render correctly
- [ ] Dark mode displays properly
- [ ] Print layout acceptable

---

## Best Practices

### DO

- ✅ Use `{% embed '_components/_table.html.twig' %}` for all HTML tables
- ✅ Include `scope` attribute on all header cells
- ✅ Use semantic column headers with translations
- ✅ Provide empty states for tables with no data
- ✅ Use appropriate table variants (small for dense data)
- ✅ Add aria-labels to action buttons and checkboxes
- ✅ Test on mobile devices

### DON'T

- ❌ Create raw `<table>` tags for HTML views (use component)
- ❌ Forget `scope` attributes on headers
- ❌ Use tables for layout (use CSS grid/flexbox)
- ❌ Hardcode text instead of using translations
- ❌ Make action columns too wide
- ❌ Use inline styles
- ❌ Forget empty state handling

---

## When NOT to Use Tables

Tables are for **tabular data only**. Don't use tables for:

- Page layout (use CSS Grid or Flexbox)
- Forms (use form components)
- Navigation (use nav elements)
- Card-like displays (use card components)

**Alternative patterns:**
- **Lists**: Use `<ul>` or `<ol>` for simple lists
- **Cards**: Use card grid for item collections
- **Definition lists**: Use `<dl>` for key-value pairs

---

## Quick Reference

### Minimal Table

```twig
{% embed '_components/_table.html.twig' %}
    {% block table_head %}
        <tr>
            <th scope="col">Column 1</th>
            <th scope="col">Column 2</th>
        </tr>
    {% endblock %}
    {% block table_body %}
        <tr>
            <td>Data 1</td>
            <td>Data 2</td>
        </tr>
    {% endblock %}
{% endembed %}
```

### Common Configurations

| Use Case | Configuration |
|----------|---------------|
| Standard data table | `{% embed '_components/_table.html.twig' %}` |
| Dense/compact table | `with {'variant': 'small'}` |
| Zebra striping | `with {'variant': 'striped'}` |
| Scrollable with fixed header | `with {'stickyHeader': true, 'theadClass': 'table-light'}` |
| Table in card | `with {'noMargin': true}` |
| Non-responsive table | `with {'responsive': false}` |

---

**Last Updated:** 2025-11-24
**Maintained by:** Little ISMS Helper Team
**See Also:** [ACCESSIBILITY.md](ACCESSIBILITY.md), [CARD_PATTERNS.md](CARD_PATTERNS.md)
