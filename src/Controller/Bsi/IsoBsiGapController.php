<?php

declare(strict_types=1);

namespace App\Controller\Bsi;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Exception\InvalidArgument\InvalidArgumentException;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\ComplianceInheritanceService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ISO 27001 → BSI IT-Grundschutz gap view.
 *
 * Answers: "I did ISO — what's left for BSI?"
 * - Module-gated under `bsi_grundschutz`
 * - Two actions: read-only gap view + POST assurance-level setter
 */
#[IsGranted('ROLE_MANAGER')]
class IsoBsiGapController extends AbstractController
{
    use ModuleGatedControllerTrait;

    /** Real ISO 27001:2022 framework code in the DB */
    private const ISO_CODE = 'ISO27001';

    /**
     * Target catalogues, keyed by the `target` query value: IT-Grundschutz-Kompendium
     * (default) or Grundschutz++ (BSI-Standard 300-1). Both are fed by official BSI
     * crosswalks from ISO 27001, so the same gap logic applies.
     */
    private const TARGETS = [
        'kompendium' => 'BSI_GRUNDSCHUTZ',
        'gspp' => 'BSI-GSPP',
    ];

    public function __construct(
        private readonly IsoToBsiGapService $gapService,
        private readonly ComplianceFrameworkRepository $frameworkRepo,
        private readonly ComplianceInheritanceService $inheritance,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Render the ISO → BSI gap dashboard.
     *
     * Groups requirements into 4 action buckets (erledigt / quick_win /
     * bsi_arbeit / pruefen) and drives the "Dein nächster Schritt" block.
     */
    #[Route('/compliance/cross-gap', name: 'app_compliance_cross_gap', methods: ['GET'])]
    public function crossGap(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bsi_grundschutz')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        $target = $this->resolveTarget($request);
        $iso = $this->frameworkRepo->findOneBy(['code' => self::ISO_CODE]);
        $bsi = $this->frameworkRepo->findOneBy(['code' => self::TARGETS[$target]]);
        // Offer only catalogues that are loaded; the switch hides when just one is.
        $targets = array_keys(array_filter(
            self::TARGETS,
            fn (string $code): bool => $this->frameworkRepo->findOneBy(['code' => $code]) !== null,
        ));

        if ($iso === null || $bsi === null) {
            // Frameworks not imported yet — render a graceful empty state
            return $this->render('bsi_grundschutz_check/cross_gap.html.twig', [
                'result'        => null,
                'level'         => $tenant->getBsiAssuranceLevel(),
                'pendingReview' => 0,
                'target'        => $target,
                'targets'       => $targets,
            ]);
        }

        $result        = $this->gapService->buildGap($tenant, $iso, $bsi);
        $pendingReview = $this->inheritance->getPendingReviewCount($tenant, $bsi);

        return $this->render('bsi_grundschutz_check/cross_gap.html.twig', [
            'result'        => $result,
            'level'         => $tenant->getBsiAssuranceLevel(),
            'pendingReview' => $pendingReview,
            'bsiCode'       => self::TARGETS[$target],
            'target'        => $target,
            'targets'       => $targets,
        ]);
    }

    /**
     * Set the tenant's BSI assurance level (basis / standard / kern).
     *
     * PRG-redirect back to the gap view after success or flash-error.
     */
    #[Route('/compliance/cross-gap/level', name: 'app_compliance_bsi_level', methods: ['POST'])]
    #[IsCsrfTokenValid('bsi_level')]
    public function setLevel(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bsi_grundschutz')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        $level = (string) $request->request->get('level', '');

        try {
            $tenant->setBsiAssuranceLevel($level);
            $this->em->flush();
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'bsi.level.invalid');
        }

        return $this->redirectToRoute('app_compliance_cross_gap', [
            '_locale' => $request->getLocale(),
            'target' => $this->resolveTarget($request),
        ]);
    }

    private function resolveTarget(Request $request): string
    {
        // GET carries it in the query, the level form posts it back as a field.
        $target = (string) ($request->query->get('target') ?? $request->request->get('target', 'kompendium'));

        return array_key_exists($target, self::TARGETS) ? $target : 'kompendium';
    }
}
