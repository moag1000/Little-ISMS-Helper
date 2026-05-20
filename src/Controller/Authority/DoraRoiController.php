<?php

declare(strict_types=1);

namespace App\Controller\Authority;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\User;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use App\Security\Voter\Authority\DoraRoiVoter;
use App\Service\AuditLogger;
use App\Service\Authority\DoraRoiXbrlExporter;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F30 — DORA Register of Information (RoI) Controller.
 *
 * Routes:
 *  GET  /authority/dora-roi          — index: list past submissions + generate button
 *  POST /authority/dora-roi/generate — run XBRL exporter, return XML download
 *  POST /authority/dora-roi/{id}/mark-submitted — admin records confirmation number
 *
 * Module gate: nis2_dora
 * RBAC: ROLE_MANAGER for VIEW/EXPORT; ROLE_ADMIN for MARK_SUBMITTED
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/authority/dora-roi', name: 'dora_roi_')]
#[IsGranted('ROLE_MANAGER')]
class DoraRoiController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly DoraRoiXbrlExporter $exporter,
        private readonly DoraRegisterOfInformationRepository $roiRepository,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        // Tenant-level DORA gate: non-DORA-obligated tenants cannot access DORA RoI.
        if (!$tenant->isDoraObligated()) {
            $this->addFlash('info', $this->translator->trans(
                'dora.not_applicable_to_tenant',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        $submissions = $this->roiRepository->findAllForTenant($tenant);
        $currentYear = $this->roiRepository->findCurrentYearForTenant($tenant);

        return $this->render('authority/dora_roi/index.html.twig', [
            'submissions' => $submissions,
            'currentYear' => $currentYear,
            'reportingYear' => (int) (new DateTimeImmutable())->format('Y'),
        ]);
    }

    // ─── Generate XBRL ───────────────────────────────────────────────────────

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    #[IsCsrfTokenValid('dora_roi_generate')]
    public function generate(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        if (!$tenant->isDoraObligated()) {
            $this->addFlash('info', $this->translator->trans(
                'dora.not_applicable_to_tenant',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        // Create a dummy record for voter check (tenant-isolated, not yet persisted)
        $record = new DoraRegisterOfInformation();
        $record->setTenant($tenant);
        $this->denyAccessUnlessGranted(DoraRoiVoter::EXPORT, $record);

        $xbrlXml = $this->exporter->generate($tenant);
        $payloadHash = $this->exporter->computePayloadHash($xbrlXml);

        $reportingDate = new DateTimeImmutable();

        // Upsert: find or create this year's record
        $roiRecord = $this->roiRepository->findCurrentYearForTenant($tenant);

        if ($roiRecord === null) {
            $roiRecord = new DoraRegisterOfInformation();
            $roiRecord->setTenant($tenant);
            $roiRecord->setReportingDate(new DateTimeImmutable(
                $reportingDate->format('Y') . '-12-31'
            ));
            $this->entityManager->persist($roiRecord);
        }

        $roiRecord->setPayloadHash($payloadHash);
        $roiRecord->setReportingScope(DoraRegisterOfInformation::SCOPE_YEARLY_FULL);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: AuditLogger::ACTION_DORA_ROI_EXPORTED,
            entityType: 'DoraRegisterOfInformation',
            entityId: $roiRecord->getId(),
            description: sprintf(
                'DORA RoI XBRL exported by %s (hash: %s)',
                $this->getUser()?->getUserIdentifier() ?? 'unknown',
                substr($payloadHash, 0, 12) . '…'
            ),
        );

        $filename = sprintf(
            'DORA-RoI-%s-%s.xbrl',
            preg_replace('/[^a-z0-9]+/i', '-', $tenant->getName() ?? 'export'),
            $reportingDate->format('Ymd')
        );

        return new Response($xbrlXml, Response::HTTP_OK, [
            'Content-Type'        => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
        ]);
    }

    // ─── Mark Submitted ───────────────────────────────────────────────────────

    #[Route('/{id}/mark-submitted', name: 'mark_submitted', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('dora_roi_mark_submitted_{id}')]
    public function markSubmitted(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        if (!$tenant->isDoraObligated()) {
            $this->addFlash('info', $this->translator->trans(
                'dora.not_applicable_to_tenant',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('app_dashboard', ['_locale' => $request->getLocale()]);
        }

        $record = $this->roiRepository->find($id);
        if ($record === null || $record->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('DORA RoI record not found.');
        }

        $this->denyAccessUnlessGranted(DoraRoiVoter::MARK_SUBMITTED, $record);

        $confirmationNumber = trim((string) $request->request->get('confirmation_number', ''));
        if ($confirmationNumber === '') {
            $this->addFlash('danger', $this->translator->trans(
                'eu_authorities.dora_roi.error.confirmation_required',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('dora_roi_index');
        }

        $record->setSubmittedAt(new DateTimeImmutable());
        $record->setSubmittedBy($user);
        $record->setConfirmationNumber($confirmationNumber);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: AuditLogger::ACTION_DORA_ROI_SUBMITTED,
            entityType: 'DoraRegisterOfInformation',
            entityId: $record->getId(),
            description: sprintf(
                'DORA RoI marked as submitted by %s — confirmation: %s',
                $user->getUserIdentifier(),
                $confirmationNumber
            ),
        );

        $this->addFlash('success', $this->translator->trans(
            'eu_authorities.dora_roi.success.submitted',
            ['%number%' => $confirmationNumber],
            'eu_authorities'
        ));

        return $this->redirectToRoute('dora_roi_index');
    }
}
