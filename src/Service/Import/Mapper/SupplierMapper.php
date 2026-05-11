<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import mapper for the Supplier entity.
 *
 * Required spreadsheet columns : name
 * Optional spreadsheet columns : contactEmail, criticality (low|medium|high|critical),
 *                                 isDoraRelevant (bool via ictCriticality),
 *                                 description
 *
 * Delta match-key: name (case-insensitive).
 *
 * Note: The Supplier entity does not have a dedicated `isDoraRelevant` boolean.
 * DORA-relevance is modelled via `ictCriticality` (non_ict | important | critical).
 * When a spreadsheet row sets isDoraRelevant = true we map to ictCriticality = 'important'
 * (conservative default). Importers can supply the exact value via the `ictCriticality` column.
 */
final class SupplierMapper extends AbstractEntityMapper
{
    /** Canonical criticality values. */
    private const CRITICALITY_VALUES = ['low', 'medium', 'high', 'critical'];

    /** Canonical ICT-criticality values (DORA). */
    private const ICT_CRITICALITY_VALUES = ['non_ict', 'important', 'critical'];

    public function __construct(
        EntityManagerInterface $em,
        private readonly SupplierRepository $supplierRepository,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === 'Supplier';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $row): array
    {
        $errors   = [];
        $warnings = [];

        // ── Required ─────────────────────────────────────────────────────────
        $name = $this->resolveField($row, 'name', null);
        if (empty($name)) {
            $errors[] = 'Field "name" is required.';
        }

        // ── Contact email ─────────────────────────────────────────────────────
        $email = $this->resolveField($row, 'contactEmail', null)
            ?? $this->resolveField($row, 'email', null);
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = sprintf('Field "contactEmail" is not a valid email address (got: %s).', (string) $email);
        }

        // ── Criticality enum ──────────────────────────────────────────────────
        $criticality = $this->resolveField($row, 'criticality', null);
        if (!empty($criticality)) {
            $normalised = strtolower(trim((string) $criticality));
            if (!in_array($normalised, self::CRITICALITY_VALUES, strict: true)) {
                $warnings[] = sprintf(
                    'Field "criticality" value "%s" is unknown (accepted: %s). Defaults to "medium".',
                    $criticality,
                    implode(', ', self::CRITICALITY_VALUES),
                );
            }
        }

        // ── ICT criticality / DORA ────────────────────────────────────────────
        $ictCriticality = $this->resolveField($row, 'ictCriticality', null)
            ?? $this->resolveField($row, 'ict_criticality', null);
        if (!empty($ictCriticality)) {
            $normalised = strtolower(trim((string) $ictCriticality));
            if (!in_array($normalised, self::ICT_CRITICALITY_VALUES, strict: true)) {
                $warnings[] = sprintf(
                    'Field "ictCriticality" value "%s" is unknown (accepted: %s). It will be ignored.',
                    $ictCriticality,
                    implode(', ', self::ICT_CRITICALITY_VALUES),
                );
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, string>|null $columnMapping
     * @return array<string, mixed>
     */
    public function toEntityData(array $row, ?array $columnMapping = null): array
    {
        $get = fn (string $field): mixed => $this->resolveField($row, $field, $columnMapping);

        $data = [
            'name'        => trim((string) ($get('name') ?? '')),
            'description' => $get('description') !== null ? trim((string) $get('description')) : null,
        ];

        // Contact email (maps to Supplier::$email)
        $email = $get('contactEmail') ?? $get('email');
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['email'] = strtolower(trim((string) $email));
        }

        // Criticality enum
        $criticality = $this->castEnum($get('criticality'), self::CRITICALITY_VALUES, 'medium');
        $data['criticality'] = $criticality ?? 'medium';

        // DORA: ictCriticality takes priority over isDoraRelevant boolean shortcut
        $ictCriticality = $get('ictCriticality') ?? $get('ict_criticality');
        if (!empty($ictCriticality)) {
            $normalised = strtolower(trim((string) $ictCriticality));
            if (in_array($normalised, self::ICT_CRITICALITY_VALUES, strict: true)) {
                $data['ictCriticality'] = $normalised;
            }
        } else {
            $isDoraRelevant = $get('isDoraRelevant') ?? $get('is_dora_relevant') ?? $get('doraRelevant');
            if ($isDoraRelevant !== null && $isDoraRelevant !== '') {
                $data['ictCriticality'] = $this->castBool($isDoraRelevant) ? 'important' : 'non_ict';
            }
        }

        return $data;
    }

    /**
     * Delta-mode: find by name (case-insensitive) within tenant.
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
        $qb->select('s')
           ->from(Supplier::class, 's')
           ->where('s.tenant = :tenant')
           ->andWhere('LOWER(s.name) = LOWER(:name)')
           ->setParameter('tenant', $tenant)
           ->setParameter('name', $name)
           ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
