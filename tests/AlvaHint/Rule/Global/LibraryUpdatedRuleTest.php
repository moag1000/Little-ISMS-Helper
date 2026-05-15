<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\LibraryUpdatedRule;
use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LibraryUpdatedRule.
 *
 * Tests: version mismatch triggers hint, same version suppresses hint,
 * missing YAML file is skipped gracefully, missing framework is skipped.
 */
#[AllowMockObjectsWithoutExpectations]
final class LibraryUpdatedRuleTest extends TestCase
{
    private string $projectDir;
    private Tenant $tenant;
    private User $user;

    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/library_hint_test_' . uniqid();
        mkdir($this->projectDir . '/fixtures/library/frameworks', 0777, true);

        $this->tenant = new Tenant();
        $this->user = new User();
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
    }

    protected function tearDown(): void
    {
        // Clean up YAMLs written by tests
        $dir = $this->projectDir . '/fixtures/library/frameworks';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.yaml') as $file) {
                unlink($file);
            }
            rmdir($dir);
            rmdir($this->projectDir . '/fixtures/library');
            rmdir($this->projectDir . '/fixtures');
        }
        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    #[Test]
    public function returnsHintWhenYamlVersionIsNewerThanDb(): void
    {
        $this->writeYaml('bsi-it-grundschutz-2024.yaml', '2024.2');

        $framework = $this->makeFramework('BSI-GRUNDSCHUTZ-2024', '2024.1');
        $this->frameworkRepo->method('findOneBy')
            ->with(['code' => 'BSI-GRUNDSCHUTZ-2024'])
            ->willReturn($framework);

        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.library_updated', $hint->key);
        self::assertSame('info', $hint->variant);
        self::assertTrue($hint->dismissible);
        self::assertSame(3, $hint->priorityTier);
        self::assertSame('admin_library_index', $hint->actionRoute);
    }

    #[Test]
    public function returnsNullWhenYamlVersionMatchesDb(): void
    {
        $this->writeYaml('bsi-it-grundschutz-2024.yaml', '2024.1');

        $framework = $this->makeFramework('BSI-GRUNDSCHUTZ-2024', '2024.1');
        $this->frameworkRepo->method('findOneBy')
            ->with(['code' => 'BSI-GRUNDSCHUTZ-2024'])
            ->willReturn($framework);

        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenFrameworkNotYetImported(): void
    {
        $this->writeYaml('bsi-it-grundschutz-2024.yaml', '2024.1');

        $this->frameworkRepo->method('findOneBy')->willReturn(null);

        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsNullWhenYamlFilesAreMissing(): void
    {
        // No YAML files written — all paths missing
        $this->frameworkRepo->method('findOneBy')->willReturn(null);

        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function hintBodyParamsContainVersionInfo(): void
    {
        $this->writeYaml('bsi-it-grundschutz-2024.yaml', '2024.2');

        $framework = $this->makeFramework('BSI-GRUNDSCHUTZ-2024', '2024.1');
        $this->frameworkRepo->method('findOneBy')->willReturn($framework);

        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertArrayHasKey('%code%', $hint->bodyTranslationParams);
        self::assertArrayHasKey('%yaml_version%', $hint->bodyTranslationParams);
        self::assertArrayHasKey('%db_version%', $hint->bodyTranslationParams);
        self::assertSame('BSI-GRUNDSCHUTZ-2024', $hint->bodyTranslationParams['%code%']);
        self::assertSame('2024.2', $hint->bodyTranslationParams['%yaml_version%']);
        self::assertSame('2024.1', $hint->bodyTranslationParams['%db_version%']);
    }

    #[Test]
    public function requiresComplianceModule(): void
    {
        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        self::assertContains('compliance', $rule->requiredModules());
    }

    #[Test]
    public function requiresAdminRole(): void
    {
        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);

        // Trigger a hint to check requiredRoles
        $this->writeYaml('bsi-it-grundschutz-2024.yaml', '2024.2');
        $framework = $this->makeFramework('BSI-GRUNDSCHUTZ-2024', '2024.1');
        $this->frameworkRepo->method('findOneBy')->willReturn($framework);

        $hint = $rule->evaluate($this->tenant, $this->user);
        self::assertNotNull($hint);
        self::assertContains('ROLE_ADMIN', $hint->requiredRoles);
    }

    #[Test]
    public function appliesToLibraryAndHubPages(): void
    {
        $rule = new LibraryUpdatedRule($this->frameworkRepo, $this->projectDir);
        $pages = $rule->appliesToPages();

        self::assertContains('admin_library_index', $pages);
        self::assertContains('admin_hub_index', $pages);
    }

    private function writeYaml(string $filename, string $version): void
    {
        $yaml = sprintf(
            "metadata:\n  code: 'BSI-GRUNDSCHUTZ-2024'\n  name: 'Test'\n  version: '%s'\n",
            $version,
        );
        file_put_contents($this->projectDir . '/fixtures/library/frameworks/' . $filename, $yaml);
    }

    private function makeFramework(string $code, string $version): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode($code);
        $framework->setName('Test Framework');
        $framework->setVersion($version);
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('BSI');
        $framework->setMandatory(false);

        return $framework;
    }
}
