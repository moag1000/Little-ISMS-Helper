<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Service\PolicyWizard\TailoringFieldQualityValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the W1 audit-defang gap #1 tailoring-field minimum-
 * quality validator. Asserts the six contract rules from
 * `07-phase4-sprint-reconciliation.md` line 215-225 (auditor "What
 * would make me NOT challenge auto-generation" item 1).
 */
#[AllowMockObjectsWithoutExpectations]
final class TailoringFieldQualityValidatorTest extends TestCase
{
    private TailoringFieldQualityValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TailoringFieldQualityValidator();
    }

    #[Test]
    public function testValidInputPasses(): void
    {
        $input = 'Geltungsbereich: alle Standorte der Beispiel AG in Deutschland und Österreich, '
            . 'inklusive der zentralen Datenverarbeitungssysteme.';

        $result = $this->validator->validateTailoringInput('scope_statement', $input);

        self::assertTrue($result['passed'], 'Auditor-grade scope statement must pass');
        self::assertSame([], $result['violations']);
        self::assertSame('scope_statement', $result['field_key']);
    }

    #[Test]
    public function testTooShortFails(): void
    {
        $input = 'Sicherheit'; // 10 chars — below the 30-char default floor.

        $result = $this->validator->validateTailoringInput('scope_statement', $input);

        self::assertFalse($result['passed']);
        self::assertContains(
            'policy_wizard.error.tailoring_quality.too_short',
            $result['violations'],
        );
    }

    #[Test]
    public function testWordRepetitionFails(): void
    {
        // The word "sicherheit" appears 5 times in this sentence; the
        // default max_repetitions is 3.
        $input = 'Sicherheit Sicherheit Sicherheit Sicherheit Sicherheit ist wichtig fuer alle Mitarbeiter.';

        $result = $this->validator->validateTailoringInput('scope_statement', $input);

        self::assertFalse($result['passed']);
        self::assertContains(
            'policy_wizard.error.tailoring_quality.repetition',
            $result['violations'],
        );
    }

    #[Test]
    public function testLoremIpsumFails(): void
    {
        $input = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor.';

        $result = $this->validator->validateTailoringInput('scope_statement', $input);

        self::assertFalse($result['passed']);
        self::assertContains(
            'policy_wizard.error.tailoring_quality.placeholder',
            $result['violations'],
        );
    }

    #[Test]
    public function testTbdPlaceholderFails(): void
    {
        $input = 'Beschreibung folgt — TBD durch CISO bis Quartalsende einzutragen.';

        $result = $this->validator->validateTailoringInput('scope_statement', $input);

        self::assertFalse($result['passed']);
        self::assertContains(
            'policy_wizard.error.tailoring_quality.placeholder',
            $result['violations'],
        );
    }

    #[Test]
    public function testCustomConstraintsFromTemplateRespected(): void
    {
        // 1) Per-call override: tighten min_length to 60.
        $input = 'Standorte: Hamburg, Berlin und Wien.';
        $result = $this->validator->validateTailoringInput(
            'scope_statement',
            $input,
            ['min_length' => 60],
        );
        self::assertFalse($result['passed']);
        self::assertContains(
            'policy_wizard.error.tailoring_quality.too_short',
            $result['violations'],
        );

        // 2) Per-template override read via resolveTemplateConstraints.
        $template = $this->createMock(PolicyTemplate::class);
        $template->method('getRequiredVariables')->willReturn([
            [
                'key'   => 'tailoring_constraints',
                'type'  => 'map',
                'value' => [
                    'scope_statement' => [
                        'min_length'         => 5,
                        'max_repetitions'    => 99,
                        'reject_lorem_ipsum' => false,
                    ],
                ],
            ],
        ]);

        $constraints = $this->validator->resolveTemplateConstraints($template);
        self::assertArrayHasKey('scope_statement', $constraints);

        // Same "lorem ipsum" input now passes because the template
        // disabled the placeholder check AND lowered the min length.
        $loosened = $this->validator->validateTailoringInput(
            'scope_statement',
            'Lorem ipsum testing the override.',
            $constraints['scope_statement'],
        );
        self::assertTrue($loosened['passed'], 'Template override must disable the placeholder check');
    }
}
