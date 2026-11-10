<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\StatementOfApplicabilityController;
use App\Service\Suggestion\TargetAccess;

/**
 * Effectiveness of an implemented control (ISO 27001 9.1) from the incidents
 * on the assets it protects since it was implemented (Control::
 * getEffectivenessScore) and from the incidents that name it as failed
 * (Incident::failedControls — one failure: at most partially effective, two
 * or more: ineffective). Proposes a first rating where none exists and a
 * downgrade where incidents contradict an "effective" rating; never an
 * upgrade, that needs a test.
 */
final class ControlEffectivenessKind extends AbstractSuggestionKind
{
    public const string KEY = 'control.effectiveness';

    private const array RANK = ['ineffective' => 1, 'partially_effective' => 2, 'effective' => 3, 'highly_effective' => 4];

    public function __construct(
        private readonly ControlRepository $controls,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
        private readonly ?IncidentRepository $incidents = null,
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
        return 'controls';
    }

    public static function ratingFor(float $score): string
    {
        return match (true) {
            $score >= 80 => 'effective',
            $score >= 50 => 'partially_effective',
            default => 'ineffective',
        };
    }

    /** Rating the failures since implementation allow at most; null without failure. */
    public static function ratingForFailures(int $failures): ?string
    {
        return match (true) {
            $failures >= 2 => 'ineffective',
            $failures === 1 => 'partially_effective',
            default => null,
        };
    }

    public function generate(Tenant $tenant): iterable
    {
        $failedAt = $this->incidents?->failedControlDetections($tenant) ?? [];
        foreach ($this->controls->findByTenant($tenant) as $control) {
            $failures = self::failuresSince($control, $failedAt[(int) $control->getId()] ?? []);
            if (!ControlState::isImplemented($control) || (int) $control->getImplementationPercentage() < 100
                || ($control->getProtectedAssets()->count() === 0 && $failures === 0)) {
                continue;
            }
            $current = $control->getEffectiveness();
            $rating = $control->getProtectedAssets()->count() > 0
                ? self::ratingFor($control->getEffectivenessScore())
                : 'highly_effective';
            $byFailures = self::ratingForFailures($failures);
            if ($byFailures !== null && self::RANK[$byFailures] < self::RANK[$rating]) {
                $rating = $byFailures;
            }
            $unrated = $current === null || $current === '' || $current === 'not_assessed';
            $downgrade = isset(self::RANK[$current]) && self::RANK[$rating] < self::RANK[$current];
            if (!$unrated && !$downgrade) {
                continue;
            }
            yield new SuggestionDraft(
                subject: $control,
                title: $this->text->t($tenant, 'suggestions.text.control_effectiveness.title', ['%control%' => ControlState::label($control)]),
                rationale: $this->text->t($tenant, 'suggestions.text.control_effectiveness.rationale', [
                    '%incidents%' => self::incidentsSince($control),
                    '%assets%' => $control->getProtectedAssets()->count(),
                    '%score%' => (int) round($control->getEffectivenessScore()),
                    '%rating%' => $this->text->t($tenant, 'suggestions.text.effectiveness.' . $rating),
                    '%tested%' => $control->getLastEffectivenessTest()?->format('d.m.Y') ?? '—',
                ]) . ($failures > 0 ? ' ' . $this->text->t($tenant, 'suggestions.text.control_effectiveness.failed', ['%count%' => $failures]) : ''),
                payload: ['effectiveness' => $rating, 'from' => $current],
                dedupKey: sprintf('control:%d:%s:%s', (int) $control->getId(), (string) $current, $rating),
            );
        }
    }

    /**
     * @param list<\DateTimeInterface> $detections
     */
    private static function failuresSince(Control $control, array $detections): int
    {
        $since = $control->getLastReviewDate() ?? $control->getCreatedAt();

        return count(array_filter($detections, static fn (\DateTimeInterface $at): bool => $since === null || $at >= $since));
    }

    private static function incidentsSince(Control $control): int
    {
        $since = $control->getLastReviewDate() ?? $control->getCreatedAt();
        $count = 0;
        foreach ($control->getProtectedAssets() as $asset) {
            foreach ($asset->getIncidents() as $incident) {
                if ($since === null || $incident->getDetectedAt() >= $since) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $control = $this->entityManager->find(Control::class, (int) $suggestion->getSubjectId());
        if (!$control instanceof Control || $control->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('control_gone');
        }
        $payload = $suggestion->getPayload();
        if ($control->getEffectiveness() !== ($payload['from'] ?? null)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $control->setEffectiveness((string) $payload['effectiveness']);

        return $control;
    }
}
