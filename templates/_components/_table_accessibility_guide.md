# Table Accessibility Guide (WCAG 2.1 AA)

## Warum Table Scope Attributes?

**WCAG 2.1 Success Criterion 1.3.1 (Level A):** Information, structure, and relationships conveyed through presentation can be programmatically determined.

Table scope attributes helfen Screen Readern, die Beziehung zwischen Header-Zellen und Daten-Zellen zu verstehen.

## Scope Attribute Regeln

### 1. Column Headers (Spalten-Überschriften)

Verwende `scope="col"` für Spalten-Header:

```html
<thead>
    <tr>
        <th scope="col">Name</th>
        <th scope="col">Email</th>
        <th scope="col">Role</th>
    </tr>
</thead>
```

**Screen Reader sagt:** "Name, column header" → "John Doe, row 1"

### 2. Row Headers (Zeilen-Überschriften)

Verwende `scope="row"` für Zeilen-Header:

```html
<tbody>
    <tr>
        <th scope="row">Q1 2024</th>
        <td>$50,000</td>
        <td>25 clients</td>
    </tr>
</tbody>
```

**Screen Reader sagt:** "Q1 2024, row header" → "$50,000"

### 3. Komplexe Tabellen

Für Tabellen mit mehreren Header-Ebenen verwende `headers` und `id`:

```html
<table>
    <thead>
        <tr>
            <th id="name" scope="col">Name</th>
            <th id="q1" colspan="2" scope="colgroup">Q1</th>
            <th id="q2" colspan="2" scope="colgroup">Q2</th>
        </tr>
        <tr>
            <th scope="col"><!-- empty --></th>
            <th id="q1-sales" scope="col">Sales</th>
            <th id="q1-profit" scope="col">Profit</th>
            <th id="q2-sales" scope="col">Sales</th>
            <th id="q2-profit" scope="col">Profit</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <th scope="row">Product A</th>
            <td headers="q1 q1-sales">$10k</td>
            <td headers="q1 q1-profit">$2k</td>
            <td headers="q2 q2-sales">$12k</td>
            <td headers="q2 q2-profit">$3k</td>
        </tr>
    </tbody>
</table>
```

## Standard Table Patterns in Little ISMS Helper

### Pattern 1: Index Tables (mit Bulk Actions)

```twig
<table class="table table-hover">
    <thead>
        <tr>
            <th scope="col" class="w-40px">
                <input type="checkbox"
                       class="form-check-input"
                       data-action="bulk-actions#selectAll"
                       data-bulk-actions-target="selectAllCheckbox"
                       aria-label="Alle auswählen">
            </th>
            <th scope="col">Name</th>
            <th scope="col">Type</th>
            <th scope="col" class="text-center">Status</th>
            <th scope="col" class="text-center">{{ 'action.label'|trans }}</th>
        </tr>
    </thead>
    <tbody>
        {% for item in items %}
        <tr>
            <td>
                <input type="checkbox"
                       class="form-check-input"
                       data-bulk-actions-target="item"
                       value="{{ item.id }}"
                       aria-label="{{ 'select'|trans }} {{ item.name }}">
            </td>
            <td>{{ item.name }}</td>
            <td>{{ item.type }}</td>
            <td class="text-center">
                <span class="badge bg-{{ item.status == 'active' ? 'success' : 'secondary' }}">
                    {{ item.status|trans }}
                </span>
            </td>
            <td class="text-center">
                <a href="{{ path('app_item_edit', {id: item.id}) }}"
                   class="btn btn-sm btn-primary"
                   aria-label="{{ 'edit'|trans }} {{ item.name }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
            </td>
        </tr>
        {% endfor %}
    </tbody>
</table>
```

### Pattern 2: Detail/Show Tables (Key-Value)

```twig
<table class="table">
    <tbody>
        <tr>
            <th scope="row" class="w-200px">{{ 'field.name'|trans }}</th>
            <td>{{ entity.name }}</td>
        </tr>
        <tr>
            <th scope="row">{{ 'field.email'|trans }}</th>
            <td>{{ entity.email }}</td>
        </tr>
        <tr>
            <th scope="row">{{ 'field.created_at'|trans }}</th>
            <td>{{ entity.createdAt|date('Y-m-d H:i') }}</td>
        </tr>
    </tbody>
</table>
```

### Pattern 3: Statistics/Report Tables

```twig
<table class="table table-bordered">
    <caption class="visually-hidden">{{ 'report.compliance_statistics'|trans }}</caption>
    <thead>
        <tr>
            <th scope="col">{{ 'framework.name'|trans }}</th>
            <th scope="col" class="text-center">{{ 'compliance.total_controls'|trans }}</th>
            <th scope="col" class="text-center">{{ 'compliance.implemented'|trans }}</th>
            <th scope="col" class="text-center">{{ 'compliance.percentage'|trans }}</th>
        </tr>
    </thead>
    <tbody>
        {% for framework in frameworks %}
        <tr>
            <th scope="row">{{ framework.name }}</th>
            <td class="text-center">{{ framework.totalControls }}</td>
            <td class="text-center">{{ framework.implementedControls }}</td>
            <td class="text-center">
                <strong>{{ (framework.implementedControls / framework.totalControls * 100)|round }}%</strong>
            </td>
        </tr>
        {% endfor %}
    </tbody>
    <tfoot>
        <tr>
            <th scope="row">{{ 'total'|trans }}</th>
            <td class="text-center"><strong>{{ totalControls }}</strong></td>
            <td class="text-center"><strong>{{ implementedControls }}</strong></td>
            <td class="text-center">
                <strong>{{ (implementedControls / totalControls * 100)|round }}%</strong>
            </td>
        </tr>
    </tfoot>
</table>
```

## Accessibility Checklist für Tabellen

- [ ] **Alle `<th>` Elemente haben `scope` Attribut**
  - `scope="col"` für Spalten-Header
  - `scope="row"` für Zeilen-Header
  - `scope="colgroup"` für gespannte Spalten-Gruppen
  - `scope="rowgroup"` für gespannte Zeilen-Gruppen

- [ ] **Caption oder visually-hidden Beschreibung**
  ```html
  <caption>Liste aller Benutzer</caption>
  <!-- oder -->
  <caption class="visually-hidden">Benutzer-Übersicht mit Name, Email und Rolle</caption>
  ```

- [ ] **Checkbox-Inputs haben aria-label**
  ```html
  <input type="checkbox" aria-label="Asset XY auswählen">
  ```

- [ ] **Action-Buttons haben aria-label**
  ```html
  <a href="..." aria-label="Asset XY bearbeiten">
      <i class="bi bi-pencil" aria-hidden="true"></i>
  </a>
  ```

- [ ] **Sortierbare Spalten zeigen Sortier-Zustand**
  ```html
  <th scope="col" aria-sort="ascending">
      Name
      <i class="bi bi-arrow-up" aria-hidden="true"></i>
  </th>
  ```

- [ ] **Leere Zellen verwenden `&nbsp;` oder `—`**
  ```html
  <td>—</td> <!-- statt <td></td> -->
  ```

- [ ] **Icons haben aria-hidden="true"**
  ```html
  <i class="bi bi-check" aria-hidden="true"></i>
  ```

## Häufige Fehler vermeiden

### ❌ Falsch
```html
<thead>
    <tr>
        <th>Name</th> <!-- Missing scope -->
        <th>Email</th>
    </tr>
</thead>
```

### ✅ Richtig
```html
<thead>
    <tr>
        <th scope="col">Name</th>
        <th scope="col">Email</th>
    </tr>
</thead>
```

### ❌ Falsch
```html
<td><input type="checkbox"></td> <!-- Missing label -->
```

### ✅ Richtig
```html
<td>
    <input type="checkbox"
           aria-label="Benutzer John Doe auswählen">
</td>
```

## Testing

### Automated Testing

```bash
# Check for missing scope attributes
grep -r "<th" templates/ | grep -v "scope=" | wc -l
# Should be 0

# Check for captions
grep -r "<table" templates/ | grep -v "caption" | wc -l
# Should be as low as possible
```

### Manual Testing

1. **Screen Reader Test** (NVDA/JAWS/VoiceOver)
   - Navigiere zu einer Tabelle
   - Bewege dich mit Pfeiltasten durch Zellen
   - Screen Reader sollte Header bei jeder Zelle ansagen

2. **Keyboard Navigation**
   - Tab durch interaktive Elemente (Checkboxen, Links)
   - Alle Elemente sollten erreichbar sein

3. **Lighthouse Audit**
   - Chrome DevTools → Lighthouse → Accessibility
   - Score sollte > 95 sein

## Weitere Ressourcen

- [WCAG 2.1 Tables](https://www.w3.org/WAI/tutorials/tables/)
- [MDN: scope attribute](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/th#attr-scope)
- [WebAIM: Creating Accessible Tables](https://webaim.org/techniques/tables/data)
