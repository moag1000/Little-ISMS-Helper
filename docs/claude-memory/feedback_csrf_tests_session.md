---
name: feedback-csrf-tests-session
description: CSRF token generation in WebTestCase requires active session — always make a GET request before calling token_manager->getToken()
type: feedback
originSessionId: 66461db0-055e-4e1c-9cd5-1c0dc0a21e17
---
When adding CSRF validation to endpoints, always fix the corresponding tests too.

In Symfony WebTestCase, `static::getContainer()->get('security.csrf.token_manager')->getToken('...')` throws `SessionNotFoundException` if no session is active yet. `loginUser()` alone does NOT start a session — the session starts on the first HTTP request.

**Why:** Asset bulk-delete tests broke in CI after adding CSRF checks (T1-2). Token was generated before any request, so no session existed.

**How to apply:** Before generating a CSRF token in a WebTestCase, ensure the client has made at least one request:
```php
$this->loginAsUser($user);
$this->client->request('GET', '/en/some-page/'); // starts session
$csrfToken = static::getContainer()->get('security.csrf.token_manager')
    ->getToken('token_id')->getValue();
```

Also: when adding security features (CSRF, auth guards, command whitelists), always check if tests use the affected endpoints and adapt them in the same commit.
