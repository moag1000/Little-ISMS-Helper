<?php

declare(strict_types=1);

namespace App\Controller\Authority;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Authority\Nis2RegistrationProfile;
use App\Form\Authority\Nis2RegistrationProfileType;
use App\Security\Voter\Authority\Nis2RegistrationProfileVoter;
use App\Service\AuditLogger;
use App\Service\Authority\Nis2BsiRegistrationService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F29 — NIS-2 BSI-Portal Yearly Re-Registration Controller.
 *
 * Routes:
 *  GET  /authority/nis2-registration         — index: profile overview
 *  GET  /authority/nis2-registration/edit    — edit form
 *  POST /authority/nis2-registration/edit    — save form
 *  GET  /authority/nis2-registration/export  — download BSI-Portal JSON
 *  POST /authority/nis2-registration/mark-reported — admin confirms BSI submission
 *
 * Module gate: nis2_dora
 * RBAC: ROLE_MANAGER for VIEW/EDIT/EXPORT; ROLE_ADMIN for MARK_REPORTED
 */
#[Route('/authority/nis2-registration', name: 'nis2_registration_')]
#[IsGranted('ROLE_MANAGER')]
final class Nis2RegistrationController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly Nis2BsiRegistrationService $registrationService,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $profile = $this->registrationService->getOrCreateProfile($tenant);
        $this->denyAccessUnlessGranted(Nis2RegistrationProfileVoter::VIEW, $profile);

        $validationErrors = $this->registrationService->validate($profile);

        return $this->render('authority/nis2_registration/index.html.twig', [
            'profile' => $profile,
            'validationErrors' => $validationErrors,
            'isComplete' => $validationErrors === [],
        ]);
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    #[Route('/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $profile = $this->registrationService->getOrCreateProfile($tenant);
        $this->denyAccessUnlessGranted(Nis2RegistrationProfileVoter::EDIT, $profile);

        $form = $this->createForm(Nis2RegistrationProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->auditLogger->logCustom(
                action: AuditLogger::ACTION_NIS2_REGISTRATION_UPDATED,
                entityType: 'Nis2RegistrationProfile',
                entityId: $profile->getId(),
                description: sprintf(
                    'NIS-2 registration profile updated by %s',
                    $this->getUser()?->getUserIdentifier() ?? 'unknown'
                ),
            );

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'eu_authorities.nis2_registration.success.saved',
                [],
                'eu_authorities'
            ));

            return $this->redirectToRoute('nis2_registration_index');
        }

        return $this->render('authority/nis2_registration/edit.html.twig', [
            'profile' => $profile,
            'form' => $form,
        ]);
    }

    // ─── JSON Export ──────────────────────────────────────────────────────────

    #[Route('/export', name: 'export_json', methods: ['GET'])]
    public function exportJson(): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $profile = $this->registrationService->getOrCreateProfile($tenant);
        $this->denyAccessUnlessGranted(Nis2RegistrationProfileVoter::EXPORT, $profile);

        $errors = $this->registrationService->validate($profile);
        if ($errors !== []) {
            $this->addFlash('danger', $this->translator->trans(
                'eu_authorities.nis2_registration.error.profile_incomplete',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('nis2_registration_index');
        }

        $json = $this->registrationService->exportToJson($profile);

        $this->auditLogger->logCustom(
            action: AuditLogger::ACTION_NIS2_REGISTRATION_EXPORTED,
            entityType: 'Nis2RegistrationProfile',
            entityId: $profile->getId(),
            description: sprintf(
                'NIS-2 registration profile exported as JSON by %s',
                $this->getUser()?->getUserIdentifier() ?? 'unknown'
            ),
        );

        $filename = sprintf(
            'nis2-registration-%s-%s.json',
            preg_replace('/[^a-z0-9]+/i', '-', $tenant->getName() ?? 'export'),
            (new DateTimeImmutable())->format('Ymd')
        );

        return new Response(
            content: $json,
            status: 200,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"',
            ]
        );
    }

    // ─── Mark Reported ────────────────────────────────────────────────────────

    #[Route('/mark-reported', name: 'mark_reported', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('nis2_mark_reported')]
    public function markReported(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $profile = $this->registrationService->getOrCreateProfile($tenant);
        $this->denyAccessUnlessGranted(Nis2RegistrationProfileVoter::MARK_REPORTED, $profile);

        $confirmationNumber = trim((string) $request->request->get('confirmation_number', ''));

        if ($confirmationNumber === '') {
            $this->addFlash('danger', $this->translator->trans(
                'eu_authorities.nis2_registration.error.confirmation_number_required',
                [],
                'eu_authorities'
            ));
            return $this->redirectToRoute('nis2_registration_index');
        }

        $this->registrationService->markReported($profile, $confirmationNumber);

        $this->addFlash('success', $this->translator->trans(
            'eu_authorities.nis2_registration.success.reported',
            ['%number%' => $confirmationNumber],
            'eu_authorities'
        ));

        return $this->redirectToRoute('nis2_registration_index');
    }

}

