# Bulk Delete Confirmation Dialog

Ein wiederverwendbarer, accessible Confirmation Dialog für Bulk-Delete-Operationen.

## Features

- **WCAG 2.1 AA konform** - Screen Reader freundlich mit korrekten ARIA-Attributen
- **Keyboard Navigation** - Vollständig über Tastatur bedienbar
- **Dependency Check** - Zeigt betroffene Verknüpfungen an
- **Accessible Design** - Klare Fokus-Indikatoren und Skip-Links
- **Promise-basiert** - Einfache Integration mit async/await
- **Mehrsprachig** - Unterstützt Deutsch und Englisch

## Verwendung

### 1. Template Setup

Der Dialog ist global verfügbar und bereits in `base.html.twig` eingebunden. Keine zusätzliche Konfiguration nötig.

### 2. Controller Setup

In deinem Twig-Template, füge die bulk-actions Controller Attribute hinzu:

```twig
<div data-controller="bulk-actions"
     data-bulk-actions-endpoint-value="/{{ locale }}/asset"
     data-bulk-actions-entity-label="Assets">

    <table class="table">
        <thead>
            <tr>
                <th>
                    <input type="checkbox"
                           data-action="bulk-actions#selectAll"
                           data-bulk-actions-target="selectAllCheckbox"
                           aria-label="Alle auswählen">
                </th>
                <th>Name</th>
                <th>Typ</th>
            </tr>
        </thead>
        <tbody>
            {% for asset in assets %}
            <tr>
                <td>
                    <input type="checkbox"
                           data-bulk-actions-target="item"
                           data-action="bulk-actions#selectItem"
                           value="{{ asset.id }}"
                           aria-label="Asset auswählen">
                </td>
                <td>{{ asset.name }}</td>
                <td>{{ asset.type }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>

    {% include '_components/_bulk_action_bar.html.twig' with {
        actions: ['delete', 'export']
    } %}
</div>
```

### 3. Backend Endpoints

Implementiere zwei Endpunkte in deinem Controller:

#### 3.1 Dependency Check Endpoint

```php
// @csrf-exempt: read-only dependency check for the bulk-delete confirmation modal
#[Route('/asset/bulk-delete-check', name: 'app_asset_bulk_delete_check', methods: ['POST'])]
#[IsGranted('ROLE_MANAGER')]
public function bulkDeleteCheck(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true) ?? [];
    $ids = array_filter((array) ($data['ids'] ?? []), 'is_int');
    $assets = $this->assetRepository->findBy(['id' => $ids, 'tenant' => $this->security->getUser()?->getTenant()]);

    return $this->checkBulkDependencies($assets, 'getName', [
        fn (Asset $asset): ?array => ($c = $asset->getRisks()->count()) > 0
            ? ['message' => $this->translator->trans('bulk_delete.dependency.risks', ['%count%' => $c], 'bulk_delete'), 'icon' => 'shield-exclamation']
            : null,
        // … incidents, protecting controls
    ]);
}
```

Only records of the current organisation are checked; messages come from the `bulk_delete` domain.

#### 3.2 Bulk Delete Endpoint

Every bulk delete goes through `BulkActionTrait::handleBulkDelete()` and `BulkDeleteService`: shared
`bulk_action` CSRF token, id sanitising, tenant check, voter, optional guard, one transaction and one
`AuditLogger::logBulk()` batch.

```php
#[Route('/asset/bulk-delete', name: 'app_asset_bulk_delete', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
public function bulkDelete(Request $request, BulkDeleteService $bulkDeleteService): JsonResponse
{
    return $this->handleBulkDelete(
        $request,
        $bulkDeleteService,
        'Asset',
        $this->security->getUser(),
        fn (int $id): ?object => $this->assetRepository->find($id),
        voterAttribute: 'delete',
    );
}
```

`BulkDeleteService` applies the deletion policy (`App\Lifecycle\DeletionPolicy::refusal()`): an asset is
deleted only once it is `disposed`, or within 24 hours of its creation. Refused rows are reported per id, the rest is deleted.

## Accessibility Features

### ARIA Attributes

- `role="dialog"` - Definiert das Modal als Dialog
- `aria-modal="true"` - Kennzeichnet als modales Element
- `aria-labelledby` - Verknüpft mit dem Titel
- `aria-describedby` - Verknüpft mit der Beschreibung
- `aria-label` - Labels für Buttons und Checkboxen

### Keyboard Navigation

- **ESC** - Schließt den Dialog
- **Tab** - Navigiert zwischen Buttons
- **Enter** - Bestätigt die Aktion
- **Space** - Aktiviert Checkboxen

### Screen Reader Support

- Semantische HTML-Struktur
- Beschreibende Labels
- Status-Updates werden angekündigt
- Fokus wird korrekt verwaltet

## Styling

Der Dialog ist ein Aurora-`fa-modal` (`templates/_components/_bulk_delete_confirmation.html.twig`, Modus
`confirm`, Ton `danger`; unter 576 px als Bottom-Sheet). Anpassungen gehören in `fa-modal.css` bzw. die
Aurora-Tokens, nicht in eigene Farbwerte.

## Beispiel mit mehreren Entities

```javascript
// Manual usage (falls nötig)
const confirmModal = document.getElementById('bulkDeleteModal');
const confirmController = this.application.getControllerForElementAndIdentifier(
    confirmModal,
    'bulk-delete-confirmation'
);

const confirmed = await confirmController.show({
    count: 10,
    entityLabel: 'Assets',
    endpoint: '/de/asset/bulk-delete-check',
    ids: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
    message: 'Möchten Sie wirklich 10 Assets löschen?' // Optional custom message
});

if (confirmed) {
    // Proceed with deletion
}
```

## Testing

### Manual Testing Checklist

- [ ] Dialog öffnet sich beim Klick auf "Löschen"
- [ ] Anzahl der ausgewählten Items wird korrekt angezeigt
- [ ] Dependencies werden geladen und angezeigt
- [ ] ESC schließt den Dialog
- [ ] "Abbrechen" schließt den Dialog ohne Aktion
- [ ] "Löschen" führt die Aktion aus
- [ ] Fokus kehrt nach Schließen zurück
- [ ] Screen Reader liest alle Informationen vor
- [ ] Keyboard-Navigation funktioniert
- [ ] Loading-State wird angezeigt während Dependency-Check

### Automated Testing

```php
public function testBulkDeleteCheck(): void
{
    $client = static::createClient();
    $client->request('POST', '/de/asset/bulk-delete-check', [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['ids' => [1, 2, 3]]));

    $this->assertResponseIsSuccessful();
    $response = json_decode($client->getResponse()->getContent(), true);
    $this->assertArrayHasKey('dependencies', $response);
}
```

## Troubleshooting

### Dialog öffnet sich nicht

- Prüfe, dass `bulk_delete_confirmation_controller.js` geladen wird
- Überprüfe die Browser-Konsole auf JavaScript-Fehler
- Prüfe, dass `fa-modal` auf dem Dialog sitzt (`data-controller="fa-modal bulk-delete-confirmation"`)

### Dependencies werden nicht angezeigt

- Prüfe, dass der Endpoint korrekt ist
- Überprüfe die Network-Tab in den DevTools
- Stelle sicher, dass der Endpoint ein JSON-Response mit `dependencies` Array zurückgibt

### Fokus geht verloren

- Stelle sicher, dass `previousFocus` korrekt gespeichert wird
- Prüfe, dass das Element noch im DOM existiert

## Weitere Ressourcen

- [WCAG 2.1 Modal Dialog Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)
- [Stimulus.js Documentation](https://stimulus.hotwired.dev/)
