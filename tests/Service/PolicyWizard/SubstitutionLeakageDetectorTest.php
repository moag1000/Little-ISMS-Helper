<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SubstitutionLeakageDetector;
use App\Service\PolicyWizard\SubstitutionLeakageException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the W1 audit-defang gap #3 leakage detector. Asserts
 * the five contract rules from `07-phase4-sprint-reconciliation.md`
 * line 215-225 (auditor "What would make me NOT challenge auto-
 * generation" item 3).
 */
final class SubstitutionLeakageDetectorTest extends TestCase
{
    #[Test]
    public function testCleanBodyPasses(): void
    {
        $body = <<<MARKDOWN
            # ISMS-Leitlinie der Beispiel AG

            Diese Leitlinie regelt die Informationssicherheit der Beispiel AG.
            Wir berücksichtigen explizit den Klimawandel als externen Faktor
            (ISO 27001:2022 Cl. 4.1, Amd. 1:2024).

            ## Geltungsbereich

            Alle Standorte in Deutschland und Österreich.
            MARKDOWN;

        // No exception expected — clean prose passes the detector.
        SubstitutionLeakageDetector::assertNoLeaks($body);
        self::assertSame([], SubstitutionLeakageDetector::findLeaks($body));
    }

    #[Test]
    public function testRawCurlyBracesDetectedAsLeak(): void
    {
        $body = "# Policy of {{ tenant.legal_name }}\n\nWir schützen {{ tenant.scope }}.";

        try {
            SubstitutionLeakageDetector::assertNoLeaks($body);
            self::fail('Expected SubstitutionLeakageException');
        } catch (SubstitutionLeakageException $exception) {
            self::assertCount(2, $exception->leaks);
            self::assertStringContainsString('tenant.legal_name', $exception->leaks[0]['token']);
            self::assertSame(1, $exception->leaks[0]['line']);
            self::assertStringContainsString('tenant.scope', $exception->leaks[1]['token']);
            self::assertSame(3, $exception->leaks[1]['line']);
        }
    }

    #[Test]
    public function testTwigStatementDetectedAsLeak(): void
    {
        $body = "# Policy\n\n{% if tenant.is_gdpr_subject %}DSGVO gilt.{% endif %}";

        try {
            SubstitutionLeakageDetector::assertNoLeaks($body);
            self::fail('Expected SubstitutionLeakageException');
        } catch (SubstitutionLeakageException $exception) {
            self::assertGreaterThanOrEqual(1, count($exception->leaks));
            self::assertStringContainsString('{%', $exception->leaks[0]['token']);
            self::assertStringContainsString('if tenant.is_gdpr_subject', $exception->leaks[0]['token']);
        }
    }

    #[Test]
    public function testTwigCommentDetectedAsLeak(): void
    {
        $body = "# Policy\n\n{# TODO: replace with v2 wording #}\n\nWir schützen unsere Assets.";

        try {
            SubstitutionLeakageDetector::assertNoLeaks($body);
            self::fail('Expected SubstitutionLeakageException');
        } catch (SubstitutionLeakageException $exception) {
            self::assertCount(1, $exception->leaks);
            self::assertStringContainsString('{#', $exception->leaks[0]['token']);
            self::assertStringContainsString('TODO', $exception->leaks[0]['token']);
            self::assertSame(3, $exception->leaks[0]['line']);
        }
    }

    #[Test]
    public function testCodeBlockExclusionWhitelisted(): void
    {
        // Twig syntax inside fenced code blocks is a developer-doc
        // example and must NOT be flagged. Same for <code>…</code>
        // and <pre>…</pre> wrappers.
        $body = <<<MARKDOWN
            # Operator handbook

            Templates may use the standard Twig pipeline:

            ```twig
            Hello {{ user.name }}, welcome to {% block title %}the site{% endblock %}.
            ```

            Inline example: <code>{{ tenant.legal_name }}</code>

            Block example:

            <pre>
            {# this is just a comment example #}
            </pre>
            MARKDOWN;

        SubstitutionLeakageDetector::assertNoLeaks($body);
        self::assertSame([], SubstitutionLeakageDetector::findLeaks($body));
    }
}
