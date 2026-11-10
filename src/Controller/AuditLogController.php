<?php

declare(strict_types=1);

namespace App\Controller;

use DateTime;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Util\InputDate;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Job\VerifyAuditLogChainJob;
use App\Service\AuditLogIntegrityService;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\Job\JobTenantScope;
use App\Util\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
class AuditLogController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly TranslatorInterface $translator,
        private readonly AsyncJobDispatcher $asyncJobDispatcher,
        private readonly JobTenantScope $jobTenantScope,
        private readonly ?AuditLogIntegrityService $integrityService = null,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'audit_log';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    /**
     * HMAC-Chain Verify endpoint surfaced as Admin button.
     * Auditor's classic question "manipulationssicher?" → click & see green/red.
     * Runs as async job (large logs take longer than a request) over the chain of
     * the admin's organisation; a SUPER_ADMIN without organisation checks every chain.
     */
    #[Route('/admin/audit-log/verify', name: 'app_audit_log_verify', methods: ['POST'])]
    #[IsCsrfTokenValid('audit_log_verify')]
    public function verifyChain(Request $request): Response
    {
        if ($this->integrityService === null || !$this->integrityService->isEnabled()) {
            $this->flashWarning('audit_log.integrity.disabled');
            return $this->redirectToRoute('app_audit_log_index');
        }

        return $this->asyncJobDispatcher->dispatchWithProgress(
            request: $request,
            jobClass: VerifyAuditLogChainJob::class,
            jobArgs: $this->jobTenantScope->argsForCurrentUser() + ['locale' => $request->getLocale()],
            jobName: 'audit_log.verify_chain',
            payload: [
                '_label' => $this->translator->trans('audit_log.integrity.job_label', [], 'audit_log'),
                '_subtitle' => $this->translator->trans('audit_log.integrity.job_subtitle', [], 'audit_log'),
            ],
            returnUrl: $this->generateUrl('app_audit_log_index'),
        );
    }

    #[Route('/admin/audit-log', name: 'app_audit_log_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limit = ListPage::perPage($request);
        $offset = (ListPage::pageNumber($request) - 1) * $limit;

        // Get filter parameters
        $filters = [
            'entityType' => is_string($v = $request->query->get('entityType')) ? $v : null,
            'action' => is_string($v = $request->query->get('action')) ? $v : null,
            'userName' => is_string($v = $request->query->get('userName')) ? $v : null,
            'dateFrom' => InputDate::parse($request->query->get('dateFrom')),
            'dateTo' => InputDate::parse($request->query->get('dateTo')),
        ];

        // Remove empty filters
        $filters = array_filter($filters, fn(int|DateTime|string|null $value): bool => $value !== null && $value !== '');

        // Get logs based on filters. countSearch() gives the REAL total so
        // pagination is correct; search() pages via limit+offset (previously the
        // total was just count() of the capped page → always 1 page when filtered).
        if (count($filters) > 0) {
            $totalLogs = $this->auditLogRepository->countSearch($filters);
            $auditLogs = $this->auditLogRepository->search($filters + ['limit' => $limit, 'offset' => $offset]);
        } else {
            $auditLogs = $this->auditLogRepository->findAllOrdered($limit, $offset);
            $totalLogs = $this->auditLogRepository->countAll();
        }

        // Get statistics
        $actionStats = $this->auditLogRepository->getActionStatistics();
        $entityTypeStats = $this->auditLogRepository->getEntityTypeStatistics();
        $recentActivity = $this->auditLogRepository->recentActivitySummary(24)['total'];

        // Filter dropdowns from DISTINCT queries — never the whole table (S31).
        $entityTypes = $this->auditLogRepository->distinctValues('entityType');
        $actions = $this->auditLogRepository->distinctValues('action');

        return $this->render('audit_log/index.html.twig', [
            'page' => ListPage::fromSlice($auditLogs, $totalLogs, $request),
            'totalLogs' => $totalLogs,
            'actionStats' => $actionStats,
            'entityTypeStats' => $entityTypeStats,
            'recentActivity' => $recentActivity,
            'entityTypes' => $entityTypes,
            'actions' => $actions,
            'filters' => $request->query->all()
        ]);
    }

    #[Route('/admin/audit-log/entity/{entityType}/{entityId}', name: 'app_audit_log_entity', methods: ['GET'])]
    public function entityHistory(string $entityType, int $entityId): Response
    {
        $auditLogs = $this->auditLogRepository->findByEntity($entityType, $entityId);

        return $this->render('audit_log/entity_history.html.twig', [
            'auditLogs' => $auditLogs,
            'entityType' => $entityType,
            'entityId' => $entityId
        ]);
    }

    #[Route('/admin/audit-log/user/{userName}', name: 'app_audit_log_user', methods: ['GET'])]
    public function userActivity(string $userName): Response
    {
        $auditLogs = $this->auditLogRepository->findByUser($userName);

        return $this->render('audit_log/user_activity.html.twig', [
            'auditLogs' => $auditLogs,
            'userName' => $userName
        ]);
    }

    #[Route('/admin/audit-log/statistics', name: 'app_audit_log_statistics', methods: ['GET'])]
    public function statistics(): Response
    {
        $actionStats = $this->auditLogRepository->getActionStatistics();
        $entityTypeStats = $this->auditLogRepository->getEntityTypeStatistics();
        $totalLogs = $this->auditLogRepository->countAll();

        // Activity per day of the last 30 days, one aggregate query (S31).
        $activityByDay = $this->auditLogRepository->countPerDay(30);

        return $this->render('audit_log/statistics.html.twig', [
            'actionStats' => $actionStats,
            'entityTypeStats' => $entityTypeStats,
            'totalLogs' => $totalLogs,
            'activityByDay' => $activityByDay
        ]);
    }

    #[Route('/admin/audit-log/{id}', name: 'app_audit_log_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $auditLog = $this->auditLogRepository->find($id);

        if (!$auditLog) {
            throw $this->createNotFoundException('Audit log not found');
        }

        return $this->render('audit_log/detail.html.twig', [
            'auditLog' => $auditLog
        ]);
    }
}
