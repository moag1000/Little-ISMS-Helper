<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\AuditFindingRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\StatementOfApplicabilityController;
use App\Service\Suggestion\TargetAccess;

/**
 * An open nonconformity against a control the SoA still calls done
 * (ISO 27001 10.2 a, 9.2): a major one proposes "in progress" (50 %), a
 * minor one takes "verified" back to "implemented". The ISB decides; the
 * fulfilment of the requirements behind the control follows via
 * {@see \App\Service\Compliance\Fulfillment\ControlChangeCollector}.
 */
final class ControlDowngradeKind extends AbstractSuggestionKind
{
    public const string KEY = 'control.downgrade';

    public function __construct(
        private readonly AuditFindingRepository $findings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Rule;
    }

    public function targetAccess(): TargetAccess
    {
        return TargetAccess::action(StatementOfApplicabilityController::class, 'edit');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'audits';
    }

    /**
     * Status the finding allows at most; null when the control may stay as it is.
     *
     * @return array{status: string, percentage: int|null}|null
     */
    public static function targetFor(AuditFinding $finding, Control $control): ?array
    {
        $status = $control->getImplementationStatus();

        return match (true) {
            $finding->getType() === AuditFinding::TYPE_MAJOR_NC && ControlState::isImplemented($control) => ['status' => 'in_progress', 'percentage' => 50],
            $finding->getType() === AuditFinding::TYPE_MINOR_NC && $status === 'verified' => ['status' => 'implemented', 'percentage' => null],
            default => null,
        };
    }

    public function generate(Tenant $tenant): iterable
    {
        // one proposal per control; a major nonconformity outranks a minor one
        $worst = [];
        foreach ($this->findings->findOpenByTenant($tenant) as $finding) {
            if (!$finding->isNonconformity()) {
                continue;
            }
            foreach ($finding->getRelatedControls() as $control) {
                $target = $control->getTenant() === $tenant ? self::targetFor($finding, $control) : null;
                if ($target === null) {
                    continue;
                }
                $id = (int) $control->getId();
                if (!isset($worst[$id]) || ($target['status'] === 'in_progress' && $worst[$id][2]['status'] !== 'in_progress')) {
                    $worst[$id] = [$control, $finding, $target];
                }
            }
        }

        foreach ($worst as [$control, $finding, $target]) {
            $current = (string) $control->getImplementationStatus();
            yield new SuggestionDraft(
                subject: $control,
                title: $this->text->t($tenant, 'suggestions.text.control_downgrade.title', ['%control%' => ControlState::label($control)]),
                rationale: $this->text->t($tenant, 'suggestions.text.control_downgrade.rationale', [
                    '%finding%' => (string) ($finding->getFindingNumber() ?? $finding->getTitle()),
                    '%type%' => $this->text->t($tenant, 'suggestions.text.control_downgrade.type.' . $finding->getType()),
                    '%from%' => $this->text->t($tenant, 'suggestions.text.control_downgrade.status.' . $current),
                    '%to%' => $this->text->t($tenant, 'suggestions.text.control_downgrade.status.' . $target['status']),
                ]),
                payload: ['status' => $target['status'], 'percentage' => $target['percentage'], 'from' => $current, 'finding' => $finding->getId()],
                dedupKey: sprintf('control:%d:%s:%s', (int) $control->getId(), $current, $target['status']),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $control = $this->entityManager->find(Control::class, (int) $suggestion->getSubjectId());
        if (!$control instanceof Control || $control->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('control_gone');
        }
        $payload = $suggestion->getPayload();
        if ($control->getImplementationStatus() !== ($payload['from'] ?? null)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $control->setImplementationStatus((string) $payload['status']);
        if (isset($payload['percentage'])) {
            $control->setImplementationPercentage((int) $payload['percentage']);
        }

        return $control;
    }
}
