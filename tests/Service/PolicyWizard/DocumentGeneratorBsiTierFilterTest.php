<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\VariableCollector;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Policy-Wizard W5-A — DocumentGenerator bsi.tier_filter integration.
 *
 * Verifies the §8 tier filter contract: the resolved tenant setting
 * `bsi.tier_filter` decides which BSI templates ship.
 *
 *   • basis_only      → only `basis` (and NULL-tiered) templates ship
 *   • up_to_standard  → `basis` + `standard` (and NULL) ship; `kern` skipped
 *   • kern_full       → every template ships
 *   • unknown filter  → falls back safely to basis_only
 *
 * Sandbox-mode is used so the tests stay free of EntityManager + DB
 * coupling and only exercise the gating + render-pass logic.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorBsiTierFilterTest extends TestCase
{
    private const string TOPIC_BASIS = 'it_security_policy';
    private const string TOPIC_STANDARD = 'iam';
    private const string TOPIC_KERN = 'kern_only_policy';
    private const string TOPIC_NULL = 'iso_top_level_no_tier';

    private function makeTenant(int $id = 11): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeUser(int $id = 9): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeTemplate(string $standard, string $topic, ?string $bsiTier, int $id): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('policy.' . $standard . '.' . $topic . '.v1.title');
        $template->setBodyTranslationKey('policy.' . $standard . '.' . $topic . '.v1.body');
        $template->setBsiTier($bsiTier);
        $template->setVersion(1);

        $reflection = new \ReflectionProperty(PolicyTemplate::class, 'id');
        $reflection->setValue($template, $id);
        return $template;
    }

    private function makeRun(Tenant $tenant, string $standard = 'bsi'): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setStandardsAdopted([$standard]);
        // Sandbox mode keeps the test free of EntityManager persistence.
        $run->setMode(WizardStepKeys::MODE_SANDBOX);
        $run->setStartedByUser($this->makeUser());
        $run->setInputs([]);

        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, 100);
        return $run;
    }

    /**
     * @param list<PolicyTemplate> $templates
     */
    private function makeGenerator(array $templates, ?string $resolvedFilter): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $cb) => $cb(null),
        );

        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $templateRepo->method('findActiveByStandard')->willReturn($templates);

        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findOneBy')->willReturn(null);

        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $dclRepo->method('findOneByDocumentAndControl')->willReturn(null);

        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findOneBy')->willReturn(null);

        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);

        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn(['tenant.legal_name' => 'TestCo']);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_ends_with($key, '.title') => 'Title for ' . $key,
                str_ends_with($key, '.body') => 'Body for ' . $key,
                default => $key,
            },
        );

        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $sectionRepo->method('findOneByDocumentAndKey')->willReturn(null);

        $policyProvider = $resolvedFilter === null
            ? null
            : $this->makePolicyProvider($resolvedFilter);

        return new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            $sectionRepo,
            new \Psr\Log\NullLogger(),
            null,
            $policyProvider,
        );
    }

    private function makePolicyProvider(string $resolvedFilter): PolicySettingProvider
    {
        // Real PolicySettingProvider with a resolver-mock that returns
        // the requested filter value. Tests both the provider's
        // tierAllowedUnderFilter() helper and the filter resolution path
        // in DocumentGenerator end-to-end.
        $resolver = $this->createMock(\App\Service\TenantSettingResolver\TenantSettingResolver::class);
        $resolver->method('resolveFor')->willReturn(
            new \App\Service\TenantSettingResolver\SettingResolutionResult(
                $resolvedFilter,
                'default',
                \App\Service\TenantSettingResolver\OverrideMode::ForbiddenToRelax,
            ),
        );
        return new PolicySettingProvider($resolver);
    }

    /**
     * @return list<PolicyTemplate>
     */
    private function makeBsiCorpus(): array
    {
        return [
            $this->makeTemplate('bsi', self::TOPIC_BASIS, PolicyTemplate::BSI_TIER_BASIS, 101),
            $this->makeTemplate('bsi', self::TOPIC_STANDARD, PolicyTemplate::BSI_TIER_STANDARD, 102),
            $this->makeTemplate('bsi', self::TOPIC_KERN, PolicyTemplate::BSI_TIER_KERN, 103),
            // ISO-style template without a BSI tier — must always ship.
            $this->makeTemplate('bsi', self::TOPIC_NULL, null, 104),
        ];
    }

    /**
     * Pull the topic list from a sandbox-result preview block.
     *
     * @param array{document_ids: list<int>, sandbox_preview: array<string, mixed>|null} $result
     * @return list<string>
     */
    private function topicsIn(array $result): array
    {
        $preview = $result['sandbox_preview'] ?? null;
        if (!is_array($preview)) {
            return [];
        }
        $policies = $preview['policies'] ?? [];
        if (!is_array($policies)) {
            return [];
        }
        $topics = [];
        foreach ($policies as $row) {
            if (is_array($row) && isset($row['topic']) && is_string($row['topic'])) {
                $topics[] = $row['topic'];
            }
        }
        sort($topics);
        return $topics;
    }

    #[Test]
    public function testBasisOnlyShipsOnlyBasisAndNullTiered(): void
    {
        $generator = $this->makeGenerator(
            $this->makeBsiCorpus(),
            PolicySettingProvider::TIER_FILTER_BASIS_ONLY,
        );
        $result = $generator->generate($this->makeRun($this->makeTenant()));

        $topics = $this->topicsIn($result);
        // basis_only: it_security_policy (basis) + iso_top_level_no_tier (null)
        $expected = [self::TOPIC_NULL, self::TOPIC_BASIS];
        sort($expected);
        self::assertSame($expected, $topics);
    }

    #[Test]
    public function testUpToStandardShipsBasisAndStandardSkipsKern(): void
    {
        $generator = $this->makeGenerator(
            $this->makeBsiCorpus(),
            PolicySettingProvider::TIER_FILTER_UP_TO_STANDARD,
        );
        $result = $generator->generate($this->makeRun($this->makeTenant()));

        $topics = $this->topicsIn($result);
        // up_to_standard: basis + standard + null. Kern dropped.
        $expected = [self::TOPIC_NULL, self::TOPIC_BASIS, self::TOPIC_STANDARD];
        sort($expected);
        self::assertSame($expected, $topics);
        self::assertNotContains(self::TOPIC_KERN, $topics, 'kern templates skipped under up_to_standard');
    }

    #[Test]
    public function testKernFullShipsEverything(): void
    {
        $generator = $this->makeGenerator(
            $this->makeBsiCorpus(),
            PolicySettingProvider::TIER_FILTER_KERN_FULL,
        );
        $result = $generator->generate($this->makeRun($this->makeTenant()));

        $topics = $this->topicsIn($result);
        $expected = [self::TOPIC_BASIS, self::TOPIC_KERN, self::TOPIC_NULL, self::TOPIC_STANDARD];
        sort($expected);
        self::assertSame($expected, $topics, 'kern_full ships all four templates including kern');
    }

    #[Test]
    public function testUnknownFilterFallsBackToBasisOnlySafely(): void
    {
        // Inject a bogus stored value via the resolver mock — provider
        // must reject and substitute the basis_only default.
        $resolver = $this->createMock(\App\Service\TenantSettingResolver\TenantSettingResolver::class);
        $resolver->method('resolveFor')->willReturn(
            new \App\Service\TenantSettingResolver\SettingResolutionResult(
                'totally_invalid_value',
                'default',
                \App\Service\TenantSettingResolver\OverrideMode::ForbiddenToRelax,
            ),
        );
        $policyProvider = new PolicySettingProvider($resolver);

        // Sanity-check the helper itself first.
        self::assertSame(
            PolicySettingProvider::TIER_FILTER_DEFAULT,
            $policyProvider->resolveBsiTierFilter($this->makeTenant()),
            'unknown stored values must collapse to the basis_only default',
        );
        self::assertTrue($policyProvider->tierAllowedUnderFilter('basis', 'totally_bogus'));
        self::assertFalse($policyProvider->tierAllowedUnderFilter('standard', 'totally_bogus'));
        self::assertFalse($policyProvider->tierAllowedUnderFilter('kern', 'totally_bogus'));
        // NULL-tiered templates always ship regardless of the filter value.
        self::assertTrue($policyProvider->tierAllowedUnderFilter(null, 'totally_bogus'));

        // End-to-end: even with a corrupted setting the generator only
        // ships basis + null-tiered topics — no over-ship leak possible.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $cb) => $cb(null),
        );

        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $templateRepo->method('findActiveByStandard')->willReturn($this->makeBsiCorpus());

        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('findOneBy')->willReturn(null);
        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $dclRepo->method('findOneByDocumentAndControl')->willReturn(null);
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findOneBy')->willReturn(null);
        $tagRepo = $this->createMock(TagRepository::class);
        $tagRepo->method('findOneByName')->willReturn(null);
        $variableCollector = $this->createMock(VariableCollector::class);
        $variableCollector->method('collectFor')->willReturn(['tenant.legal_name' => 'TestCo']);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => 'Trans for ' . $key,
        );
        $sectionRepo = $this->createMock(DocumentSectionRepository::class);
        $sectionRepo->method('findOneByDocumentAndKey')->willReturn(null);

        $generator = new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            $sectionRepo,
            new \Psr\Log\NullLogger(),
            null,
            $policyProvider,
        );

        $result = $generator->generate($this->makeRun($this->makeTenant()));
        $topics = $this->topicsIn($result);
        $expected = [self::TOPIC_NULL, self::TOPIC_BASIS];
        sort($expected);
        self::assertSame(
            $expected,
            $topics,
            'corrupted setting must collapse to basis_only — no over-ship leak',
        );
    }
}
