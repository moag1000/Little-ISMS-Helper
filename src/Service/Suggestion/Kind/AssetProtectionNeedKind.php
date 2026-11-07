<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Asset;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\AssetRepository;
use App\Service\AssetDependencyService;
use App\Service\ProtectionRequirementService;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Protection need of an asset (BSI 200-2 / ISO 27001 A.5.12): raise C/I/A
 * where the maximum principle along the dependencies (BSI 3.6) or the BIA of
 * a supported business process (RTO → availability) asks for more than the
 * asset states. Only raises; lowering stays a human call.
 */
final class AssetProtectionNeedKind extends AbstractSuggestionKind
{
    public const string KEY = 'asset.protection_need';

    private const array DIMENSIONS = ['confidentiality', 'integrity', 'availability'];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly AssetDependencyService $dependencies,
        private readonly ProtectionRequirementService $protection,
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
        return ['ROLE_ISB', 'ROLE_RISK_MANAGER', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'assets';
    }

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->assets->findByTenant($tenant) as $asset) {
            if (in_array($asset->getStatus(), ['retired', 'disposed'], true)) {
                continue;
            }
            $draft = $this->draftFor($asset, $tenant);
            if ($draft !== null) {
                yield $draft;
            }
        }
    }

    public function draftFor(Asset $asset, Tenant $tenant): ?SuggestionDraft
    {
        $current = self::current($asset);
        $proposed = [];
        $reasons = [];

        $inherited = $this->dependencies->calculateInheritedProtectionNeed($asset);
        foreach (self::DIMENSIONS as $dim) {
            $driver = $inherited['drivenBy'][$dim] ?? null;
            if ($inherited[$dim] > $current[$dim] && $driver instanceof Asset && $driver !== $asset) {
                $proposed[$dim] = $inherited[$dim];
                $reasons[$dim] = $this->text->t($tenant, 'suggestions.text.asset_protection.reason_dependency', [
                    '%asset%' => (string) $driver->getName(),
                ]);
            }
        }

        $bia = $this->protection->calculateAvailabilityRequirement($asset);
        $process = $bia['process'] ?? null;
        if (($bia['source'] ?? null) === 'bcm' && $process !== null
            && (int) $bia['value'] > max($current['availability'], $proposed['availability'] ?? 0)) {
            $proposed['availability'] = (int) $bia['value'];
            $reasons['availability'] = $this->text->t($tenant, 'suggestions.text.asset_protection.reason_bia', [
                '%process%' => (string) $process->getName(),
                '%rto%' => (int) $process->getRto(),
            ]);
        }

        if ($proposed === []) {
            return null;
        }
        ksort($proposed);

        $lines = [];
        foreach ($proposed as $dim => $value) {
            $lines[] = $this->text->t($tenant, 'suggestions.text.asset_protection.change', [
                '%dimension%' => $this->text->t($tenant, 'suggestions.text.dimension.' . $dim),
                '%from%' => $current[$dim] > 0 ? (string) $current[$dim] : '—',
                '%to%' => $value,
                '%reason%' => $reasons[$dim],
            ]);
        }

        return new SuggestionDraft(
            subject: $asset,
            title: $this->text->t($tenant, 'suggestions.text.asset_protection.title', ['%asset%' => (string) $asset->getName()]),
            rationale: implode("\n", $lines),
            payload: ['values' => $proposed],
            dedupKey: sprintf('asset:%d:%s', (int) $asset->getId(), json_encode($proposed, JSON_THROW_ON_ERROR)),
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $asset = $this->entityManager->find(Asset::class, (int) $suggestion->getSubjectId());
        if (!$asset instanceof Asset || $asset->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('asset_gone');
        }
        $values = (array) ($suggestion->getPayload()['values'] ?? []);
        $changed = false;
        foreach ($values as $dim => $value) {
            $value = (int) $value;
            $now = self::current($asset)[$dim] ?? null;
            if ($now === null || $value <= $now) {
                continue;
            }
            match ($dim) {
                'confidentiality' => $asset->setConfidentialityValue($value),
                'integrity' => $asset->setIntegrityValue($value),
                'availability' => $asset->setAvailabilityValue($value),
                default => null,
            };
            $changed = true;
        }
        if (!$changed) {
            throw new StaleSuggestionException('already_applied');
        }

        return $asset;
    }

    /** @return array{confidentiality: int, integrity: int, availability: int} */
    private static function current(Asset $asset): array
    {
        return [
            'confidentiality' => (int) ($asset->getConfidentialityValue() ?? 0),
            'integrity' => (int) ($asset->getIntegrityValue() ?? 0),
            'availability' => (int) ($asset->getAvailabilityValue() ?? 0),
        ];
    }
}
