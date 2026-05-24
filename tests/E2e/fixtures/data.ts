/**
 * Test-data helpers.
 *
 * Each helper returns a unique-per-test string suffix so concurrent or repeated
 * runs do not collide on unique constraints. Tests run against the shared
 * screenshot-user tenant for now (see tests/e2e/README.md §Test-data lifecycle).
 */

/** Random suffix safe for inputs/URL slugs (lowercase + digits, 10 chars). */
export function uniqueSuffix(prefix: string = ''): string {
    const ts = Date.now().toString(36);
    const rnd = Math.random().toString(36).slice(2, 6);
    return prefix ? `${prefix}-${ts}-${rnd}` : `${ts}-${rnd}`;
}

/** Build a name for an entity that includes the test-run timestamp. */
export function testEntityName(kind: string): string {
    return `E2E-${kind}-${uniqueSuffix()}`;
}

/** Build a unique e-mail for forms that need one. */
export function testEmail(): string {
    return `e2e-${uniqueSuffix()}@example.test`;
}
