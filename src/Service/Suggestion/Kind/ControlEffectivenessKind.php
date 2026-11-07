<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ControlRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Effectiveness of an implemented control (ISO 27001 9.1) from the incidents
 * on the assets it protects since it was implemented (Control::
 * getEffectivenessScore). Proposes a first rating where none exists and a
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

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->controls->findByTenant($tenant) as $control) {
            if (!ControlState::isImplemented($control) || $control->getProtectedAssets()->count() === 0
                || (int) $control->getImplementationPercentage() < 100) {
                continue;
            }
            $current = $control->getEffectiveness();
            $rating = self::ratingFor($control->getEffectivenessScore());
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
                ]),
                payload: ['effectiveness' => $rating, 'from' => $current],
                dedupKey: sprintf('control:%d:%s:%s', (int) $control->getId(), (string) $current, $rating),
            );
        }
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
