<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\Tenant;
use App\Template\Provider\ModuleProfileProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModuleProfileProviderTest extends TestCase
{
    #[Test]
    public function emitsFiveProfilesPerLanguage(): void
    {
        $provider = new ModuleProfileProvider();
        $templates = iterator_to_array($provider->provide());

        // 5 profiles × 2 languages = 10 (M-04 added automotive_supplier as the 5th)
        $this->assertCount(10, $templates);
    }

    #[Test]
    public function automotiveSupplierProfileEnablesTisaxStack(): void
    {
        $provider = new ModuleProfileProvider();
        $auto = null;
        foreach ($provider->provide() as $template) {
            if ($template->prefill['profileKey'] === 'automotive_supplier' && $template->language === 'de') {
                $auto = $template;
                break;
            }
        }
        $this->assertNotNull($auto, 'Automotive supplier DE template missing');

        $modules = $auto->prefill['activeModules'];
        $this->assertContains('tisax', $modules, 'Automotive profile must activate TISAX');
        $this->assertContains('tisax_isa', $modules, 'Automotive profile must activate TISAX-ISA');
        $this->assertContains('suppliers', $modules);
        $this->assertNotContains('marisk', $modules,
            'Automotive supplier is not under MaRisk by default');
    }

    #[Test]
    public function profilesAreTenantLevelAndNotModuleGated(): void
    {
        $provider = new ModuleProfileProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(Tenant::class, $template->entityClass);
            $this->assertNull($template->module,
                'Module-Profile templates must not be module-gated (they enable modules)');
            $this->assertArrayHasKey('activeModules', $template->prefill);
            $this->assertArrayHasKey('profileKey', $template->prefill);
        }
    }

    #[Test]
    public function kmuProfileEnablesCoreIsmsModules(): void
    {
        $provider = new ModuleProfileProvider();
        $kmu = null;
        foreach ($provider->provide() as $template) {
            if ($template->prefill['profileKey'] === 'kmu' && $template->language === 'de') {
                $kmu = $template;
                break;
            }
        }
        $this->assertNotNull($kmu);

        $modules = $kmu->prefill['activeModules'];
        foreach (['core', 'assets', 'risks', 'controls', 'incidents', 'privacy'] as $expected) {
            $this->assertContains($expected, $modules,
                sprintf('KMU profile should activate %s', $expected));
        }
    }

    #[Test]
    public function finanzdienstleisterEnablesDoraAndMarisk(): void
    {
        $provider = new ModuleProfileProvider();
        foreach ($provider->provide() as $template) {
            if ($template->prefill['profileKey'] === 'finanzdienstleister' && $template->language === 'de') {
                $modules = $template->prefill['activeModules'];
                $this->assertContains('marisk', $modules);
                $this->assertContains('eu_authority_reporting', $modules);
                return;
            }
        }
        $this->fail('Finanzdienstleister DE template not found');
    }

    #[Test]
    public function vereinProfileIsMinimal(): void
    {
        $provider = new ModuleProfileProvider();
        foreach ($provider->provide() as $template) {
            if ($template->prefill['profileKey'] === 'verein' && $template->language === 'de') {
                $modules = $template->prefill['activeModules'];
                $this->assertLessThan(10, count($modules),
                    'Verein profile should be minimal (< 10 modules)');
                $this->assertContains('privacy', $modules);
                return;
            }
        }
        $this->fail('Verein DE template not found');
    }
}
