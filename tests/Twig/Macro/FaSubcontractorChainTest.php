<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * P-9 JsonBuilder — smoke tests for `_fa_subcontractor_chain.html.twig`.
 * Supplier.subcontractorChain indented tier-builder (DORA Art. 28).
 */
final class FaSubcontractorChainTest extends KernelTestCase
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
            'name' => 'supplier[subcontractorChain]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-controller="subcontractor-chain"', $output);
        self::assertStringContainsString('name="supplier[subcontractorChain]"', $output);
        self::assertStringContainsString('data-subcontractor-chain-target="hidden"', $output);
    }

    #[Test]
    public function rendersRowTemplateWithIndentOutdentAndCriticalFlag(): void
    {
        $output = $this->renderMacro([
            'name' => 'supplier[subcontractorChain]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-subcontractor-chain-target="template"', $output);
        // Tier/indent/outdent affordances
        self::assertStringContainsString('data-action="click->subcontractor-chain#indent"', $output);
        self::assertStringContainsString('data-action="click->subcontractor-chain#outdent"', $output);
        // DORA Art. 28 critical flag
        self::assertStringContainsString('data-field="critical"', $output);
        self::assertStringContainsString('data-action="change->subcontractor-chain#updateCritical"', $output);
        // Standard fields
        self::assertStringContainsString('__NAME__', $output);
        self::assertStringContainsString('__COUNTRY__', $output);
        self::assertStringContainsString('__SERVICE__', $output);
        self::assertStringContainsString('__DEPTH__', $output);
    }

    #[Test]
    public function serialisesArrayValueAsJsonIntoHiddenTextarea(): void
    {
        $output = $this->renderMacro([
            'name' => 'supplier[subcontractorChain]',
            'value' => [
                ['tier' => 1, 'name' => 'AWS', 'country' => 'IE', 'service' => 'Cloud', 'critical' => true],
                ['tier' => 2, 'name' => 'CDN Edge', 'country' => 'DE', 'service' => 'CDN', 'critical' => false],
            ],
        ]);

        // Hidden textarea contents are HTML-encoded (&quot; instead of ").
        self::assertMatchesRegularExpression('/(&quot;|")tier(&quot;|")/', $output);
        self::assertStringContainsString('AWS', $output);
        self::assertMatchesRegularExpression('/(&quot;|")critical(&quot;|")/', $output);
    }

    #[Test]
    public function rendersRawJsonToggleAndAddButtons(): void
    {
        $output = $this->renderMacro([
            'name' => 'supplier[subcontractorChain]',
            'value' => null,
        ]);

        self::assertStringContainsString('data-action="click->subcontractor-chain#addRow"', $output);
        self::assertStringContainsString('data-action="click->subcontractor-chain#showRawJson"', $output);
        self::assertStringContainsString('data-subcontractor-chain-target="rawPanel"', $output);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacro(array $props): string
    {
        $template = '{% import "_components/_fa_subcontractor_chain.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
