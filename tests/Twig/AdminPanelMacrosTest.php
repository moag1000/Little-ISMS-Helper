<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Smoke tests for Sprint-3 admin-panel macros. Each test renders the
 * macro with a representative argument set and asserts that the output
 * contains the marker class plus the expected payload.
 */
final class AdminPanelMacrosTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }


    #[Test]
    public function auditRowRendersSeverityAndSealedTone(): void
    {
        $output = $this->renderMacro('_fa_audit_row', 'render', [
            'ts' => '10:38:02.118',
            'sev' => 'info',
            'actor' => 'system',
            'action' => 'audit.seal',
            'target' => 'period:2025-W47',
            'pill' => 'sealed',
            'hash' => '00f2…3d8a',
            'sealed' => true,
        ]);

        $this->assertStringContainsString('fa-audit-row--sealed', $output);
        $this->assertStringContainsString('fa-audit-row__sev--info', $output);
        $this->assertStringContainsString('audit.seal', $output);
        $this->assertStringContainsString('00f2…3d8a', $output);
    }




    #[Test]
    public function confirmRendersDangerToneWithPhraseAndCooldown(): void
    {
        $output = $this->renderMacro('_fa_confirm', 'render', [
            'tone' => 'nuclear',
            'title' => 'Mandant löschen?',
            'sub' => 'Dieser Vorgang ist nicht reversibel.',
            'confirmPhrase' => 'tenant-delete',
            'cooldownSeconds' => 5,
            'submitLabel' => 'Endgültig löschen',
        ]);

        // BC-shim: legacy tone 'nuclear' is escalated to 'danger' with minimum 5s cooldown
        // by the _fa_confirm → _fa_modal.confirm delegator (see 2026-05-21 modal foundation PR).
        $this->assertStringContainsString('fa-modal--danger', $output);
        $this->assertStringContainsString('data-fa-confirm-cooldown-value="5"', $output);
        $this->assertStringContainsString('data-fa-confirm-phrase-value="tenant-delete"', $output);
        $this->assertStringContainsString('Endgültig löschen', $output);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function renderMacro(string $template, string $macro, array $args): string
    {
        $template = sprintf(
            '{%% import "_components/%s.html.twig" as m %%}{{ m.%s(args) }}',
            $template,
            $macro,
        );

        return $this->twig->createTemplate($template)->render(['args' => $args]);
    }
}
