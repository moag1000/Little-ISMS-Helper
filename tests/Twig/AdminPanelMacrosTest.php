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
    public function permMatrixRendersRolesAndGrantedActions(): void
    {
        $output = $this->renderMacro('_fa_perm_matrix', 'render', [
            'roles' => [
                ['key' => 'admin', 'label' => 'Admin', 'color' => '#06b6d4'],
                ['key' => 'auditor', 'label' => 'Auditor', 'color' => '#8b5cf6'],
            ],
            'rows' => [
                [
                    'module' => 'Risiken',
                    'cells' => [
                        'admin' => ['view' => true, 'edit' => true, 'approve' => true, 'delete' => true],
                        'auditor' => ['view' => true],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('class="fa-perm-matrix"', $output);
        $this->assertStringContainsString('Risiken', $output);
        $this->assertStringContainsString('Admin', $output);
        $this->assertStringContainsString('V&nbsp;E&nbsp;A&nbsp;D', $output);
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
    public function apiKeyRendersMaskedTokenAndStatePill(): void
    {
        $output = $this->renderMacro('_fa_api_key', 'render', [
            'name' => 'CI · Build-Pipeline',
            'prefix' => 'lih_live_8a4f',
            'state' => 'ok',
            'stateLabel' => 'aktiv',
            'meta' => [
                ['icon' => 'shield-check', 'label' => 'controls:read'],
            ],
        ]);

        $this->assertStringContainsString('class="fa-api-key"', $output);
        $this->assertStringContainsString('lih_live_8a4f', $output);
        $this->assertStringContainsString('fa-api-key__state--ok', $output);
        $this->assertStringContainsString('aktiv', $output);
    }

    #[Test]
    public function svcTileRendersStateAttribute(): void
    {
        $output = $this->renderMacro('_fa_svc_tile', 'render', [
            'name' => 'API-Gateway',
            'state' => 'warn',
            'latency' => '112 ms',
            'uptime' => '99.82%',
            'detail' => 'Reindex läuft',
        ]);

        $this->assertStringContainsString('data-state="warn"', $output);
        $this->assertStringContainsString('API-Gateway', $output);
        $this->assertStringContainsString('112 ms', $output);
    }

    #[Test]
    public function skelRendersVariant(): void
    {
        $output = $this->renderMacro('_fa_skel', 'render', ['variant' => 'card']);

        $this->assertStringContainsString('fa-skel fa-skel--card', $output);
        $this->assertStringContainsString('aria-hidden="true"', $output);
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

        $this->assertStringContainsString('data-tone="nuclear"', $output);
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
