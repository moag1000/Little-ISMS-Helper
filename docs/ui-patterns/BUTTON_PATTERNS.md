# Button Styling Patterns - Little ISMS Helper

**Version:** 1.0
**Date:** 2025-11-23
**Purpose:** Standardized button usage across all templates

---

## Button Variants

### Primary Actions
Use `btn btn-primary` for the most important action on a page.

```twig
<button type="submit" class="btn btn-primary">
    <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
</button>
```

**When to use:**
- Form submit buttons (Save, Create, Update)
- Primary page actions (Add New, Create)
- Confirmation dialogs (Yes, Confirm)

---

### Secondary Actions
Use `btn btn-secondary` for supporting actions.

```twig
<a href="{{ path('app_index') }}" class="btn btn-secondary">
    <i class="bi bi-arrow-left"></i> {{ 'action.back'|trans }}
</a>
```

**When to use:**
- Cancel buttons
- Back to list links
- Alternative actions

---

### Outlined Buttons
Use `btn btn-outline-{variant}` for less prominent actions.

```twig
<a href="{{ path('app_view') }}" class="btn btn-outline-primary">
    <i class="bi bi-eye"></i> {{ 'action.view'|trans }}
</a>
```

**When to use:**
- View actions in tables
- Edit actions in tables
- Secondary navigation

---

### Destructive Actions
Use `btn btn-danger` for delete/remove actions.

```twig
<button type="submit" class="btn btn-danger">
    <i class="bi bi-trash"></i> {{ 'action.delete'|trans }}
</button>
```

**When to use:**
- Delete buttons
- Remove actions
- Critical operations

---

## Button Groups

### Table Action Buttons
Use `btn-group btn-group-sm` for action buttons in tables.

```twig
<div class="btn-group btn-group-sm">
    <a href="{{ path('app_show', {id: item.id}) }}" class="btn btn-outline-primary" title="{{ 'action.view'|trans }}">
        <i class="bi bi-eye"></i>
    </a>
    <a href="{{ path('app_edit', {id: item.id}) }}" class="btn btn-outline-secondary" title="{{ 'action.edit'|trans }}">
        <i class="bi bi-pencil"></i>
    </a>
    <button type="submit" class="btn btn-outline-danger" title="{{ 'action.delete'|trans }}">
        <i class="bi bi-trash"></i>
    </button>
</div>
```

**Best practices:**
- Always use `btn-group-sm` for table actions
- Use icon-only buttons with `title` attribute for accessibility
- Maintain consistent order: View → Edit → Delete

---

### Form Action Buttons
Use `d-flex gap-1` for form submit/cancel button groups.

```twig
<div class="d-flex gap-1">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
    </button>
    <a href="{{ path('app_index') }}" class="btn btn-secondary">
        <i class="bi bi-x-circle"></i> {{ 'action.cancel'|trans }}
    </a>
</div>
```

---

## Icon Usage

### Always Include Icons
Every button should have a Bootstrap Icon for better visual recognition.

**Common icons:**
- `bi-plus-circle` - Create/Add
- `bi-check-circle` - Save/Confirm
- `bi-x-circle` - Cancel/Close
- `bi-pencil` - Edit
- `bi-eye` - View
- `bi-trash` - Delete
- `bi-arrow-left` - Back
- `bi-funnel` - Filter
- `bi-download` - Download/Export

### Icon-Only Buttons
For icon-only buttons, always include a `title` attribute:

```twig
<button class="btn btn-sm btn-primary" title="{{ 'action.edit'|trans }}">
    <i class="bi bi-pencil"></i>
</button>
```

---

## Size Variants

### Small Buttons
Use `btn-sm` for compact layouts (tables, inline actions).

```twig
<button class="btn btn-sm btn-primary">
    <i class="bi bi-plus"></i> {{ 'action.add'|trans }}
</button>
```

### Large Buttons
Use `btn-lg` for prominent call-to-actions.

```twig
<button class="btn btn-lg btn-primary">
    <i class="bi bi-rocket"></i> {{ 'action.get_started'|trans }}
</button>
```

---

## Accessibility

### Required Attributes

1. **Type attribute** (for buttons):
   ```twig
   <button type="submit" class="btn btn-primary">...</button>
   <button type="button" class="btn btn-secondary">...</button>
   ```

2. **Title attribute** (for icon-only buttons):
   ```twig
   <button class="btn btn-sm btn-primary" title="{{ 'action.edit'|trans }}">
       <i class="bi bi-pencil"></i>
   </button>
   ```

3. **ARIA labels** (when needed):
   ```twig
   <button class="btn btn-primary" aria-label="{{ 'action.close_dialog'|trans }}">
       <i class="bi bi-x"></i>
   </button>
   ```

---

## Button States

### Disabled Buttons
```twig
<button class="btn btn-primary" disabled>
    <i class="bi bi-lock"></i> {{ 'action.locked'|trans }}
</button>
```

### Loading State
```twig
<button class="btn btn-primary" data-loading-text="{{ 'action.loading'|trans }}">
    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
    <span class="button-text">{{ 'action.save'|trans }}</span>
</button>
```

---

## Anti-Patterns (DO NOT USE)

❌ **Button without variant:**
```twig
<button class="btn">...</button>
```

✅ **Correct:**
```twig
<button class="btn btn-primary">...</button>
```

---

❌ **Button without icon:**
```twig
<button class="btn btn-primary">Save</button>
```

✅ **Correct:**
```twig
<button class="btn btn-primary">
    <i class="bi bi-check-circle"></i> {{ 'action.save'|trans }}
</button>
```

---

❌ **Hardcoded text:**
```twig
<button class="btn btn-primary">Save</button>
```

✅ **Correct:**
```twig
<button class="btn btn-primary">{{ 'action.save'|trans }}</button>
```

---

## Quick Reference Table

| Action | Variant | Icon | Translation Key |
|--------|---------|------|-----------------|
| Create/Add New | `btn-primary` | `bi-plus-circle` | `action.add_new` |
| Save/Submit | `btn-primary` | `bi-check-circle` | `action.save` |
| Edit | `btn-outline-secondary` | `bi-pencil` | `action.edit` |
| View/Show | `btn-outline-primary` | `bi-eye` | `action.view` |
| Delete/Remove | `btn-danger` | `bi-trash` | `action.delete` |
| Cancel | `btn-secondary` | `bi-x-circle` | `action.cancel` |
| Back to List | `btn-secondary` | `bi-arrow-left` | `action.back_to_list` |
| Filter | `btn-primary` | `bi-funnel` | `action.filter` |
| Reset Filter | `btn-secondary` | `bi-x-circle` | `action.reset` |
| Export/Download | `btn-success` | `bi-download` | `action.export` |

---

## Bootstrap 5 Classes Used

- `btn` - Base button class (required)
- `btn-primary` - Primary action (blue)
- `btn-secondary` - Secondary action (gray)
- `btn-success` - Success action (green)
- `btn-danger` - Destructive action (red)
- `btn-warning` - Warning action (yellow)
- `btn-info` - Info action (cyan)
- `btn-outline-{variant}` - Outlined version
- `btn-sm` - Small size
- `btn-lg` - Large size
- `btn-group` - Group multiple buttons
- `btn-group-sm` - Small button group

---

**Last Updated:** 2025-11-23
**Maintained by:** Little ISMS Helper Team
