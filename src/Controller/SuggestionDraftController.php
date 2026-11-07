<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Job\DraftSuggestionJob;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\Job\JobTenantScope;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\RecordDrafts;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Entwurf vorschlagen" on a detail page (P6): asks one suggestion kind for a
 * draft about one record. Rule drafts are immediate; language-model drafts
 * run as a background job with the shared progress page. Either way the
 * person lands on the queue filtered to the record and decides there.
 */
#[IsGranted('ROLE_USER')]
final class SuggestionDraftController extends AbstractController
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly RecordDrafts $drafts,
        private readonly TenantContext $tenantContext,
        private readonly AsyncJobDispatcher $asyncJobDispatcher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/suggestions/draft', name: 'app_suggestion_draft', methods: ['POST'])]
    #[IsCsrfTokenValid('suggestion_draft')]
    public function draft(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No organisation context.');
        }
        $kind = $this->queue->kind((string) $request->request->get('kind'));
        if (!$kind instanceof RecordDraftKind || !$this->queue->isKindActive($kind, $tenant)) {
            throw $this->createNotFoundException('Unknown draft kind.');
        }
        $class = (string) $request->request->get('class');
        $record = $this->drafts->resolve($kind, $class, $request->request->getInt('id'), $tenant);
        if ($record === null) {
            throw $this->createNotFoundException('Record not found.');
        }
        if (!$this->drafts->mayRequest($kind, $record, $tenant)) {
            throw $this->createAccessDeniedException();
        }
        $subjectClass = (string) (new SuggestionDraft($record, '', ''))->subjectClass();
        $queueUrl = $this->generateUrl('app_suggestion_index', ['subject' => $subjectClass . '#' . $request->request->getInt('id')]);

        if ($kind->needsModel()) {
            return $this->asyncJobDispatcher->dispatchWithProgress(
                request: $request,
                jobClass: DraftSuggestionJob::class,
                jobArgs: [
                    JobTenantScope::ARG_TENANT_ID => $tenant->getId(),
                    'kind' => $kind->key(),
                    'class' => $class,
                    'id' => $request->request->getInt('id'),
                ],
                jobName: 'suggestions.draft',
                payload: [
                    '_label' => $this->translator->trans('suggestions.draft.label', [], 'suggestions'),
                    '_subtitle' => $this->translator->trans('suggestions.kind.' . $kind->key(), [], 'suggestions'),
                ],
                returnUrl: $queueUrl,
            );
        }

        $suggestion = $this->drafts->draft($tenant, $kind, $record);
        $this->addFlash($suggestion !== null ? 'success' : 'warning', $suggestion !== null ? 'suggestions.flash.drafted' : 'suggestions.flash.no_draft');

        return $this->redirect($queueUrl);
    }
}
