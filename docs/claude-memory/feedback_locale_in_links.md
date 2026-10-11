---
name: locale-in-links
description: "Locale-Prefix wird global in config/routes.yaml gesetzt. Controller-Routes NIE eigenes /{_locale}/ — sonst Doppel-Prefix-Compile-Error."
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 00707aab-5219-4853-97ae-8ba832ca1266
---

`config/routes.yaml` setzt `prefix: /{_locale}` global für alle Controller in `src/Controller/` (mit Default `_locale: de`).

**Why:** Symfony erlaubt Variable nur einmal pro Route-Pattern. Wenn Controller-Attribute zusätzlich `/{_locale}/...` deklarieren → `Route pattern "/{_locale}/{_locale}/..." cannot reference variable name "_locale" more than once` beim `cache:clear`. App startet nicht.

**How to apply:**

Controller-Routes NIE mit `/{_locale}/` prefixen:

```php
// WRONG — Doppel-Prefix, Compile-Error
#[Route('/{_locale}/authority/notification', ..., requirements: ['_locale' => 'de|en'])]

// RIGHT — globaler Prefix übernimmt es
#[Route('/authority/notification', name: 'app_authority_notification_')]
```

`path()`/`url()` Calls in Twig brauchen `_locale` NICHT zwingend — Route-Defaults setzen `_locale: de`. Calls funktionieren ohne Param.

```twig
{# OK — defaults greifen #}
<a href="{{ path('app_user_index') }}">Nutzer prüfen</a>

{# OK — wenn current locale erhalten werden soll #}
<a href="{{ path('app_user_index', { _locale: app.request.locale }) }}">Nutzer prüfen</a>
```

App ist effektiv mono-locale (de aktiv; en konfiguriert aber kaum genutzt). Doppelte `_locale`-Param-Boilerplate überall = overkill.

**Common Bug-Pattern:**
- `cache:clear` failt mit `Route pattern ... cannot reference variable name "_locale" more than once` → Doppel-Prefix in Controller
- Self-Check: `grep -rn "Route.*'/{_locale}/" src/Controller/`

Related: [[feedback_release_cadence_discipline]]
