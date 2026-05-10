<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DataProtectionImpactAssessment;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * V3 W2-FV-7 — DPIA review-frist reminder cron.
 *
 * Scans DataProtectionImpactAssessment rows whose `nextReviewDate` lies
 * within configured warning windows (30 / 14 / 7 days ahead) and emits
 * notifications to the tenant DPO. Mirrors the design of
 * RiskAcceptanceExpiryReminderCommand (V3 W2-M8 / WS-2).
 *
 *   php bin/console app:dpia-reminder
 *   php bin/console app:dpia-reminder --windows=30,14,7
 *   php bin/console app:dpia-reminder --dry-run
 *
 * Default cadence: 30 / 14 / 7 days. Run daily under cron — duplicate
 * sends are tolerated by design (idempotent, an SLA-aware DPO will
 * appreciate the reminder cascade).
 */
#[AsCommand(
    name: 'app:dpia-reminder',
    description: 'Notify DPO about DPIAs whose review-date approaches (default windows: 30,14,7 days).',
)]
class DpiaReminderCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'windows', description: 'Comma-separated days-ahead windows (default 30,14,7)')] string $windows = '30,14,7',
        #[Option(name: 'dry-run', description: 'Preview only, no notifications sent')] bool $dryRun = false,
    ): int {
        $windowDays = $this->parseWindows($windows);
        if ($windowDays === []) {
            $io->error('No valid window provided.');
            return Command::FAILURE;
        }

        $today = new \DateTimeImmutable('today');
        $maxWindow = max($windowDays);
        $cutoff = $today->modify('+' . $maxWindow . ' days');

        $dpias = $this->entityManager->getRepository(DataProtectionImpactAssessment::class)
            ->createQueryBuilder('d')
            ->where('d.nextReviewDate IS NOT NULL')
            ->andWhere('d.nextReviewDate <= :cutoff')
            ->andWhere('d.status != :archived')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('archived', 'archived')
            ->orderBy('d.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();

        if ($dpias === []) {
            $io->success(sprintf('No DPIAs with review-date within %d days.', $maxWindow));
            return Command::SUCCESS;
        }

        $rows = [];
        $notified = 0;
        foreach ($dpias as $dpia) {
            /** @var DataProtectionImpactAssessment $dpia */
            $next = $dpia->getNextReviewDate();
            if ($next === null) {
                continue;
            }
            $nextImm = $next instanceof \DateTimeImmutable
                ? $next
                : \DateTimeImmutable::createFromMutable($next instanceof \DateTime ? $next : new \DateTime($next->format('c')));

            $diff = (int) $today->diff($nextImm)->format('%R%a');
            $matchingWindow = $this->matchingWindow($diff, $windowDays);
            $rows[] = [
                $dpia->getId(),
                $dpia->getTitle() ?? '—',
                $nextImm->format('Y-m-d'),
                $diff,
                $matchingWindow !== null ? $matchingWindow . 'd' : 'skip',
                $dpia->getTenant()?->getId() ?? '—',
            ];
            if ($matchingWindow === null) {
                continue;
            }
            if (!$dryRun) {
                $sent = $this->notifyDpo($dpia, $diff, $matchingWindow);
                if ($sent) {
                    $notified++;
                }
            }
        }

        $io->table(['DPIA ID', 'Title', 'Next Review', 'Days', 'Window', 'Tenant'], $rows);
        $io->note(sprintf(
            'Reviewed %d DPIA(s); %d notification(s) %s.',
            count($rows),
            $notified,
            $dryRun ? 'WOULD have been sent (dry-run)' : 'sent',
        ));

        $this->logger->info('DPIA reminder cron run', [
            'count_total' => count($rows),
            'count_notified' => $notified,
            'windows' => $windowDays,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseWindows(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $i = (int) trim($part);
            if ($i >= 1 && $i <= 365) {
                $out[] = $i;
            }
        }
        rsort($out);
        return array_values(array_unique($out));
    }

    /**
     * @param list<int> $windows
     */
    private function matchingWindow(int $diff, array $windows): ?int
    {
        // Pick the smallest window that covers the diff (so 5 days hits
        // the 7-day window, not 14 or 30; already-overdue (-2 days)
        // matches the smallest window too).
        $hits = array_filter($windows, fn (int $w): bool => $diff <= $w);
        if ($hits === []) {
            return null;
        }
        return min($hits);
    }

    private function notifyDpo(
        DataProtectionImpactAssessment $dpia,
        int $diffDays,
        int $matchedWindow,
    ): bool {
        if ($this->emailNotifier === null || $this->userRepository === null) {
            return false;
        }
        $tenant = $dpia->getTenant();
        if ($tenant === null) {
            return false;
        }
        $dpos = $this->userRepository->findByRoleInTenant('ROLE_DPO', $tenant);
        if (empty($dpos)) {
            return false;
        }
        try {
            $this->emailNotifier->sendGenericNotification(
                sprintf('DPIA review approaching (%d days): %s', $diffDays, (string) ($dpia->getTitle() ?? '—')),
                'emails/dpia_review_reminder.html.twig',
                [
                    'dpia' => $dpia,
                    'days_remaining' => $diffDays,
                    'matched_window' => $matchedWindow,
                ],
                $dpos,
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('DPIA reminder notification failed', [
                'dpia_id' => $dpia->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
