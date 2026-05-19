<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AuditLogger;
use App\Service\Job\WorkerHealthService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Worker-Health UI plus the "Process queue now" emergency-trigger.
 *
 * Purpose: shared-hosting deployments (no systemd, no Docker, only PHP-FPM +
 * a panel cron). Admins land here to verify the cron is running, see queue
 * depth + heartbeat, browse recent jobs, and drain the queue manually
 * when needed.
 *
 * See docs/user-guide/HOSTING_WORKER.md for the cron-pattern.
 */
#[IsGranted('ROLE_ADMIN')]
final class QueueStatusController extends AbstractController
{
    /**
     * Manual-trigger ceiling: 25 s under PHP-FPM 30 s limit; up to 5 messages.
     * Conservative — admin can repeat-click if more drainage is needed.
     */
    private const MANUAL_TIME_LIMIT_SECONDS = 25;
    private const MANUAL_MESSAGE_LIMIT = 5;

    public function __construct(
        private readonly WorkerHealthService $workerHealth,
        private readonly AuditLogger $auditLogger,
        private readonly KernelInterface $kernel,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Worker-Health dashboard. Renders queue depth, heartbeat traffic light,
     * pending/failed counts, last 20 jobs, and the manual-trigger button.
     */
    #[Route('/admin/queue-status', name: 'admin_queue_status', methods: ['GET'])]
    public function index(): Response
    {
        $snapshot = $this->workerHealth->snapshot();
        $recent = $this->workerHealth->recentJobs();

        return $this->render('admin/queue_status/index.html.twig', [
            'snapshot' => $snapshot,
            'recent_jobs' => $recent,
            'cron_command' => '* * * * * cd ' . $this->projectDir
                . ' && php bin/console messenger:consume async --time-limit=55 --limit=20 --quiet',
        ]);
    }

    /**
     * "Process queue now" — manual fallback when no cron is configured.
     *
     * Spawns an in-process `messenger:consume` with very tight limits so we
     * stay safely under PHP-FPM's 30 s wall-clock. Up to MANUAL_MESSAGE_LIMIT
     * messages are drained.
     *
     * Audit-logged because triggering the worker has side effects
     * (every queued admin job actually executes here).
     */
    #[Route('/admin/jobs/process-queue', name: 'admin_queue_process_now', methods: ['POST'])]
    #[IsCsrfTokenValid('admin_queue_process_now')]
    public function processQueueNow(Request $request): Response
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'messenger:consume',
            'receivers' => ['async'],
            '--time-limit' => (string) self::MANUAL_TIME_LIMIT_SECONDS,
            '--limit' => (string) self::MANUAL_MESSAGE_LIMIT,
            '--no-interaction' => true,
            '--quiet' => true,
        ]);

        $output = new BufferedOutput();
        $exitCode = 1;
        $startedAt = time();

        try {
            $exitCode = $application->run($input, $output);
        } catch (\Throwable $e) {
            $this->logger->error('Manual queue processing failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->addFlash(
                'error',
                $this->translator->trans('admin.queue.process_now.error', [
                    '%error%' => $e->getMessage(),
                ], 'admin'),
            );

            $this->auditLogger->logCustom(
                'admin.queue.process_now_failed',
                'WorkerQueue',
                null,
                null,
                ['error' => $e->getMessage()],
                'Manual queue-process trigger failed: ' . $e->getMessage(),
            );

            return $this->redirectToRoute('admin_queue_status');
        }

        $duration = time() - $startedAt;

        $this->auditLogger->logCustom(
            'admin.queue.process_now',
            'WorkerQueue',
            null,
            null,
            [
                'exit_code' => $exitCode,
                'duration_seconds' => $duration,
                'time_limit' => self::MANUAL_TIME_LIMIT_SECONDS,
                'message_limit' => self::MANUAL_MESSAGE_LIMIT,
            ],
            sprintf('Manual queue drain: exit=%d, %ds elapsed', $exitCode, $duration),
        );

        if ($exitCode === 0) {
            $this->addFlash(
                'success',
                $this->translator->trans('admin.queue.process_now.success', [
                    '%duration%' => $duration,
                ], 'admin'),
            );
        } else {
            $this->addFlash(
                'warning',
                $this->translator->trans('admin.queue.process_now.partial', [
                    '%exit%' => $exitCode,
                    '%duration%' => $duration,
                ], 'admin'),
            );
        }

        return $this->redirectToRoute('admin_queue_status');
    }
}
