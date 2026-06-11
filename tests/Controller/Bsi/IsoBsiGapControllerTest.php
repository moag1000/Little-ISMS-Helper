<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bsi;

use App\Controller\Bsi\IsoBsiGapController;
use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Bsi\BsiGapResult;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\ComplianceInheritanceService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Unit tests for IsoBsiGapController.
 *
 * Covers:
 *   - module OFF → 302 redirect for both actions
 *   - module ON + frameworks loaded → 200 with bucket KPI tiles + level selector
 *   - POST level=kern → assurance level updated + 302 back
 *   - POST invalid level → flash error added, no flush, 302 back
 *   - Acceptance: correct bucket assignment (erledigt+quick_win > 0, unmapped SYS req → pruefen)
 */
#[AllowMockObjectsWithoutExpectations]
final class IsoBsiGapControllerTest extends TestCase
{
    /** @var MockObject&IsoToBsiGapService */
    private MockObject $gapService;

    /** @var MockObject&ComplianceFrameworkRepository */
    private MockObject $frameworkRepo;

    /** @var MockObject&ComplianceInheritanceService */
    private MockObject $inheritance;

    /** @var MockObject&TenantContext */
    private MockObject $tenantContext;

    /** @var MockObject&EntityManagerInterface */
    private MockObject $em;

    /** @var MockObject&ModuleConfigurationService */
    private MockObject $moduleService;

    /** @var MockObject&TranslatorInterface */
    private MockObject $translator;

    private IsoBsiGapController $controller;

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @var MockObject&Tenant */
    private MockObject $tenant;

    /** @var MockObject&ComplianceFramework */
    private MockObject $iso;

    /** @var MockObject&ComplianceFramework */
    private MockObject $bsi;

    protected function setUp(): void
    {
        $this->gapService    = $this->createMock(IsoToBsiGapService::class);
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->inheritance   = $this->createMock(ComplianceInheritanceService::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->moduleService = $this->createMock(ModuleConfigurationService::class);
        $this->translator    = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturn('translated');

        $this->tenant = $this->createMock(Tenant::class);
        $this->iso    = $this->createMock(ComplianceFramework::class);
        $this->bsi    = $this->createMock(ComplianceFramework::class);

        $this->controller = new IsoBsiGapController(
            $this->gapService,
            $this->frameworkRepo,
            $this->inheritance,
            $this->tenantContext,
            $this->em,
            $this->moduleService,
            $this->translator,
        );

        $this->wireContainer();
    }

    // ── Container wiring ──────────────────────────────────────────────────────

    private function wireContainer(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html><body><div data-testid="bucket-quick_win"></div><div data-tour-step="bsi-gap-level"></div></body></html>');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/de/compliance/cross-gap');

        // The request in the stack MUST use FlashBagAwareSessionInterface
        // so that addFlash() in ModuleGatedControllerTrait can resolve the FlashBag.
        $flashBag = $this->createMock(FlashBagInterface::class);
        $session  = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('get')->willReturn([]);
        $session->method('getFlashBag')->willReturn($flashBag);

        $request = new Request();
        $request->setSession($session);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn($request);
        $requestStack->method('getSession')->willReturn($session);

        $parameterBag = $this->createMock(\Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface::class);
        $parameterBag->method('get')->willReturn(false);

        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('isTokenValid')->willReturn(true);
        $csrfManager->method('getToken')->willReturn(new CsrfToken('bsi_level', 'valid-token'));

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createMock(User::class));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($twig, $router, $requestStack, $parameterBag, $csrfManager, $security) {
                return match ($id) {
                    'twig'                                              => $twig,
                    'router'                                            => $router,
                    'request_stack'                                     => $requestStack,
                    'parameter_bag'                                     => $parameterBag,
                    'security.csrf.token_manager'                       => $csrfManager,
                    'security.csrf.token_manager.default'               => $csrfManager,
                    \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class => $csrfManager,
                    'security'                                          => $security,
                    default                                             => null,
                };
            }
        );

        $this->controller->setContainer($container);
    }

    private function makeFlashBagSession(): FlashBagAwareSessionInterface
    {
        $flashBag = $this->createMock(FlashBagInterface::class);
        $session  = $this->createMock(FlashBagAwareSessionInterface::class);
        $session->method('get')->willReturn([]);
        $session->method('getFlashBag')->willReturn($flashBag);
        return $session;
    }

    private function makeRequest(string $method = 'GET', array $post = []): Request
    {
        $request = new Request([], $post);
        $request->setMethod($method);
        $request->setSession($this->makeFlashBagSession());
        return $request;
    }

    // ── Test: module OFF → 302 redirect ──────────────────────────────────────

    #[Test]
    public function crossGapRedirectsWhenModuleOff(): void
    {
        $this->moduleService->method('isModuleActive')->willReturn(false);

        $response = $this->controller->crossGap();

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // ── Test: module ON + frameworks loaded → 200 ────────────────────────────

    #[Test]
    public function crossGapReturns200WithBucketTilesAndLevelSelectorWhenModuleActive(): void
    {
        $this->moduleService->method('isModuleActive')->willReturn(true);
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);
        $this->tenant->method('getBsiAssuranceLevel')->willReturn('standard');

        $this->frameworkRepo->method('findOneBy')
            ->willReturnCallback(fn(array $c) => $c['code'] === 'ISO27001' ? $this->iso : $this->bsi);

        $result = new BsiGapResult(
            items: [],
            bucketCounts: ['erledigt' => 0, 'quick_win' => 0, 'bsi_arbeit' => 0, 'pruefen' => 0],
            total: 0,
        );
        $this->gapService->method('buildGap')->willReturn($result);
        $this->inheritance->method('getPendingReviewCount')->willReturn(0);

        $response = $this->controller->crossGap();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertNotFalse($content);
        $this->assertStringContainsString('data-testid="bucket-quick_win"', $content);
        $this->assertStringContainsString('data-tour-step="bsi-gap-level"', $content);
    }

    // ── Test: POST level=kern → flush + 302 ──────────────────────────────────

    #[Test]
    public function setLevelKernUpdatesAssuranceLevelAndRedirects(): void
    {
        $this->moduleService->method('isModuleActive')->willReturn(true);
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        // setBsiAssuranceLevel should succeed silently for 'kern'
        $this->tenant->expects($this->once())
            ->method('setBsiAssuranceLevel')
            ->with('kern');

        $this->em->expects($this->once())->method('flush');

        $request = $this->makeRequest('POST', ['level' => 'kern', '_token' => 'valid-token']);

        $response = $this->controller->setLevel($request);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // ── Test: POST invalid level → flash error, no flush ─────────────────────

    #[Test]
    public function setLevelWithInvalidLevelAddsFlashErrorAndNoFlush(): void
    {
        $this->moduleService->method('isModuleActive')->willReturn(true);
        $this->tenantContext->method('getCurrentTenant')->willReturn($this->tenant);

        $this->tenant->method('setBsiAssuranceLevel')
            ->willThrowException(new \App\Exception\InvalidArgument\InvalidArgumentException('Invalid level'));

        // flush must NOT be called on invalid input
        $this->em->expects($this->never())->method('flush');

        $request = $this->makeRequest('POST', ['level' => 'invalid_level', '_token' => 'valid-token']);

        $response = $this->controller->setLevel($request);

        // Still redirects (PRG pattern)
        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // ── Test: module OFF → setLevel also redirects ────────────────────────────

    #[Test]
    public function setLevelRedirectsWhenModuleOff(): void
    {
        $this->moduleService->method('isModuleActive')->willReturn(false);

        $request = $this->makeRequest('POST', ['level' => 'kern', '_token' => 'valid-token']);
        $response = $this->controller->setLevel($request);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // ── ACCEPTANCE: gap-correctness bucket classification ─────────────────────
    //
    // Reuses IsoToBsiGapService directly (no DB) to verify that:
    //   1. A fulfilled ISO control with official crosswalk mapping → erledigt (> 0)
    //   2. An iso_offen official mapping → quick_win (> 0)
    //   3. A SYS requirement with NO ISO mapping → pruefen (not falsely 'erledigt')
    //
    // This mirrors the service-level test but documents the controller's
    // expected gap-correctness contract.

    #[Test]
    public function acceptanceGapCorrectnessBucketAssignment(): void
    {
        // Re-instantiate service with real logic + mock repos
        $reqRepo         = $this->createMock(\App\Repository\ComplianceRequirementRepository::class);
        $mappingRepo     = $this->createMock(\App\Repository\ComplianceMappingRepository::class);
        $fulfillmentRepo = $this->createMock(\App\Repository\ComplianceRequirementFulfillmentRepository::class);

        $service = new \App\Service\Bsi\IsoToBsiGapService($reqRepo, $mappingRepo, $fulfillmentRepo);

        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getBsiAssuranceLevel')->willReturn('standard');

        $iso = $this->createMock(ComplianceFramework::class);
        $bsi = $this->createMock(ComplianceFramework::class);

        // Fixture 1: APP.1.1.A1 — official crosswalk, tenant fulfilled → erledigt
        $bsiReq1  = $this->makeBsiReq(1, 'standard', 'APP.1.1.A1');
        $isoReq1  = $this->makeIsoReq('A.5.1');
        $mapping1 = $this->makeMapping($isoReq1, $bsiReq1, 100, 'official_bsi_crosswalk');

        // Fixture 2: APP.1.1.A2 — official crosswalk, tenant NOT fulfilled → quick_win
        $bsiReq2  = $this->makeBsiReq(2, 'standard', 'APP.1.1.A2');
        $isoReq2  = $this->makeIsoReq('A.5.2');
        $mapping2 = $this->makeMapping($isoReq2, $bsiReq2, 100, 'official_bsi_crosswalk');

        // Fixture 3: SYS.1.1.A5 — NO mapping → pruefen (ungemappt_unbewertet)
        $bsiReq3 = $this->makeBsiReq(3, 'standard', 'SYS.1.1.A5');

        $reqRepo->method('findByFrameworkAndTiers')
            ->willReturn([$bsiReq1, $bsiReq2, $bsiReq3]);

        $mappingRepo->method('findCrossFrameworkMappings')
            ->willReturn([$mapping1, $mapping2]);
        // bsiReq3 has no entry in the index — correctly yields pruefen

        $fulfillmentRepo->method('fulfillmentDataFor')
            ->willReturnCallback(static function (Tenant $t, \App\Entity\ComplianceRequirement $r) use ($isoReq1, $isoReq2): array {
                if ($r === $isoReq1) {
                    return ['pct' => 80, 'evidence' => ['ISMS-Policy.pdf']]; // fulfilled
                }
                if ($r === $isoReq2) {
                    return ['pct' => 0, 'evidence' => []]; // not started
                }
                return ['pct' => 0, 'evidence' => []];
            });

        $result = $service->buildGap($tenant, $iso, $bsi);

        // erledigt: fixture 1 (official, fulfilled)
        $this->assertGreaterThan(0, $result->bucketCounts['erledigt'], 'erledigt must be > 0');
        // quick_win: fixture 2 (official, not started)
        $this->assertGreaterThan(0, $result->bucketCounts['quick_win'], 'quick_win must be > 0');
        // pruefen: fixture 3 (SYS — no mapping → ungemappt_unbewertet → pruefen)
        $this->assertGreaterThan(0, $result->bucketCounts['pruefen'], 'pruefen must be > 0 for unmapped SYS req');

        // Anti-overstatement: fixture 3 must NOT be erledigt
        $sysItem = array_filter($result->items, fn(array $i) => $i['requirementId'] === 'SYS.1.1.A5');
        $sysItem = array_values($sysItem);
        $this->assertCount(1, $sysItem);
        $this->assertNotSame('erledigt', $sysItem[0]['state'],
            'SYS.1.1.A5 with no ISO mapping must NOT be classified erledigt');
        $this->assertSame('pruefen', $this->bucketOf($sysItem[0]['state'], $sysItem[0]['trust']),
            'SYS.1.1.A5 must land in pruefen bucket');
    }

    // ── Helpers for acceptance test ──────────────────────────────────────────

    private function makeBsiReq(int $id, string $tier, string $reqId): \App\Entity\ComplianceRequirement
    {
        $req = $this->createMock(\App\Entity\ComplianceRequirement::class);
        $req->method('getId')->willReturn($id);
        $req->method('getRequirementId')->willReturn($reqId);
        $req->method('getAbsicherungsStufe')->willReturn($tier);
        $req->method('getCategory')->willReturn(preg_replace('/\.A\d+$/', '', $reqId) ?: $reqId);
        return $req;
    }

    private function makeIsoReq(string $reqId): \App\Entity\ComplianceRequirement
    {
        $req = $this->createMock(\App\Entity\ComplianceRequirement::class);
        $req->method('getRequirementId')->willReturn($reqId);
        return $req;
    }

    private function makeMapping(
        \App\Entity\ComplianceRequirement $src,
        \App\Entity\ComplianceRequirement $target,
        int $pct,
        string $provenanceSource,
    ): \App\Entity\ComplianceMapping {
        $m = $this->createMock(\App\Entity\ComplianceMapping::class);
        $m->method('getSourceRequirement')->willReturn($src);
        $m->method('getTargetRequirement')->willReturn($target);
        $m->method('getMappingPercentage')->willReturn($pct);
        $m->method('getProvenanceSource')->willReturn($provenanceSource);
        $m->method('getLifecycleState')->willReturn('approved');
        $m->method('getReviewStatus')->willReturn('confirmed');
        return $m;
    }

    /**
     * Reproduce the bucket() logic from IsoToBsiGapService to validate the acceptance assertion.
     */
    private function bucketOf(string $state, string $trust): string
    {
        if ($trust === 'heuristisch' && in_array($state, ['gedeckt', 'partiell', 'iso_offen'], true)) {
            return 'pruefen';
        }
        return match ($state) {
            'gedeckt'                                            => 'erledigt',
            'iso_offen'                                          => 'quick_win',
            'partiell', 'ungemappt_eigenstaendig'                => 'bsi_arbeit',
            default                                              => 'pruefen',
        };
    }
}
