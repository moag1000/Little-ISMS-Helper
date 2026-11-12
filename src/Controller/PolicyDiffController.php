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
 * Policy-Wizard — Re-generation diff viewer.
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
        private readonly \App\Lifecycle\FourEyes\LifecycleCountersign $countersign,
    ) {
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
    #[IsGranted('ROLE_MANAGER')]
    public function restorePrevious(int $currentDocId, Request $request): Response
    {
        $current = $this->loadDocumentOr404($currentDocId);
        $previous = $current->getSupersedes();
        if (!$previous instanceof Document) {
            throw $this->createNotFoundException('Document has no previous version to restore.');
        }

        // S34 (R4): the same right as seeing the diff (tenant scope), and the person says why.
        $this->denyAccessUnlessGranted(PolicyWizardVoter::DIFF_VIEW, $current);
        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason === '') {
            $this->addFlash('danger', $this->translator->trans('policy_wizard.diff.restore_reason_required', [], 'policy_wizard'));

            return $this->redirectToRoute('app_policy_wizard_diff', ['currentDocId' => $current->getId()]);
        }
        if ($previous->getStatus() !== DocumentStatus::Archived->value) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.diff.restore_not_archived', [], 'policy_wizard'));

            return $this->redirectToRoute('app_policy_wizard_diff', ['currentDocId' => $current->getId()]);
        }

        // The live version is not archived here: one person alone neither takes a policy out
        // of force nor puts an archived one back. The previous version returns via
        // restore_to_approved, which needs the second person ("Mein Tag" / four-eyes inbox);
        // archiving the current version stays its own step (archive, with reason).
        $user = $this->security->getUser();
        $actor = $user instanceof User ? $user : null;
        try {
            if ($actor instanceof User && $this->countersign->needsCountersign($previous, 'document_lifecycle', 'restore_to_approved', $actor)) {
                $this->countersign->request($previous, 'document', 'document_lifecycle', 'restore_to_approved', $actor, mb_substr($reason, 0, 1000));
                $this->addFlash('info', $this->translator->trans('policy_wizard.diff.restore_pending', [], 'policy_wizard'));

                return $this->redirectToRoute('app_document_show', ['id' => $previous->getId()]);
            }
            // Single-approver escape (nobody else could confirm): applied at once; the flush of
            // the transition also writes the flags set here.
            $previous->setIsArchived(false);
            $previous->setUpdatedAt(new DateTimeImmutable());
            $this->lifecycleService->transition($previous, 'document_lifecycle', 'restore_to_approved', $actor, mb_substr($reason, 0, 1000));
        } catch (\App\Exception\AppException|\App\Lifecycle\Exception\TransitionAccessDeniedException|\App\Lifecycle\Exception\FourEyesRequiredException|\App\Lifecycle\InvalidTransitionException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_policy_wizard_diff', ['currentDocId' => $current->getId()]);
        }
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
