<?php

declare(strict_types=1);

namespace App\Service\Supplier;

use App\Entity\SupplierCriticalityLevel;
use App\Entity\Tenant;
use App\Repository\SupplierCriticalityLevelRepository;
use App\Twig\BadgeExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The organisation's supplier criticality levels as the supplier pages show them
 * (Admin > Lieferanten-Kritikalität): label in the user's language, the colour the admin
 * picked, and the level marked as default for new suppliers.
 *
 * Without configured levels the four canonical levels apply (critical/high/medium/low).
 */
final class SupplierCriticalityLabels implements ResetInterface
{
    /** Canonical levels: code => [German label, English label, Bootstrap colour]. */
    private const array FALLBACK = [
        'critical' => ['Kritisch', 'Critical', 'danger'],
        'high' => ['Hoch', 'High', 'warning'],
        'medium' => ['Mittel', 'Medium', 'info'],
        'low' => ['Gering', 'Low', 'secondary'],
    ];

    /** @var array<int, list<SupplierCriticalityLevel>> */
    private array $cache = [];

    public function __construct(
        private readonly SupplierCriticalityLevelRepository $repository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Form choices, label => code, in the configured order.
     *
     * @return array<string, string>
     */
    public function choices(?Tenant $tenant): array
    {
        $levels = $this->levels($tenant);
        if ($levels === []) {
            $choices = [];
            foreach (self::FALLBACK as $code => [$de, $en]) {
                $choices[$this->isGerman() ? $de : $en] = $code;
            }

            return $choices;
        }

        $choices = [];
        foreach ($levels as $level) {
            $choices[$level->getLabel($this->locale())] = (string) $level->getCode();
        }

        return $choices;
    }

    /**
     * Label and Aurora pill tone for a stored criticality code.
     *
     * @return array{label: string, tone: string}
     */
    public function describe(?string $code, ?Tenant $tenant): array
    {
        if ($code === null || $code === '') {
            return ['label' => '—', 'tone' => 'neutral'];
        }
        foreach ($this->levels($tenant) as $level) {
            if ($level->getCode() === $code) {
                return [
                    'label' => $level->getLabel($this->locale()),
                    'tone' => BadgeExtension::auroraTone($level->getColor()),
                ];
            }
        }
        if (isset(self::FALLBACK[$code])) {
            [$de, $en, $colour] = self::FALLBACK[$code];

            return ['label' => $this->isGerman() ? $de : $en, 'tone' => BadgeExtension::auroraTone($colour)];
        }

        return ['label' => $code, 'tone' => 'neutral'];
    }

    /** Code of the level marked as default for new suppliers, null without configured levels. */
    public function defaultCode(?Tenant $tenant): ?string
    {
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return $this->repository->findDefaultForTenant($tenant)?->getCode();
    }

    /** @return list<SupplierCriticalityLevel> */
    private function levels(?Tenant $tenant): array
    {
        if (!$tenant instanceof Tenant || $tenant->getId() === null) {
            return [];
        }

        return $this->cache[$tenant->getId()] ??= array_values($this->repository->findActiveByTenant($tenant));
    }

    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'de';
    }

    private function isGerman(): bool
    {
        return $this->locale() === 'de';
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
