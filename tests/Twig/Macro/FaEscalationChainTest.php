<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * P-9 JsonBuilder — smoke tests for `_fa_escalation_chain.html.twig`.
 * BCPlan.escalationLevels horizontal cascade (BSI 200-4 §6.2).
 */
final class FaEscalationChainTest extends KernelTestCase
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
            'name' => 'bcp[escalationLevels]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-controller="escalation-chain"', $output);
        self::assertStringContainsString('name="bcp[escalationLevels]"', $output);
        self::assertStringContainsString('data-escalation-chain-target="hidden"', $output);
    }

    #[Test]
    public function rendersRowTemplateWithFourFieldPlaceholders(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[escalationLevels]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-escalation-chain-target="template"', $output);
        self::assertStringContainsString('__LEVEL__', $output);
        self::assertStringContainsString('__TRIGGER__', $output);
        self::assertStringContainsString('__RESPONDER__', $output);
        self::assertStringContainsString('__ESCALATE_AFTER__', $output);
    }

    #[Test]
    public function serialisesArrayValueIntoHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[escalationLevels]',
            'value' => [
                ['level' => 1, 'trigger' => 'Outage > 30min', 'responder' => 'ISB', 'escalateAfter' => '15min'],
                ['level' => 2, 'trigger' => 'Outage > 2h', 'responder' => 'CIO', 'escalateAfter' => '1h'],
            ],
        ]);

        self::assertStringContainsString('"trigger"', $output);
        self::assertStringContainsString('Outage', $output);
        self::assertStringContainsString('escalateAfter', $output);
    }

    #[Test]
    public function rendersAddAndRawJsonButtons(): void
    {
        $output = $this->renderMacro([
            'name' => 'bcp[escalationLevels]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-action="click->escalation-chain#addRow"', $output);
        self::assertStringContainsString('data-action="click->escalation-chain#showRawJson"', $output);
        self::assertStringContainsString('data-escalation-chain-target="rawPanel"', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacro(array $props): string
    {
        $template = '{% import "_components/_fa_escalation_chain.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
