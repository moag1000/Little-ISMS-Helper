# Bulk Delete Confirmation Dialog

Ein wiederverwendbarer, accessible Confirmation Dialog für Bulk-Delete-Operationen.

## Features

- ✅ **WCAG 2.1 AA konform** - Screen Reader freundlich mit korrekten ARIA-Attributen
- ✅ **Keyboard Navigation** - Vollständig über Tastatur bedienbar
- ✅ **Dependency Check** - Zeigt betroffene Verknüpfungen an
- ✅ **Accessible Design** - Klare Fokus-Indikatoren und Skip-Links
- ✅ **Promise-basiert** - Einfache Integration mit async/await
- ✅ **Mehrsprachig** - Unterstützt Deutsch und Englisch

## Verwendung

### 1. Template Setup

Der Dialog ist global verfügbar und bereits in `base.html.twig` eingebunden. Keine zusätzliche Konfiguration nötig!

### 2. Controller Setup

In deinem Twig-Template, füge die bulk-actions Controller Attribute hinzu:

```twig
<div data-controller="bulk-actions"
     data-bulk-actions-endpoint-value="/{{ locale }}/assets"
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
#[Route('/{_locale}/assets/bulk-delete-check', name: 'asset_bulk_delete_check', methods: ['POST'])]
public function bulkDeleteCheck(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    $dependencies = [];

    foreach ($ids as $id) {
        $asset = $this->assetRepository->find($id);

        if (!$asset) {
            continue;
        }

        // Check Controls
        $controlCount = count($asset->getControls());
        if ($controlCount > 0) {
            $dependencies[] = [
                'icon' => 'shield-check',
                'message' => sprintf(
                    '%s ist mit %d Control(s) verknüpft',
                    $asset->getName(),
                    $controlCount
                )
            ];
        }

        // Check Risks
        $riskCount = count($asset->getRisks());
        if ($riskCount > 0) {
            $dependencies[] = [
                'icon' => 'exclamation-triangle',
                'message' => sprintf(
                    '%s ist mit %d Risiko(en) verknüpft',
                    $asset->getName(),
                    $riskCount
                )
            ];
        }
    }

    return $this->json([
        'dependencies' => $dependencies
    ]);
}
```

#### 3.2 Bulk Delete Endpoint

```php
#[Route('/{_locale}/assets/bulk-delete', name: 'asset_bulk_delete', methods: ['POST'])]
public function bulkDelete(Request $request, EntityManagerInterface $em): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    $deleted = 0;

    foreach ($ids as $id) {
        $asset = $this->assetRepository->find($id);

        if ($asset && $this->security->isGranted('DELETE', $asset)) {
            $em->remove($asset);
            $deleted++;
        }
    }

    $em->flush();

    return $this->json([
        'success' => true,
        'deleted' => $deleted
    ]);
}
```

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

Der Dialog verwendet Bootstrap 5 Klassen und kann über CSS angepasst werden:

```css
/* Custom danger header */
#bulkDeleteModal .modal-header {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
}

/* Custom confirmation button */
#bulkDeleteModal .btn-danger {
    box-shadow: 0 4px 6px rgba(220, 53, 69, 0.3);
}
```

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
    endpoint: '/de/assets/bulk-delete-check',
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
    $client->request('POST', '/de/assets/bulk-delete-check', [], [], [
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
- Stelle sicher, dass Bootstrap 5 JavaScript geladen ist

### Dependencies werden nicht angezeigt

- Prüfe, dass der Endpoint korrekt ist
- Überprüfe die Network-Tab in den DevTools
- Stelle sicher, dass der Endpoint ein JSON-Response mit `dependencies` Array zurückgibt

### Fokus geht verloren

- Stelle sicher, dass `previousFocus` korrekt gespeichert wird
- Prüfe, dass das Element noch im DOM existiert

## Weitere Ressourcen

- [WCAG 2.1 Modal Dialog Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)
- [Bootstrap 5 Modal Documentation](https://getbootstrap.com/docs/5.3/components/modal/)
- [Stimulus.js Documentation](https://stimulus.hotwired.dev/)
