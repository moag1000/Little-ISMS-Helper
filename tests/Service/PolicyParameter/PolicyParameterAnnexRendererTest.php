<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;
use App\Repository\OrganizationSecurityProfileRepository;
use App\Service\PolicyParameter\FrameworkConstraintChecker;
use App\Service\PolicyParameter\FrameworkCoverageEvaluator;
use App\Service\PolicyParameter\ParameterRegisterBuilder;
use App\Service\PolicyParameter\PolicyBaselineApplier;
use App\Service\PolicyParameter\PolicyBaselineCatalog;
use App\Service\PolicyParameter\PolicyParameterAnnexRenderer;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyParameterResolver;
use App\Service\PolicyParameter\PolicyProfileManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PolicyParameterAnnexRendererTest extends TestCase
{
    private function renderer(?OrganizationSecurityProfile $profile): PolicyParameterAnnexRenderer
    {
        $params = new PolicyParameterCatalog(\dirname(__DIR__, 3) . '/config/policy_parameters');
        $baselines = new PolicyBaselineCatalog(\dirname(__DIR__, 3) . '/config/policy_baselines');
        $manager = new PolicyProfileManager(
            $params,
            $baselines,
            new PolicyParameterResolver($params),
            new PolicyBaselineApplier($baselines),
            new FrameworkCoverageEvaluator($params, new FrameworkConstraintChecker()),
        );

        $repo = $this->createMock(OrganizationSecurityProfileRepository::class);
        $repo->method('findForTenant')->willReturn($profile);

        // Identity translator: returns the key so we can assert structure.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new PolicyParameterAnnexRenderer(
            $repo,
            $manager,
            new ParameterRegisterBuilder($params),
            $translator,
            $params,
        );
    }

    #[Test]
    public function it_renders_a_markdown_annex_with_resolved_values_and_authority(): void
    {
        $profile = (new OrganizationSecurityProfile())
            ->setTenantId(2)
            ->setValue('mfa_scope', 'all')
            ->setValue('approval_model', 'dual_signoff');

        $md = $this->renderer($profile)->renderForTenant(2, ['dora']);

        self::assertStringContainsString('## annex.heading', $md);          // markdown section header
        self::assertStringContainsString('annex.col.parameter', $md);       // table header row
        self::assertStringContainsString('all', $md);                       // resolved mfa_scope value
        self::assertStringContainsString('dual_signoff', $md);              // resolved approval value
        self::assertStringContainsString('DORA Art. 9(3)', $md);            // source from catalog constraint
        self::assertStringContainsString('annex.authority.regulatory', $md); // DORA-constrained → regulatory
        self::assertStringContainsString('DORA', $md);                      // framework column
    }

    #[Test]
    public function it_returns_empty_string_when_tenant_has_no_profile(): void
    {
        self::assertSame('', $this->renderer(null)->renderForTenant(2, ['dora']));
    }
}
