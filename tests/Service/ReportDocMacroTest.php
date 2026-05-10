<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Smoke-test for the fa-report-doc 5-slot macro.
 *
 * Renders the macro with a stand-alone Twig environment (no Symfony kernel) to
 * keep the test fast and free of cross-cutting concerns. Verifies:
 *   - Macro renders without throwing for all three audience variants
 *     (vorstand, auditor, aufsicht)
 *   - All five mandatory slot CSS classes are present in the rendered output
 *   - Watermark + audience CSS classes propagate from props to markup
 */
final class ReportDocMacroTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(\dirname(__DIR__, 2) . '/templates');
        $this->twig = new Environment($loader, [
            'strict_variables' => false,
            'autoescape' => 'html',
            'cache' => false,
        ]);

        // Stub `trans` filter so the macro's translation calls become identity-pass-through.
        $this->twig->addFilter(new TwigFilter('trans', static function ($value): string {
            return (string) $value;
        }));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function audienceProvider(): array
    {
        return [
            'vorstand' => ['vorstand'],
            'auditor'  => ['auditor'],
            'aufsicht' => ['aufsicht'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('audienceProvider')]
    public function it_renders_all_five_slots_for_audience(string $audience): void
    {
        $rendered = $this->renderMacroWith([
            'report' => [
                'title' => 'Q2/2026 ISMS Report',
                'subtitle' => 'Quarterly compliance & risk overview',
                'kicker' => 'Quarterly Report · Q2/2026',
                'status' => 'final',
                'audience' => $audience,
                'classification' => 'CONFIDENTIAL',
                'generated_at' => '30.06.2026',
                'prepared_by' => 'M. Lange · CISO',
                'period' => 'Q2/2026',
            ],
            'brand_name' => 'Acme GmbH',
            'key_findings' => [
                ['label' => 'ISMS-Health 92%', 'tone' => 'ok'],
                ['label' => 'Compliance 85%', 'tone' => 'ok'],
                ['label' => '3 critical risks', 'tone' => 'warn'],
            ],
            'kpis' => [
                ['label' => 'Health',     'value' => '92%', 'trend' => 'up',   'tone' => 'success'],
                ['label' => 'Compliance', 'value' => '85%', 'trend' => 'up',   'tone' => 'success'],
                ['label' => 'Critical',   'value' => 3,      'trend' => 'down', 'tone' => 'warning'],
                ['label' => 'MTTR',       'value' => '48h',  'trend' => 'stable', 'tone' => 'success'],
            ],
            'sections' => [
                [
                    'heading' => 'Risk Distribution',
                    'intro' => 'Risikoportfolio nach Schweregrad.',
                    'charts' => [
                        ['type' => 'bar', 'caption' => 'Risiken nach Score'],
                    ],
                    'table' => [
                        'headers' => ['ID', 'Title', 'Score'],
                        'rows' => [
                            ['R-001', 'Phishing', 16],
                            ['R-002', 'Outage',   12],
                        ],
                    ],
                ],
            ],
            'appendix' => [
                'methodology' => '<p>Risk = Probability × Impact</p>',
                'sources' => [
                    ['label' => 'ISMS-DB', 'detail' => 'Stand 30.06.2026'],
                ],
                'glossary' => [
                    ['term' => 'MTTR', 'definition' => 'Mean Time To Resolve'],
                ],
            ],
            'distribution' => [
                ['role' => 'CISO', 'name' => 'M. Lange'],
            ],
        ]);

        // All five mandatory slots present.
        self::assertStringContainsString('report-doc__cover',            $rendered);
        self::assertStringContainsString('report-doc__exec-summary',     $rendered);
        self::assertStringContainsString('report-doc__data-section',     $rendered);
        self::assertStringContainsString('report-doc__appendix',         $rendered);
        self::assertStringContainsString('report-doc__footer-disclaimer', $rendered);

        // Audience-modifier class applied.
        self::assertStringContainsString('report-doc--' . $audience, $rendered);

        // Status-modifier class applied + data-attribute.
        self::assertStringContainsString('report-doc--final',          $rendered);
        self::assertStringContainsString('data-report-status="final"', $rendered);

        // Final status MUST NOT carry a watermark attribute.
        self::assertStringNotContainsString('data-watermark="DRAFT"', $rendered);
        self::assertStringNotContainsString('data-watermark="CONFIDENTIAL"', $rendered);

        // Article shell wraps the slots.
        self::assertStringContainsString('<article class="report-doc', $rendered);
        self::assertStringContainsString('</article>', $rendered);
    }

    #[Test]
    public function it_emits_watermark_attribute_for_draft_status(): void
    {
        $rendered = $this->renderMacroWith([
            'report' => [
                'title' => 'Draft report',
                'status' => 'draft',
                'audience' => 'auditor',
                'generated_at' => '30.06.2026',
            ],
            'brand_name' => 'Acme GmbH',
        ]);

        self::assertStringContainsString('report-doc--draft',         $rendered);
        self::assertStringContainsString('data-watermark="report.doc.watermark.draft"', $rendered);
    }

    #[Test]
    public function it_emits_watermark_attribute_for_auditor_only_status(): void
    {
        $rendered = $this->renderMacroWith([
            'report' => [
                'title' => 'Auditor-only report',
                'status' => 'auditor-only',
                'audience' => 'aufsicht',
                'generated_at' => '30.06.2026',
            ],
            'brand_name' => 'Acme GmbH',
        ]);

        self::assertStringContainsString('report-doc--auditor-only',         $rendered);
        self::assertStringContainsString('data-watermark="report.doc.watermark.auditor_only"', $rendered);
        self::assertStringContainsString('data-report-audience="aufsicht"',  $rendered);
    }

    #[Test]
    public function it_renders_distribution_list_in_footer(): void
    {
        $rendered = $this->renderMacroWith([
            'report' => [
                'title' => 'Distribution test',
                'status' => 'final',
                'audience' => 'vorstand',
                'generated_at' => '30.06.2026',
            ],
            'brand_name' => 'Acme GmbH',
            'distribution' => [
                ['role' => 'CISO',     'name' => 'M. Lange'],
                ['role' => 'Vorstand', 'name' => 'T. Berger'],
            ],
        ]);

        self::assertStringContainsString('report-doc__distribution-list', $rendered);
        self::assertStringContainsString('M. Lange',  $rendered);
        self::assertStringContainsString('T. Berger', $rendered);
        self::assertStringContainsString('CISO',      $rendered);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderMacroWith(array $props): string
    {
        return $this->twig->createTemplate(
            "{% import '_components/_fa_report_doc.html.twig' as m %}{{ m.render(props) }}"
        )->render(['props' => $props]);
    }
}
