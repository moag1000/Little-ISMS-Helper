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

    /** BSI IT-Grundschutz 2024 framework code in the DB */
    private const BSI_CODE = 'BSI_GRUNDSCHUTZ';

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
    #[Route('/{_locale}/compliance/cross-gap', name: 'app_compliance_cross_gap', methods: ['GET'])]
    public function crossGap(): Response
    {
        if ($redirect = $this->checkModuleActive('bsi_grundschutz')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        $iso = $this->frameworkRepo->findOneBy(['code' => self::ISO_CODE]);
        $bsi = $this->frameworkRepo->findOneBy(['code' => self::BSI_CODE]);

        if ($iso === null || $bsi === null) {
            // Frameworks not imported yet — render a graceful empty state
            return $this->render('bsi_grundschutz_check/cross_gap.html.twig', [
                'result'        => null,
                'level'         => $tenant->getBsiAssuranceLevel(),
                'pendingReview' => 0,
            ]);
        }

        $result        = $this->gapService->buildGap($tenant, $iso, $bsi);
        $pendingReview = $this->inheritance->getPendingReviewCount($tenant, $bsi);

        return $this->render('bsi_grundschutz_check/cross_gap.html.twig', [
            'result'        => $result,
            'level'         => $tenant->getBsiAssuranceLevel(),
            'pendingReview' => $pendingReview,
            'bsiCode'       => self::BSI_CODE,
        ]);
    }

    /**
     * Set the tenant's BSI assurance level (basis / standard / kern).
     *
     * PRG-redirect back to the gap view after success or flash-error.
     */
    #[Route('/{_locale}/compliance/cross-gap/level', name: 'app_compliance_bsi_level', methods: ['POST'])]
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
        ]);
    }
}
