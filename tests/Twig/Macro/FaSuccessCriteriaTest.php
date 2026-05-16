<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * P-9 JsonBuilder — smoke tests for `_fa_success_criteria.html.twig`.
 * BCExercise.successCriteria table (ISO 22301 §8.6c).
 */
final class FaSuccessCriteriaTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersStimulusControllerAndHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'bc_exercise[successCriteria]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-controller="success-criteria"', $output);
        self::assertStringContainsString('name="bc_exercise[successCriteria]"', $output);
        self::assertStringContainsString('data-success-criteria-target="hidden"', $output);
    }

    #[Test]
    public function rendersTriStateMetSelectorOptions(): void
    {
        $output = $this->renderMacro([
            'name' => 'bc_exercise[successCriteria]',
            'value' => null,
        ]);

        self::assertStringContainsString('value="unknown"', $output);
        self::assertStringContainsString('value="met"', $output);
        self::assertStringContainsString('value="not_met"', $output);
        self::assertStringContainsString('__MET_SEL_UNKNOWN__', $output);
        self::assertStringContainsString('__MET_SEL_MET__', $output);
        self::assertStringContainsString('__MET_SEL_NOT_MET__', $output);
    }

    #[Test]
    public function emitsPrefillValueAsStimulusDataAttribute(): void
    {
        $output = $this->renderMacro([
            'name' => 'bc_exercise[successCriteria]',
            'value' => null,
            'prefillCriteria' => [
                ['criterion' => 'RTO eingehalten', 'target' => '4h'],
                ['criterion' => 'RPO eingehalten', 'target' => '15min'],
            ],
        ]);

        self::assertStringContainsString('data-success-criteria-prefill-value=', $output);
        self::assertStringContainsString('RTO', $output);
        // apply-prefill button is conditionally shown by JS, but the markup is there.
        self::assertStringContainsString('data-success-criteria-target="prefillBtn"', $output);
    }

    #[Test]
    public function serialisesShapeAArrayIntoHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'bc_exercise[successCriteria]',
            'value' => [
                ['criterion' => 'RTO', 'target' => '4h', 'actual' => '3h', 'met' => 'met'],
            ],
        ]);

        self::assertStringContainsString('"criterion"', $output);
        self::assertStringContainsString('RTO', $output);
        self::assertStringContainsString('"met"', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacro(array $props): string
    {
        $template = '{% import "_components/_fa_success_criteria.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
