<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\AuditChecklist;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\AuditChecklistRepository;
use DateTimeImmutable;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The auditor's verdict on a requirement (audit checklist, ISO 27001 9.2)
 * acts on its fulfilment: the newest verdict of the last twelve months caps
 * what the data say — "non compliant" 0 %, "partial" its score (50 % when
 * none is given), "compliant" 100 %. A requirement without other rule takes
 * the verdict as value. A compliant verdict on a fully met requirement marks
 * it verified. The verdict shows as a row under "Warum dieser Wert?".
 */
class AuditResultRule implements ResetInterface
{
    public const string KIND_AUDIT = 'audit';
    public const int VALID_MONTHS = 12;

    /** @var array<int, array<int, AuditChecklist>> tenant id → requirement id → newest verdict */
    private array $verdicts = [];

    public function __construct(
        private readonly AuditChecklistRepository $checklists,
    ) {
    }

    public static function valueOf(AuditChecklist $item): int
    {
        return match ($item->getVerificationStatus()) {
            'compliant' => 100,
            'partial' => $item->getComplianceScore() > 0 ? min(99, $item->getComplianceScore()) : 50,
            default => 0,
        };
    }

    public function apply(?FulfillmentDerivation $derivation, ComplianceRequirement $requirement, Tenant $tenant): ?FulfillmentDerivation
    {
        $verdict = $this->verdictFor($requirement, $tenant);
        if (!$verdict instanceof AuditChecklist || $derivation?->applicable === false) {
            return $derivation;
        }

        $value = self::valueOf($verdict);
        $row = [
            'type' => 'audit',
            'id' => $verdict->getAudit()?->getId(),
            'ref' => $verdict->getAudit()?->getAuditNumber(),
            'label' => (string) ($verdict->getAudit()?->getTitle() ?? ''),
            'status' => $verdict->getVerificationStatus(),
            'verified_at' => $verdict->getVerifiedAt()?->format('Y-m-d'),
            'value' => $value,
        ];

        if ($derivation === null || $derivation->percentage === null) {
            return new FulfillmentDerivation(
                $value,
                self::KIND_AUDIT,
                [...($derivation?->basis ?? []), $row],
                $derivation?->applicable,
                $derivation?->applicabilityJustification,
                $value >= 100,
            );
        }

        $percentage = min($derivation->percentage, $value);

        return new FulfillmentDerivation(
            $percentage,
            $derivation->kind,
            [...$derivation->basis, $row],
            $derivation->applicable,
            $derivation->applicabilityJustification,
            $value >= 100 && $percentage >= 100,
        );
    }

    public function reset(): void
    {
        $this->verdicts = [];
    }

    public function forgetTenant(Tenant $tenant): void
    {
        unset($this->verdicts[(int) $tenant->getId()]);
    }

    private function verdictFor(ComplianceRequirement $requirement, Tenant $tenant): ?AuditChecklist
    {
        $tenantId = (int) $tenant->getId();
        if (!isset($this->verdicts[$tenantId])) {
            $map = [];
            $since = new DateTimeImmutable(sprintf('-%d months', self::VALID_MONTHS));
            foreach ($this->checklists->findVerdictsSince($tenant, $since) as $item) {
                $map[(int) $item->getRequirement()?->getId()] ??= $item; // newest first
            }
            $this->verdicts[$tenantId] = $map;
        }

        return $this->verdicts[$tenantId][(int) $requirement->getId()] ?? null;
    }
}
