<?php

declare(strict_types=1);

namespace App\Service\Incident;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineType;
use App\Service\ModuleConfigurationService;
use App\Util\LegalPeriod;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The one place that answers "which reports are due for this incident or
 * data breach, and by when":
 *
 *  - NIS-2 Art. 23 (module nis2_dora, incident reportable per
 *    {@see Incident::requiresNis2Reporting()}): early warning 24 h, incident
 *    notification 72 h, final report one month after the notification.
 *  - DORA Art. 19 (module nis2_dora, financial entity, incident classified as
 *    major): initial notification (24 h at the latest), intermediate report
 *    72 h after the initial one, final report one month after the intermediate.
 *  - GDPR Art. 33 (data breach that needs an authority notification): 72 h
 *    after the controller became aware (detectedAt).
 *
 * Each later stage is anchored on the actual earlier report, otherwise on the
 * earlier deadline. SLA monitors, My Day, reminders, dashboards and exports
 * read these duties instead of computing deadlines of their own.
 */
final readonly class ReportingPlanner
{
    public const string DORA_MAJOR = 'major_ict_incident';

    public function __construct(
        private ModuleConfigurationService $modules,
    ) {
    }

    /** @return list<ReportingDuty> */
    public function dutiesFor(object $record): array
    {
        return match (true) {
            $record instanceof Incident => $this->forIncident($record),
            $record instanceof DataBreach => $this->forDataBreach($record),
            default => [],
        };
    }

    /** @return list<ReportingDuty> */
    public function forIncident(Incident $incident): array
    {
        $detectedAt = self::immutable($incident->getDetectedAt());
        $tenant = $incident->getTenant();
        if ($detectedAt === null || $tenant === null || !$this->modules->isModuleActiveForTenant('nis2_dora', $tenant)) {
            return [];
        }

        $duties = [];
        if ($incident->requiresNis2Reporting()) {
            $early = $incident->getEarlyWarningDeadline();
            $notification = $incident->getDetailedNotificationDeadline();
            $final = $incident->getFinalReportDeadline();
            if ($early !== null && $notification !== null && $final !== null) {
                $duties[] = new ReportingDuty(SlaDeadlineType::Nis2EarlyWarning24h, $detectedAt, $early, $incident->getEarlyWarningReportedAt(), 'earlyWarningReportedAt');
                $duties[] = new ReportingDuty(SlaDeadlineType::Nis2Notification72h, $detectedAt, $notification, $incident->getDetailedNotificationReportedAt(), 'detailedNotificationReportedAt');
                $duties[] = new ReportingDuty(
                    SlaDeadlineType::Nis2FinalReport1mo,
                    $incident->getDetailedNotificationReportedAt() ?? $notification,
                    $final,
                    $incident->getFinalReportSubmittedAt(),
                    'finalReportSubmittedAt',
                );
            }
        }

        if (self::doraApplies($incident, $tenant)) {
            $initial = self::after($detectedAt, SlaDeadlineType::DoraInitialNotification4h);
            $intermediateAnchor = $incident->getInitialReportSubmittedAt() ?? $initial;
            $intermediate = self::after($intermediateAnchor, SlaDeadlineType::DoraIntermediate72h);
            $finalAnchor = $incident->getIntermediateReportSubmittedAt() ?? $intermediate;
            $duties[] = new ReportingDuty(SlaDeadlineType::DoraInitialNotification4h, $detectedAt, $initial, $incident->getInitialReportSubmittedAt(), 'initialReportSubmittedAt');
            $duties[] = new ReportingDuty(SlaDeadlineType::DoraIntermediate72h, $intermediateAnchor, $intermediate, $incident->getIntermediateReportSubmittedAt(), 'intermediateReportSubmittedAt');
            $duties[] = new ReportingDuty(SlaDeadlineType::DoraFinalReport1mo, $finalAnchor, LegalPeriod::addMonths($finalAnchor, 1), $incident->getDoraFinalReportSubmittedAt(), 'doraFinalReportSubmittedAt');
        }

        return $duties;
    }

    /** @return list<ReportingDuty> */
    public function forDataBreach(DataBreach $breach): array
    {
        $detectedAt = self::immutable($breach->getDetectedAt());
        if ($detectedAt === null || !$breach->getRequiresAuthorityNotification()) {
            return [];
        }

        return [new ReportingDuty(
            SlaDeadlineType::GdprNotification72h,
            $detectedAt,
            self::after($detectedAt, SlaDeadlineType::GdprNotification72h),
            self::immutable($breach->getSupervisoryAuthorityNotifiedAt()),
            'supervisoryAuthorityNotifiedAt',
        )];
    }

    /** The duty of $type, or null when the record has none of that type. */
    public function duty(object $record, SlaDeadlineType $type): ?ReportingDuty
    {
        foreach ($this->dutiesFor($record) as $duty) {
            if ($duty->type === $type) {
                return $duty;
            }
        }

        return null;
    }

    private static function doraApplies(Incident $incident, Tenant $tenant): bool
    {
        return $tenant->getDoraEntityCategory() === Tenant::DORA_FINANCIAL_ENTITY
            && $incident->getIctIncidentClassification() === self::DORA_MAJOR;
    }

    private static function after(DateTimeImmutable $anchor, SlaDeadlineType $type): DateTimeImmutable
    {
        return $anchor->modify(sprintf('+%d hours', $type->durationHours()));
    }

    private static function immutable(?DateTimeInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value);
    }
}
