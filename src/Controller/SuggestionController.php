<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\BulkActionTrait;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Job\RefreshSuggestionsJob;
use App\Repository\SuggestionRepository;
use App\Service\AuditLogger;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\Job\JobTenantScope;
use App\Security\Voter\ControlVoter;
use App\Service\Suggestion\Kind\ControlRequirementLinkKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionLinks;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\TenantContext;
use App\Util\ListPage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The suggestion queue (Paket 5): everything the tool proposes — derived from
 * the organisation's data, inherited, imported or written by a language
 * model — in one list. Each person sees what they may decide; reading roles
 * (auditors, managers) see the rest read-only, suggestions about records of
 * people only with the DPO role ({@see SuggestionQueue::isVisible()}, S30).
 */
// @no-methods-required — class-level path prefix, methods declared per action
// @no-module-gate-required: generic queue: every SuggestionKind names its module and target access
#[Route('/suggestions', name: 'app_suggestion_')]
#[IsGranted('ROLE_USER')]
final class SuggestionController extends AbstractController
{
    use BulkActionTrait;

    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly SuggestionRepository $repository,
        private readonly SuggestionLinks $links,
        private readonly TenantContext $tenantContext,
        private readonly AsyncJobDispatcher $asyncJobDispatcher,
        private readonly JobTenantScope $jobTenantScope,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $view = $request->query->get('view') === 'decided' ? 'decided' : 'open';
        $kindFilter = (string) $request->query->get('kind', '');
        $originFilter = (string) $request->query->get('origin', '');
        $subject = (string) $request->query->get('subject', '');

        $items = $this->queue->visible(
            $view === 'decided' ? $this->repository->findDecided($tenant) : $this->repository->findPending($tenant),
        );

        $kindCounts = [];
        foreach ($items as $s) {
            $kindCounts[$s->getKind()] = ($kindCounts[$s->getKind()] ?? 0) + 1;
        }
        $items = array_values(array_filter($items, static function (Suggestion $s) use ($kindFilter, $originFilter, $subject): bool {
            return ($kindFilter === '' || $s->getKind() === $kindFilter)
                && ($originFilter === '' || $s->getOrigin()->value === $originFilter)
                && ($subject === '' || $s->getSubjectClass() . '#' . $s->getSubjectId() === $subject);
        }));

        $page = ListPage::fromRequest(
            $items,
            $request,
            static fn (Suggestion $s): array => [$s->getTitle(), $s->getRationale(), (string) $s->getEditableText()],
        );
        $rows = [];
        foreach ($page as $s) {
            $kind = $this->queue->kind($s->getKind());
            $rows[(int) $s->getId()] = [
                'subject_url' => $this->links->subject($s),
                'result_url' => $this->links->result($s),
                'can_decide' => $s->isPending() && $this->queue->canDecide($s),
                'bulk' => $kind?->allowsBulk() ?? false,
            ];
        }

        return $this->render('suggestion/index.html.twig', [
            'page' => $page,
            'rows' => $rows,
            'view' => $view,
            'kind_filter' => $kindFilter,
            'origin_filter' => $originFilter,
            'kind_counts' => $kindCounts,
            'origins' => SuggestionOrigin::cases(),
            'any_bulk' => array_filter($rows, static fn (array $r): bool => $r['can_decide'] && $r['bulk']) !== [],
        ]);
    }

    #[Route('/{id}/accept', name: 'accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"suggestion_decide_" ~ args["suggestion"].getId()'))]
    public function accept(Suggestion $suggestion, Request $request): Response
    {
        $this->assertDecidable($suggestion);
        $text = $request->request->get('text');
        try {
            $this->queue->accept(
                $suggestion,
                $this->user(),
                is_string($text) ? $text : null,
                (string) $request->request->get('note', '') ?: null,
            );
            $this->addFlash('success', 'suggestions.flash.accepted');
        } catch (StaleSuggestionException) {
            $this->addFlash('warning', 'suggestions.flash.stale');
        } catch (BusinessRuleException $e) {
            $this->addFlash('danger', $this->translator->trans('suggestions.flash.rule_failed', ['%reason%' => $e->getMessage()], 'suggestions'));
        }

        return $this->backToList($request);
    }

    #[Route('/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"suggestion_decide_" ~ args["suggestion"].getId()'))]
    public function reject(Suggestion $suggestion, Request $request): Response
    {
        $this->assertDecidable($suggestion);
        try {
            $this->queue->reject($suggestion, $this->user(), (string) $request->request->get('reason', '') ?: null);
            $this->addFlash('success', 'suggestions.flash.rejected');
        } catch (StaleSuggestionException) {
            $this->addFlash('warning', 'suggestions.flash.stale');
        } catch (BusinessRuleException $e) {
            $this->addFlash('danger', $this->translator->trans('suggestions.flash.rule_failed', ['%reason%' => $e->getMessage()], 'suggestions'));
        }

        return $this->backToList($request);
    }

    /**
     * Bulk decision from the bulk-actions bar: newStatus "approved" = accept,
     * "rejected" = reject. Only kinds that allow it (allowsBulk); the rest is
     * reported back as rejected rows.
     */
    #[Route('/bulk-status-change', name: 'bulk_status_change', methods: ['POST'])]
    public function bulkStatusChange(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?: [];
            if (!$this->isBulkCsrfTokenValid($request, $data)) {
                return new JsonResponse(['ok' => false, 'error' => 'csrf'], Response::HTTP_FORBIDDEN);
            }
            $accept = ($data['newStatus'] ?? '') === 'approved';
            if (!$accept && ($data['newStatus'] ?? '') !== 'rejected') {
                return new JsonResponse(['ok' => false, 'error' => 'status'], Response::HTTP_BAD_REQUEST);
            }
            $tenant = $this->tenant();
            $user = $this->user();
            $changed = 0;
            $rejected = [];
            $perEntity = [];
            foreach (array_slice(array_map('intval', (array) ($data['ids'] ?? [])), 0, 500) as $id) {
                $s = $this->repository->find($id);
                $kind = $s instanceof Suggestion ? $this->queue->kind($s->getKind()) : null;
                if (!$s instanceof Suggestion || $s->getTenant() !== $tenant || !$s->isPending()
                    || $kind === null || !$kind->allowsBulk() || !$this->queue->canDecide($s)) {
                    $rejected[] = $id;
                    continue;
                }
                try {
                    $kindKey = $s->getKind();
                    $accept ? $this->queue->accept($s, $user) : $this->queue->reject($s, $user, null);
                    $changed++;
                    $perEntity[] = [
                        'entity_id' => $id,
                        'action' => 'update',
                        'old_values' => ['status' => 'pending'],
                        'new_values' => ['status' => $accept ? 'accepted' : 'rejected', 'kind' => $kindKey],
                    ];
                } catch (StaleSuggestionException|BusinessRuleException) {
                    $rejected[] = $id;
                }
            }

            if ($perEntity !== []) {
                // ISO 27001 Cl. 7.5.3: one batch entry + one row per decided suggestion.
                $this->auditLogger->logBulk(
                    'suggestion.bulk_decision',
                    'Suggestion',
                    [
                        'decision' => $accept ? 'accept' : 'reject',
                        'row_count_total' => count($data['ids'] ?? []),
                        'row_count_success' => $changed,
                        'row_count_skipped' => count($rejected),
                        'user_id' => $user->getId(),
                    ],
                    $perEntity,
                );
            }

            return new JsonResponse(['ok' => true, 'changed' => $changed, 'rejected' => $rejected]);
        } catch (\Throwable $e) {
            // Exception texts carry SQL, paths and class names — the log keeps them, the client gets a code.
            $this->logger->error('Suggestion bulk decision failed', ['exception' => $e]);

            return new JsonResponse(['ok' => false, 'error' => 'internal'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * "Not a match" on a live SoA mapping suggestion: remembered as a
     * rejected suggestion, so the pair is not proposed again.
     */
    #[Route(
        '/mapping/{id}/{requirement}/reject',
        name: 'mapping_reject',
        requirements: ['id' => '\d+', 'requirement' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted(ControlVoter::EDIT, subject: 'control')]
    #[IsCsrfTokenValid(new Expression('"suggestion_mapping_reject_" ~ args["control"].getId() ~ "_" ~ args["requirement"].getId()'))]
    public function rejectMapping(
        #[MapEntity(id: 'id')] Control $control,
        #[MapEntity(id: 'requirement')] ComplianceRequirement $requirement,
        Request $request,
    ): Response
    {
        $tenant = $this->tenant();
        $kind = $this->queue->kind(ControlRequirementLinkKind::KEY);
        $uploadTenant = $requirement->getUploadTenant();
        if ($control->getTenant() !== $tenant || !$kind instanceof ControlRequirementLinkKind
            || ($uploadTenant !== null && $uploadTenant !== $tenant)) {
            throw $this->createNotFoundException();
        }
        $confidence = $request->request->get('confidence');
        $this->queue->rememberRejection(
            $tenant,
            $kind,
            $kind->draft($control, $requirement, is_numeric($confidence) ? (float) $confidence : 0.0, $tenant),
            $this->user(),
            (string) $request->request->get('reason', '') ?: null,
        );
        $this->addFlash('success', 'suggestions.flash.rejected');

        return $this->redirectToRoute('app_soa_show', ['id' => $control->getId()]);
    }

    /** "Check now": runs every kind for this organisation in the background. */
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('suggestion_refresh')]
    public function refresh(Request $request): Response
    {
        $this->tenant();

        return $this->asyncJobDispatcher->dispatchWithProgress(
            request: $request,
            jobClass: RefreshSuggestionsJob::class,
            jobArgs: $this->jobTenantScope->argsForCurrentUser(),
            jobName: 'suggestions.refresh',
            payload: [
                '_label' => $this->translator->trans('suggestions.job.label', [], 'suggestions'),
                '_subtitle' => $this->translator->trans('suggestions.job.subtitle', [], 'suggestions'),
            ],
            returnUrl: $this->generateUrl('app_suggestion_index'),
        );
    }

    private function assertDecidable(Suggestion $suggestion): void
    {
        if ($suggestion->getTenant() !== $this->tenant() || !$this->queue->canDecide($suggestion)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function backToList(Request $request): Response
    {
        $back = (string) $request->request->get('_back', '');

        return \App\Security\SafeRedirectTarget::isSafe($back)
            ? $this->redirect($back)
            : $this->redirectToRoute('app_suggestion_index');
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No organisation context.');
        }

        return $tenant;
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
