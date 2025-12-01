<?php

namespace App\Controller;

use App\Entity\Consent;
use App\Entity\User;
use App\Form\ConsentType;
use App\Repository\ConsentRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/consent', requirements: ['_locale' => 'de|en'])]
class ConsentController extends AbstractController
{
    public function __construct(
        private readonly ConsentRepository $consentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_consent_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Get filter parameters
        $status = $request->query->get('status');
        $verified = $request->query->get('verified');
        $processingActivityId = $request->query->get('processingActivity');

        // Build query
        $qb = $this->consentRepository->createQueryBuilder('c')
            ->leftJoin('c.processingActivity', 'pa')
            ->addSelect('pa');

        if ($tenant) {
            $qb->where('c.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        if ($verified !== null) {
            $qb->andWhere('c.isVerifiedByDpo = :verified')
                ->setParameter('verified', $verified === '1');
        }

        if ($processingActivityId) {
            $qb->andWhere('c.processingActivity = :pa_id')
                ->setParameter('pa_id', $processingActivityId);
        }

        $consents = $qb->orderBy('c.documentedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Get statistics for dashboard
        $statistics = $this->consentRepository->getStatistics($tenant);

        return $this->render('consent/index.html.twig', [
            'consents' => $consents,
            'statistics' => $statistics,
            'current_status' => $status,
            'current_verified' => $verified,
            'current_processing_activity' => $processingActivityId,
        ]);
    }

    #[Route('/new', name: 'app_consent_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $consent = new Consent();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant) {
            $consent->setTenant($tenant);
        }

        $form = $this->createForm(ConsentType::class, $consent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentUser = $this->security->getUser();

            if ($currentUser instanceof User) {
                // Set documented by current user
                $consent->setDocumentedBy($currentUser);
                $consent->setDocumentedAt(new DateTimeImmutable());

                // Auto-verify if user has DPO role
                if ($this->isGranted('ROLE_DPO')) {
                    $consent->setIsVerifiedByDpo(true);
                    $consent->setVerifiedBy($currentUser);
                    $consent->setVerifiedAt(new DateTimeImmutable());
                    $consent->setStatus('active');
                } else {
                    $consent->setStatus('pending_verification');
                }

                $this->entityManager->persist($consent);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('consent.success.created', [], 'consent'));
                return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
            }
        }

        return $this->render('consent/form.html.twig', [
            'consent' => $consent,
            'form' => $form,
            'is_edit' => false,
        ]);
    }

    #[Route('/{id}', name: 'app_consent_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Consent $consent): Response
    {
        return $this->render('consent/show.html.twig', [
            'consent' => $consent,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_consent_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Consent $consent): Response
    {
        // Only allow editing if pending or active
        if (!in_array($consent->getStatus(), ['pending_verification', 'active'], true)) {
            $this->addFlash('error', $this->translator->trans('consent.error.cannot_edit_status', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        $form = $this->createForm(ConsentType::class, $consent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $consent->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('consent.success.updated', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        return $this->render('consent/form.html.twig', [
            'consent' => $consent,
            'form' => $form,
            'is_edit' => true,
        ]);
    }

    #[Route('/{id}/verify', name: 'app_consent_verify', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_DPO')]
    public function verify(Request $request, Consent $consent): Response
    {
        if (!$this->isCsrfTokenValid('verify' . $consent->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('consent.error.invalid_token', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        if ($consent->isVerifiedByDpo()) {
            $this->addFlash('warning', $this->translator->trans('consent.warning.already_verified', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        $currentUser = $this->security->getUser();

        if ($currentUser instanceof User) {
            $consent->setIsVerifiedByDpo(true);
            $consent->setVerifiedBy($currentUser);
            $consent->setVerifiedAt(new DateTimeImmutable());
            $consent->setStatus('active');
            $consent->setUpdatedAt(new DateTimeImmutable());

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('consent.success.verified', [], 'consent'));
        }

        return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
    }

    #[Route('/{id}/revoke', name: 'app_consent_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function revoke(Request $request, Consent $consent): Response
    {
        if (!$this->isCsrfTokenValid('revoke' . $consent->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('consent.error.invalid_token', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        if ($consent->isRevoked()) {
            $this->addFlash('warning', $this->translator->trans('consent.warning.already_revoked', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        $currentUser = $this->security->getUser();

        if ($currentUser instanceof User) {
            $revocationMethod = $request->request->get('revocation_method', 'other');
            $revocationNotes = $request->request->get('revocation_notes', '');

            $consent->setIsRevoked(true);
            $consent->setRevokedAt(new DateTimeImmutable());
            $consent->setRevocationMethod($revocationMethod);
            $consent->setRevocationDocumentedBy($currentUser);
            $consent->setStatus('revoked');
            $consent->setUpdatedAt(new DateTimeImmutable());

            // Append revocation notes
            if ($revocationNotes) {
                $existingNotes = $consent->getNotes() ?? '';
                $newNotes = sprintf(
                    "%s\n\n[%s] Widerruf dokumentiert von %s %s\nMethode: %s\n%s",
                    $existingNotes,
                    (new DateTimeImmutable())->format('Y-m-d H:i'),
                    $currentUser->getFirstName(),
                    $currentUser->getLastName(),
                    $revocationMethod,
                    $revocationNotes
                );
                $consent->setNotes($newNotes);
            }

            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('consent.success.revoked', [], 'consent'));
        }

        return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_consent_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_DPO')]
    public function delete(Request $request, Consent $consent): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $consent->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('consent.error.invalid_token', [], 'consent'));
            return $this->redirectToRoute('app_consent_show', ['id' => $consent->getId()]);
        }

        $this->entityManager->remove($consent);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('consent.success.deleted', [], 'consent'));
        return $this->redirectToRoute('app_consent_index');
    }
}
