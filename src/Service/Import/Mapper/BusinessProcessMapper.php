<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BusinessProcessRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import mapper for the BusinessProcess entity.
 *
 * Required spreadsheet columns : name, criticality (critical/high/medium/low)
 * Optional spreadsheet columns : description, rto (hours), rpo (hours),
 *                                 mtpd (hours), financialImpactPerHour (decimal),
 *                                 processOwner (email → User lookup; falls back to
 *                                 plain string for processOwner field),
 *                                 dependenciesUpstream (text),
 *                                 dependenciesDownstream (text)
 *
 * Delta match-key: name (case-insensitive).
 */
final class BusinessProcessMapper extends AbstractEntityMapper
{
    /** Accepted criticality values (BSI 200-4 / ISO 22301). */
    private const CRITICALITY_VALUES = [
        'critical',
        'high',
        'medium',
        'low',
    ];

    public function __construct(
        EntityManagerInterface $em,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === 'BusinessProcess';
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
        $name        = $this->resolveField($row, 'name', null);
        $criticality = $this->resolveField($row, 'criticality', null);

        if (empty($name)) {
            $errors[] = 'Field "name" (process name) is required.';
        }

        if (empty($criticality)) {
            $errors[] = 'Field "criticality" is required.';
        } elseif (!in_array(strtolower(trim((string) $criticality)), self::CRITICALITY_VALUES, strict: true)) {
            $errors[] = sprintf(
                'Field "criticality" value "%s" is not recognised. Allowed: %s.',
                $criticality,
                implode(', ', self::CRITICALITY_VALUES),
            );
        }

        // ── Numeric field validation (must be positive integers when provided) ──
        foreach (['rto', 'rpo', 'mtpd'] as $field) {
            $raw = $this->resolveField($row, $field, null);
            if ($raw === null || $raw === '') {
                continue;
            }

            $int = $this->castInt($raw);
            if ($int === null || $int < 0) {
                $errors[] = sprintf(
                    'Field "%s" must be a non-negative integer representing hours (got: %s).',
                    $field,
                    (string) $raw,
                );
            }
        }

        // ── Financial impact validation ────────────────────────────────────────
        $fin = $this->resolveField($row, 'financialImpactPerHour', null);
        if ($fin !== null && $fin !== '') {
            $float = $this->castFloat($fin);
            if ($float === null || $float < 0) {
                $warnings[] = sprintf(
                    'Field "financialImpactPerHour" value "%s" could not be parsed as a positive decimal. '
                    . 'It will be set to null.',
                    (string) $fin,
                );
            }
        }

        // ── Owner email warning (not fatal) ───────────────────────────────────
        $owner = $this->resolveField($row, 'processOwner', null)
            ?? $this->resolveField($row, 'owner', null);
        if (!empty($owner) && !filter_var($owner, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = sprintf(
                'Field "processOwner" value "%s" is not a valid email address. '
                . 'User lookup will be skipped; the plain string will be stored as the process owner name.',
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

        $name        = trim((string) ($get('name') ?? ''));
        $criticality = strtolower(trim((string) ($get('criticality') ?? 'low')));

        // RTO / RPO / MTPD — cast to int (hours)
        $rtoRaw  = $get('rto');
        $rpoRaw  = $get('rpo');
        $mtpdRaw = $get('mtpd') ?? $get('max_ausfallzeit');

        // Financial impact
        $finRaw = $get('financialImpactPerHour');
        $fin    = ($finRaw !== null && $finRaw !== '') ? $this->castFloat($finRaw) : null;

        // Process owner: use email string as fallback for processOwner string field
        $ownerRaw = $get('processOwner') ?? $get('owner') ?? '';

        $data = [
            'name'                    => $name,
            'criticality'             => in_array($criticality, self::CRITICALITY_VALUES, strict: true)
                ? $criticality
                : 'low',
            'description'             => $get('description') !== null ? trim((string) $get('description')) : null,
            'rto'                     => $rtoRaw !== null && $rtoRaw !== '' ? $this->castInt($rtoRaw) : null,
            'rpo'                     => $rpoRaw !== null && $rpoRaw !== '' ? $this->castInt($rpoRaw) : null,
            'mtpd'                    => $mtpdRaw !== null && $mtpdRaw !== '' ? $this->castInt($mtpdRaw) : null,
            'financialImpactPerHour'  => $fin !== null ? (string) $fin : null,
            'processOwner'            => trim((string) $ownerRaw) ?: 'Imported',
            'dependenciesUpstream'    => $get('dependenciesUpstream') !== null
                ? trim((string) $get('dependenciesUpstream'))
                : null,
            'dependenciesDownstream'  => $get('dependenciesDownstream') !== null
                ? trim((string) $get('dependenciesDownstream'))
                : null,
        ];

        return $data;
    }

    /**
     * Resolve the process owner email to a User entity within $tenant.
     * If the owner field is not a valid email, returns null — the plain string
     * has already been stored in processOwner via toEntityData().
     *
     * @param array<string, mixed> $row
     */
    public function resolveOwnerUser(array $row, Tenant $tenant): ?User
    {
        $ownerRaw = $this->resolveField($row, 'processOwner', null)
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
     * Delta-mode: find by name (case-insensitive).
     *
     * @param array<string, mixed> $row
     */
    public function findExisting(array $row, Tenant $tenant): ?object
    {
        $name = trim((string) ($this->resolveField($row, 'name', null) ?? ''));

        if ($name === '') {
            return null;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('bp')
           ->from(BusinessProcess::class, 'bp')
           ->where('bp.tenant = :tenant')
           ->andWhere('LOWER(bp.name) = LOWER(:name)')
           ->setParameter('tenant', $tenant)
           ->setParameter('name', $name)
           ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
