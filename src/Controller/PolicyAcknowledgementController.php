<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\TenantContext;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * HTTP surface for the per-user PolicyAcknowledgement collection flow
 * (W3-L). Closes the auditor's predicted ISO 27001 A.6.3 NC.
 *
 * Routes:
 *   GET  /policy-ack/inbox            inbox of pending policies for the user
 *   GET  /policy-ack/view/{id}        full policy text + ack button
 *   POST /policy-ack/acknowledge/{id} record the acknowledgement
 *
 * The controller is mounted under the global `/{_locale}` prefix
 * declared in `config/routes.yaml` — no need to repeat the placeholder
 * here.
 */
#[Route('/policy-ack', name: 'app_policy_ack_')]
#[IsGranted('ROLE_USER')]
final class PolicyAcknowledgementController extends AbstractController
{
    public function __construct(
        private readonly PolicyAcknowledgementService $service,
        private readonly DocumentRepository $documentRepository,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/inbox', name: 'inbox', methods: ['GET'])]
    public function inbox(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $pending = $this->service->pendingDocumentsForUser($user);

        return $this->render('policy_acknowledgement/inbox.html.twig', [
            'pending_documents' => $pending,
            'pending_count' => count($pending),
        ]);
    }

    #[Route('/view/{documentId}', name: 'view', methods: ['GET'], requirements: ['documentId' => '\d+'])]
    public function view(int $documentId): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->loadAuthorisedDocument($documentId);
        $coverage = $this->service->coverageFor($document);
        $alreadyAcknowledged = !$this->isUserPendingFor($user, $document);

        return $this->render('policy_acknowledgement/view.html.twig', [
            'document' => $document,
            'coverage' => $coverage,
            'already_acknowledged' => $alreadyAcknowledged,
        ]);
    }

    #[Route('/acknowledge/{documentId}', name: 'acknowledge', methods: ['POST'], requirements: ['documentId' => '\d+'])]
    #[IsCsrfTokenValid('policy_ack', tokenKey: '_token')]
    public function acknowledge(int $documentId, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $document = $this->loadAuthorisedDocument($documentId);

        try {
            $this->service->acknowledge(
                document: $document,
                user: $user,
                method: PolicyAcknowledgementService::METHOD_WEB_CLICK,
                ipAddress: $request->getClientIp(),
            );
            $this->addFlash(
                'success',
                $this->translator->trans('policy_ack.message.acknowledged_success', [], 'policy_wizard'),
            );
        } catch (RuntimeException) {
            // Already acknowledged — silently keep the user on the inbox
            // page. No flash; the inbox will show the doc as gone.
        }

        return $this->redirectToRoute('app_policy_ack_inbox');
    }

    private function loadAuthorisedDocument(int $documentId): Document
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException();
        }
        $document = $this->documentRepository->find($documentId);
        if (!$document instanceof Document) {
            throw $this->createNotFoundException();
        }
        $documentTenant = $document->getTenant();
        if (!$documentTenant instanceof Tenant
            || $documentTenant->getId() !== $tenant->getId()
        ) {
            throw $this->createAccessDeniedException();
        }
        if (!in_array($document->getStatus(), ['published', 'approved'], true)) {
            throw $this->createNotFoundException();
        }
        return $document;
    }

    private function isUserPendingFor(User $user, Document $document): bool
    {
        foreach ($this->service->pendingDocumentsForUser($user) as $pending) {
            if ($pending->getId() === $document->getId()) {
                return true;
            }
        }
        return false;
    }
}
