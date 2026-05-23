<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\InternalAudit;
use App\Template\Provider\AuditProgramTemplateProvider;
use App\Template\SystemTemplate;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test coverage for C5-05 (S14 Cluster E · Audit-Finding-Polish).
 *
 * The provider must emit one template per (program × language). All three
 * programs ship with sensible defaults so the form passes validation on the
 * first apply — junior implementers should not face a "blank required field"
 * trap after picking the template.
 */
final class AuditProgramTemplateProviderTest extends TestCase
{
    #[Test]
    public function emitsThreeProgramsPerLanguage(): void
    {
        $provider = new AuditProgramTemplateProvider();
        $templates = iterator_to_array($provider->provide());

        // iso27001_standard_audit + dsgvo_audit + bcm_audit × 2 languages = 6
        $this->assertCount(6, $templates);
    }

    #[Test]
    public function allTemplatesTargetInternalAudit(): void
    {
        $provider = new AuditProgramTemplateProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(InternalAudit::class, $template->entityClass);
            $this->assertNull($template->module, 'Audit templates are not module-gated');
        }
    }

    #[Test]
    public function shipsThreeDistinctProgramKeys(): void
    {
        $provider = new AuditProgramTemplateProvider();
        $keys = array_map(static fn(SystemTemplate $t): string => $t->key, iterator_to_array($provider->provide()));

        $expectedKeys = [
            'audit.program.iso27001_standard_audit.de',
            'audit.program.iso27001_standard_audit.en',
            'audit.program.dsgvo_audit.de',
            'audit.program.dsgvo_audit.en',
            'audit.program.bcm_audit.de',
            'audit.program.bcm_audit.en',
        ];

        foreach ($expectedKeys as $expected) {
            $this->assertContains($expected, $keys, "Missing template key: $expected");
        }
    }

    #[Test]
    public function allTemplatesShipNonBlankFormPassingDefaults(): void
    {
        $provider = new AuditProgramTemplateProvider();
        foreach ($provider->provide() as $template) {
            $prefill = $template->prefill;
            $this->assertArrayHasKey('title', $prefill);
            $this->assertNotEmpty($prefill['title'], "title must be set for {$template->key}");

            $this->assertArrayHasKey('scope', $prefill);
            $this->assertNotEmpty($prefill['scope']);

            $this->assertArrayHasKey('objectives', $prefill);
            $this->assertNotEmpty($prefill['objectives']);

            // plannedDate is required + NotNull on the entity — must be set
            $this->assertArrayHasKey('plannedDate', $prefill);
            $this->assertInstanceOf(DateTimeInterface::class, $prefill['plannedDate']);

            $this->assertArrayHasKey('status', $prefill);
            $this->assertSame('planned', $prefill['status']);

            $this->assertArrayHasKey('scopeType', $prefill);
            $this->assertContains($prefill['scopeType'], [
                'full_isms', 'compliance_framework', 'asset', 'asset_type',
                'asset_group', 'location', 'department',
                'corporate_wide', 'corporate_subsidiaries',
            ]);
        }
    }

    #[Test]
    public function germanAndEnglishHaveDifferentLocalisedCopy(): void
    {
        $provider = new AuditProgramTemplateProvider();
        $byKey = [];
        foreach ($provider->provide() as $template) {
            $byKey[$template->key] = $template;
        }

        $de = $byKey['audit.program.iso27001_standard_audit.de'];
        $en = $byKey['audit.program.iso27001_standard_audit.en'];

        $this->assertNotSame($de->prefill['title'], $en->prefill['title']);
        $this->assertNotSame($de->prefill['scope'], $en->prefill['scope']);
        $this->assertSame('de', $de->language);
        $this->assertSame('en', $en->language);
    }
}
