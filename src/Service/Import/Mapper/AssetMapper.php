<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import mapper for the Asset entity.
 *
 * Required spreadsheet columns : name, assetType
 * Optional spreadsheet columns : classification, owner (email → User),
 *                                 confidentiality, integrity, availability (1-5),
 *                                 description
 *
 * Delta match-key: name + assetType (case-insensitive).
 */
final class AssetMapper extends AbstractEntityMapper
{
    /** Accepted CIA range. */
    private const CIA_MIN = 1;
    private const CIA_MAX = 5;

    /** Accepted data-classification values (ISO 27001). */
    private const CLASSIFICATION_VALUES = [
        'public',
        'internal',
        'confidential',
        'restricted',
    ];

    public function __construct(
        EntityManagerInterface $em,
        private readonly AssetRepository $assetRepository,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === 'Asset';
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
        $name      = $this->resolveField($row, 'name', null);
        $assetType = $this->resolveField($row, 'assetType', null)
            ?? $this->resolveField($row, 'asset_type', null);

        if (empty($name)) {
            $errors[] = 'Field "name" is required.';
        }

        if (empty($assetType)) {
            $errors[] = 'Field "assetType" is required.';
        }

        // ── CIA range validation ──────────────────────────────────────────────
        foreach (['confidentiality', 'integrity', 'availability'] as $field) {
            $raw = $this->resolveField($row, $field, null);
            if ($raw === null || $raw === '') {
                continue; // optional field
            }

            $int = $this->castInt($raw);
            if ($int === null || $int < self::CIA_MIN || $int > self::CIA_MAX) {
                $errors[] = sprintf(
                    'Field "%s" must be an integer between %d and %d (got: %s).',
                    $field,
                    self::CIA_MIN,
                    self::CIA_MAX,
                    (string) $raw,
                );
            }
        }

        // ── Owner email warning (not fatal) ───────────────────────────────────
        $owner = $this->resolveField($row, 'owner', null);
        if (!empty($owner) && !filter_var($owner, FILTER_VALIDATE_EMAIL)) {
            $warnings[] = sprintf(
                'Field "owner" looks like a name rather than an email address ("%s"). '
                . 'User lookup requires a valid email; the owner will be set to null.',
                $owner,
            );
        }

        // ── Classification validation ─────────────────────────────────────────
        $classification = $this->resolveField($row, 'classification', null)
            ?? $this->resolveField($row, 'dataClassification', null);
        if (!empty($classification)) {
            $normalised = strtolower(trim((string) $classification));
            if (!in_array($normalised, self::CLASSIFICATION_VALUES, strict: true)) {
                $warnings[] = sprintf(
                    'Field "classification" value "%s" is not a recognised value (%s). It will be ignored.',
                    $classification,
                    implode(', ', self::CLASSIFICATION_VALUES),
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
            'assetType'   => trim((string) ($get('assetType') ?? $get('asset_type') ?? '')),
            'description' => $get('description') !== null ? trim((string) $get('description')) : null,
        ];

        // CIA values — null means "not provided", caller must fill defaults
        foreach (['confidentiality', 'integrity', 'availability'] as $cia) {
            $raw = $get($cia);
            $data[$cia . 'Value'] = ($raw !== null && $raw !== '')
                ? $this->castInt($raw)
                : null;
        }

        // Data classification
        $classification = $get('classification') ?? $get('dataClassification');
        if (!empty($classification)) {
            $normalised = strtolower(trim((string) $classification));
            if (in_array($normalised, self::CLASSIFICATION_VALUES, strict: true)) {
                $data['dataClassification'] = $normalised;
            }
        }

        return $data;
    }

    /**
     * Resolve the owner email in $row to a User entity within $tenant.
     * Returns the User when found, null when absent or not found (warning already emitted by validate()).
     *
     * @param array<string, mixed> $row
     */
    public function resolveOwnerUser(array $row, Tenant $tenant): ?User
    {
        $ownerRaw = $this->resolveField($row, 'owner', null);
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
     * Delta-mode: find by name + assetType (case-insensitive).
     *
     * @param array<string, mixed> $row
     */
    public function findExisting(array $row, Tenant $tenant): ?object
    {
        $name      = trim((string) ($this->resolveField($row, 'name', null) ?? ''));
        $assetType = trim((string) (
            $this->resolveField($row, 'assetType', null)
            ?? $this->resolveField($row, 'asset_type', null)
            ?? ''
        ));

        if ($name === '' || $assetType === '') {
            return null;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('a')
           ->from(Asset::class, 'a')
           ->where('a.tenant = :tenant')
           ->andWhere('LOWER(a.name) = LOWER(:name)')
           ->andWhere('LOWER(a.assetType) = LOWER(:assetType)')
           ->setParameter('tenant', $tenant)
           ->setParameter('name', $name)
           ->setParameter('assetType', $assetType)
           ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }
}
