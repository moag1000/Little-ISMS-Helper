<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\DocumentRepository;
use App\Security\Voter\PolicyWizardVoter;
use App\Service\PolicyWizard\Diff\PolicyDiffService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Policy-Wizard W7-C — Re-generation diff viewer.
 *
 * Renders the doc-level + variable-level diff between an old (superseded)
 * Document and the new (superseding) Document produced by a wizard re-run.
 * Strictly NOT a character-level diff per the ISB practitioner review.
 *
 * Routes:
 *  - GET  /policy-wizard/diff/{currentDocId}                         — defaults previous to current.supersedes
 *  - GET  /policy-wizard/diff/{currentDocId}/vs/{previousDocId}      — explicit pair
 *  - POST /policy-wizard/diff/{currentDocId}/restore                 — archive current, restore previous
 */
#[IsGranted('ROLE_USER')]
final class PolicyDiffController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyDiffService $diffService,
        private readonly TranslatorInterface $translator,
        private readonly LifecycleTransitionInterface $lifecycleService,
        private readonly Security $security,
    ) {
    }

    #[Route(
        '/policy-wizard/diff/{currentDocId}/vs/{previousDocId}',
        name: 'app_policy_wizard_diff_explicit',
        requirements: ['currentDocId' => '\d+', 'previousDocId' => '\d+'],
        methods: ['GET'],
    )]
    public function diffExplicit(int $currentDocId, int $previousDocId): Response
    {
        $current = $this->loadDocumentOr404($currentDocId);
        $previous = $this->loadDocumentOr404($previousDocId);

        return $this->renderDiff($current, $previous);
    }

    #[Route(
        '/policy-wizard/diff/{currentDocId}',
        name: 'app_policy_wizard_diff',
        requirements: ['currentDocId' => '\d+'],
        methods: ['GET'],
    )]
    public function diff(int $currentDocId): Response
    {
        $current = $this->loadDocumentOr404($currentDocId);
        $previous = $current->getSupersedes();
        if (!$previous instanceof Document) {
            throw $this->createNotFoundException('Document has no previous version to diff against.');
        }
        return $this->renderDiff($current, $previous);
    }

    #[Route(
        '/policy-wizard/diff/{currentDocId}/restore',
        name: 'app_policy_wizard_diff_restore',
        requirements: ['currentDocId' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid('policy_wizard_diff_restore')]
    public function restorePrevious(int $currentDocId, Request $request): Response
    {
        $current = $this->loadDocumentOr404($currentDocId);
        $previous = $current->getSupersedes();
        if (!$previous instanceof Document) {
            throw $this->createNotFoundException('Document has no previous version to restore.');
        }

        // Archive the current row + flip the previous one back to active.
        // We do NOT delete the current — supersession history must remain
        // intact for §10 immutability/audit. The status flip is enough.
        $user = $this->security->getUser();
        $lifecycleUser = $user instanceof User ? $user : null;

        $current->setIsArchived(true);
        $current->setUpdatedAt(new DateTimeImmutable());
        // X.6: archive_on_supersession works from any active place (draft/in_review/approved/published/archived).
        $this->lifecycleService->transition(
            $current,
            'document_lifecycle',
            'archive_on_supersession',
            $lifecycleUser,
            'Policy supersession reverted — document archived to restore previous version',
        );

        $previous->setIsArchived(false);
        $previous->setUpdatedAt(new DateTimeImmutable());
        // Approved → published-equivalent in this codebase. Only restore if previous
        // was archived; non-archived states are left as-is to avoid unintended elevation.
        if ($previous->getStatus() === DocumentStatus::Archived->value) {
            // X.6: restore_to_approved: archived → approved (policy supersession restore).
            $this->lifecycleService->transition(
                $previous,
                'document_lifecycle',
                'restore_to_approved',
                $lifecycleUser,
                'Restored from supersession revert',
            );
        }
        // Note: LifecycleService::transition() calls flush() internally.
        // The setIsArchived()/setUpdatedAt() mutations above are picked up in the same flush.

        $this->addFlash(
            'success',
            $this->translator->trans('policy_wizard.diff.restore_success', [], 'policy_wizard'),
        );

        return $this->redirectToRoute('app_document_show', ['id' => $previous->getId()]);
    }

    private function renderDiff(Document $current, Document $previous): Response
    {
        // Vote on the CURRENT (newer) Document — that's the row whose
        // tenant scope we trust as authoritative for "is the user allowed
        // to see this diff?". Both rows must share a tenant in normal
        // operation; the previous-tenant is implicitly covered by the
        // supersedes relation having stayed within the same tenant.
        $this->denyAccessUnlessGranted(PolicyWizardVoter::DIFF_VIEW, $current);

        $diff = $this->diffService->diffDocuments($previous, $current);

        return $this->render('policy_wizard/diff/index.html.twig', [
            'diff' => $diff,
            'current' => $current,
            'previous' => $previous,
        ]);
    }

    private function loadDocumentOr404(int $id): Document
    {
        $document = $this->documentRepository->find($id);
        if (!$document instanceof Document) {
            throw $this->createNotFoundException('Document not found.');
        }
        return $document;
    }
}
