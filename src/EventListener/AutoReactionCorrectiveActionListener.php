<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Repository\SystemSettingsRepository;
use App\Repository\UserRepository;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-CorrectiveAction on Major/Critical Audit-Finding.
 *
 * On AuditFinding postPersist/Update with severity in [major, critical, high]:
 * create a Corrective-Action skeleton with planned completion in 30 days,
 * status='planned', linked to the finding.
 *
 * Toggle: AutoReactionService::KEY_CA_ON_FINDING (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: AuditFinding::class)]
#[AsEntityListener(event: Events::postUpdate, entity: AuditFinding::class)]
class AutoReactionCorrectiveActionListener
{
    /**
     * V3 W2-WS-7 — SystemSettings key for severity-based CA due-days.
     * Stored as JSON dict, e.g. {"critical":14,"major":30,"high":30,"medium":60,"minor":90,"low":90}.
     * Default fallbacks live in {@see DEFAULT_DUE_DAYS}.
     */
    public const SETTINGS_CATEGORY = 'auto_reactions';
    public const SETTINGS_KEY_CA_DUE_DAYS = 'auto_ca_due_days';

    /** @var array<string, int> */
    private const DEFAULT_DUE_DAYS = [
        'critical' => 14,
        'high'     => 30,
        'major'    => 30, // alias for type=major_nc
        'medium'   => 60,
        'minor'    => 90, // alias for type=minor_nc
        'low'      => 90,
    ];

    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?SystemSettingsRepository $systemSettings = null,
    ) {
    }

    public function postPersist(AuditFinding $finding, PostPersistEventArgs $args): void
    {
        $this->maybeCreateCa($finding, $args);
    }

    public function postUpdate(AuditFinding $finding, PostUpdateEventArgs $args): void
    {
        $this->maybeCreateCa($finding, $args);
    }

    private function maybeCreateCa(AuditFinding $finding, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_CA_ON_FINDING)) {
            return;
        }

        $type = method_exists($finding, 'getType') ? $finding->getType() : null;
        $severity = method_exists($finding, 'getSeverity') ? $finding->getSeverity() : null;

        $isMajor = in_array($type, [AuditFinding::TYPE_MAJOR_NC], true)
            || in_array($severity, [AuditFinding::SEVERITY_CRITICAL, AuditFinding::SEVERITY_HIGH], true);

        if (!$isMajor) {
            return;
        }

        try {
            $em = $args->getObjectManager();

            // Already has a corrective action?
            $existing = $em->getRepository(CorrectiveAction::class)->findOneBy([
                'finding' => $finding,
            ]);
            if ($existing instanceof CorrectiveAction) {
                return;
            }

            $ca = new CorrectiveAction();
            $ca->setTenant(method_exists($finding, 'getTenant') ? $finding->getTenant() : null);
            $ca->setFinding($finding);
            $ca->setTitle('Corrective Action: ' . ($finding->getTitle() ?? '—'));
            if (method_exists($ca, 'setDescription')) {
                $ca->setDescription(
                    'Auto-generated corrective action skeleton for major/critical finding. Update root-cause analysis and target date.'
                );
            }
            if (method_exists($ca, 'setActionType')) {
                $ca->setActionType(CorrectiveAction::ACTION_TYPE_CORRECTIVE);
            }
            if (method_exists($ca, 'setPlannedCompletionDate')) {
                $dueDays = $this->resolveDueDays($severity, $type);
                $ca->setPlannedCompletionDate(new DateTimeImmutable('+' . $dueDays . ' days'));
            }
            if (method_exists($ca, 'setStatus')) {
                $ca->setStatus(CorrectiveAction::STATUS_PLANNED);
            }

            $em->persist($ca);
            $em->flush();

            $this->logger->info('Auto-CorrectiveAction created for finding', [
                'finding_id' => $finding->getId(),
                'ca_id' => $ca->getId(),
            ]);

            // V3 W2-H4 (ISO 27001 Cl.7.4 + Cl.10.1): Notify CA assignee /
            // finding owner so the 30-day target lands on a calendar.
            $this->notifyAssignee($finding, $ca);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-CorrectiveAction failed', [
                'finding_id' => $finding->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-WS-7 — Map severity / type to days-until-due.
     *
     * Resolution order:
     *  1. SystemSettings JSON dict (per-tenant configurable).
     *  2. DEFAULT_DUE_DAYS fallback.
     *  3. 30 days (legacy default).
     *
     * Severity (critical/high/medium/low) takes precedence over type
     * (major_nc/minor_nc) — operationally severity is the more granular
     * driver. When the severity key is absent we fall back to the type
     * synonym (major_nc → major, minor_nc → minor).
     */
    private function resolveDueDays(?string $severity, ?string $type): int
    {
        $config = self::DEFAULT_DUE_DAYS;
        if ($this->systemSettings !== null) {
            $stored = $this->systemSettings->getSetting(
                self::SETTINGS_CATEGORY,
                self::SETTINGS_KEY_CA_DUE_DAYS,
                null,
            );
            if (is_array($stored)) {
                foreach ($stored as $k => $v) {
                    $key = strtolower((string) $k);
                    $val = (int) $v;
                    if ($val > 0 && $val <= 365) {
                        $config[$key] = $val;
                    }
                }
            }
        }

        $sev = strtolower((string) $severity);
        if ($sev !== '' && isset($config[$sev])) {
            return $config[$sev];
        }
        // Fallback via type → severity synonym.
        $typeKey = match ($type) {
            AuditFinding::TYPE_MAJOR_NC => 'major',
            AuditFinding::TYPE_MINOR_NC => 'minor',
            default => null,
        };
        if ($typeKey !== null && isset($config[$typeKey])) {
            return $config[$typeKey];
        }
        return 30;
    }

    /**
     * V3 W2-H4 — Notify the CA assignee. Falls back to finding.assignee, then
     * tenant ROLE_AUDITOR / ROLE_MANAGER. Best-effort.
     */
    private function notifyAssignee(AuditFinding $finding, CorrectiveAction $ca): void
    {
        if ($this->emailNotifier === null) {
            return;
        }
        try {
            $tenant = method_exists($finding, 'getTenant') ? $finding->getTenant() : null;
            $recipients = [];

            // 1) Direct assignee on the CA (currently null since we just created it)
            if (method_exists($ca, 'getResponsiblePersonUser') && $ca->getResponsiblePersonUser() !== null) {
                $recipients[] = $ca->getResponsiblePersonUser();
            }
            // 2) Finding owner
            if (empty($recipients) && method_exists($finding, 'getAssignedTo') && $finding->getAssignedTo() !== null) {
                $recipients[] = $finding->getAssignedTo();
            }
            // 3) Tenant fallback
            if (empty($recipients) && $this->userRepository !== null) {
                $recipients = $this->userRepository->findByRoleInTenant('ROLE_AUDITOR', $tenant);
                if (empty($recipients)) {
                    $recipients = $this->userRepository->findByRoleInTenant('ROLE_MANAGER', $tenant);
                }
            }

            if (empty($recipients)) {
                return;
            }

            $this->emailNotifier->sendGenericNotification(
                sprintf('Corrective Action 30-day target: %s', (string) ($finding->getTitle() ?? '—')),
                'emails/auto_reaction_corrective_action.html.twig',
                [
                    'finding' => $finding,
                    'ca' => $ca,
                    'plannedCompletionDate' => $ca->getPlannedCompletionDate(),
                ],
                $recipients
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-CA notification failed', [
                'ca_id' => $ca->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
