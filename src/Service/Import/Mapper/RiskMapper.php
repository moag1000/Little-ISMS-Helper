<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import mapper for the Risk entity.
 *
 * Required spreadsheet columns : name (maps to Risk.title), category
 * Optional spreadsheet columns : description, threatSource (maps to threat),
 *                                 vulnerability, inherentImpact (1-5),
 *                                 inherentLikelihood (1-5),
 *                                 treatmentStrategy (avoid/reduce/transfer/accept),
 *                                 riskOwner (email → User), requiresDpia (bool)
 *
 * Note: Risk entity uses `title` for name and `probability` for inherentLikelihood.
 * The mapper accepts both conventions.
 *
 * Delta match-key: name (title) + category (case-insensitive).
 */
final class RiskMapper extends AbstractEntityMapper
{
    /** Accepted impact/likelihood range (ISO 27005). */
    private const SCORE_MIN = 1;
    private const SCORE_MAX = 5;

    /** Accepted category values (ISO 27005:2022 Section 8.2.3). */
    private const CATEGORY_VALUES = [
        'financial',
        'operational',
        'compliance',
        'strategic',
        'reputational',
        'security',
    ];

    /** Accepted treatment strategy values. */
    private const TREATMENT_VALUES = [
        'avoid',
        'reduce',
        'mitigate',
        'transfer',
        'accept',
    ];

    /** Mapping from import aliases to TreatmentStrategy enum values. */
    private const TREATMENT_ALIAS_MAP = [
        'avoid'    => 'avoid',
        'reduce'   => 'mitigate',
        'mitigate' => 'mitigate',
        'transfer' => 'transfer',
        'accept'   => 'accept',
    ];

    public function __construct(
        EntityManagerInterface $em,
        private readonly RiskRepository $riskRepository,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === 'Risk';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $row): array
    {
        $errors   = [];
        $warnings = [];

        // ── Required fields ──────────────────────────────────────────────────
        $name     = $this->resolveField($row, 'name', null)
            ?? $this->resolveField($row, 'title', null);
        $category = $this->resolveField($row, 'category', null);

        if (empty($name)) {
            $errors[] = 'Field "name" (risk title) is required.';
        }

        if (empty($category)) {
            $errors[] = 'Field "category" is required.';
        } elseif (!in_array(strtolower(trim((string) $category)), self::CATEGORY_VALUES, strict: true)) {
            $errors[] = sprintf(
                'Field "category" value "%s" is not recognised. Allowed: %s.',
                $category,
                implode(', ', self::CATEGORY_VALUES),
            );
        }

        // ── Impact / Likelihood range validation ──────────────────────────────
        foreach ([
            'inherentImpact'      => 'inherentImpact',
            'inherentLikelihood'  => 'inherentLikelihood',
            'impact'              => 'inherentImpact',
            'likelihood'          => 'inherentLikelihood',
        ] as $alias => $fieldLabel) {
            $raw = $this->resolveField($row, $alias, null);
            if ($raw === null || $raw === '') {
                continue;
            }

            $int = $this->castInt($raw);
            if ($int === null || $int < self::SCORE_MIN || $int > self::SCORE_MAX) {
                $errors[] = sprintf(
                    'Field "%s" must be an integer between %d and %d (got: %s).',
                    $fieldLabel,
                    self::SCORE_MIN,
                    self::SCORE_MAX,
                    (string) $raw,
                );
            }

            break; // only validate whichever alias matched first
        }

        // ── Treatment strategy validation ─────────────────────────────────────
        $treatment = $this->resolveField($row, 'treatmentStrategy', null)
            ?? $this->resolveField($row, 'treatment', null);
        if (!empty($treatment)) {
            $normalised = strtolower(trim((string) $treatment));
            if (!in_array($normalised, self::TREATMENT_VALUES, strict: true)) {
                $warnings[] = sprintf(
                    'Field "treatmentStrategy" value "%s" is not recognised (%s). Defaulting to "mitigate".',
                    $treatment,
                    implode(', ', self::TREATMENT_VALUES),
                );
            }
        }

        // ── Owner email warning (not fatal) ───────────────────────────────────
        $owner = $this->resolveField($row, 'riskOwner', null)
            ?? $this->resolveField($row, 'owner', null);
        if (!empty($owner) && !filter_var($owner, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = sprintf(
                'Field "riskOwner" looks like a name rather than an email address ("%s"). '
                . 'User lookup requires a valid email; the owner will be set to null.',
                $owner,
            );
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed>       $row
     * @param array<string, string>|null $columnMapping
     * @return array<string, mixed>
     */
    public function toEntityData(array $row, ?array $columnMapping = null): array
    {
        $get = fn (string $field): mixed => $this->resolveField($row, $field, $columnMapping);

        // name → Risk.title
        $name = $get('name') ?? $get('title') ?? '';

        // impact → Risk.impact (inherentImpact is import alias)
        $impactRaw = $get('inherentImpact') ?? $get('impact');
        $impact    = ($impactRaw !== null && $impactRaw !== '') ? $this->castInt($impactRaw) : null;

        // likelihood → Risk.probability (inherentLikelihood is import alias)
        $likelihoodRaw  = $get('inherentLikelihood') ?? $get('likelihood');
        $likelihood     = ($likelihoodRaw !== null && $likelihoodRaw !== '') ? $this->castInt($likelihoodRaw) : null;

        // treatment strategy — map aliases to TreatmentStrategy enum values
        $treatmentRaw = $get('treatmentStrategy') ?? $get('treatment') ?? 'mitigate';
        $treatmentKey = strtolower(trim((string) $treatmentRaw));
        $treatmentVal = self::TREATMENT_ALIAS_MAP[$treatmentKey] ?? 'mitigate';

        // requiresDpia maps to Risk.requiresDPIA
        $dpiaRaw = $get('requiresDpia') ?? $get('dpia');

        $data = [
            'title'             => trim((string) $name),
            'category'          => strtolower(trim((string) ($get('category') ?? ''))),
            'description'       => $get('description') !== null ? trim((string) $get('description')) : '',
            'threat'            => $get('threatSource') !== null ? trim((string) $get('threatSource')) : null,
            'vulnerability'     => $get('vulnerability') !== null ? trim((string) $get('vulnerability')) : null,
            'impact'            => $impact,
            'probability'       => $likelihood,
            'treatmentStrategy' => $treatmentVal,
            'requiresDPIA'      => $dpiaRaw !== null ? $this->castBool($dpiaRaw) : false,
        ];

        return $data;
    }

    /**
     * Resolve the risk owner email to a User entity within $tenant.
     *
     * @param array<string, mixed> $row
     */
    public function resolveOwnerUser(array $row, Tenant $tenant): ?User
    {
        $ownerRaw = $this->resolveField($row, 'riskOwner', null)
            ?? $this->resolveField($row, 'owner', null);

        if (empty($ownerRaw) || !filter_var($ownerRaw, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        /** @var User|null $user */
        $user = $this->userRepository->findOneBy([
            'email'  => strtolower(trim((string) $ownerRaw)),
            'tenant' => $tenant,
        ]);

        return $user;
    }

    /**
     * Delta-mode: find by title + category (case-insensitive).
     *
     * @param array<string, mixed> $row
     */
    public function findExisting(array $row, Tenant $tenant): ?object
    {
        $title    = trim((string) ($this->resolveField($row, 'name', null)
            ?? $this->resolveField($row, 'title', null) ?? ''));
        $category = trim((string) ($this->resolveField($row, 'category', null) ?? ''));

        if ($title === '' || $category === '') {
            return null;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('r')
           ->from(Risk::class, 'r')
           ->where('r.tenant = :tenant')
           ->andWhere('LOWER(r.title) = LOWER(:title)')
           ->andWhere('LOWER(r.category) = LOWER(:category)')
           ->setParameter('tenant', $tenant)
           ->setParameter('title', $title)
           ->setParameter('category', strtolower($category))
           ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
