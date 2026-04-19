<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditFreeze;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AuditFreezeRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and verifies audit freezes.
 *
 * A freeze = immutable snapshot of SoA + requirement fulfillments + risks + KPIs
 * at a chosen Stichtag, sealed with a SHA-256 hash over the canonical JSON
 * payload. The hash lets an external auditor verify weeks later that nobody
 * has tampered with the stored snapshot.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-8
 */
class AuditFreezeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditFreezeRepository $freezeRepository,
        private readonly AuditFreezeSnapshotBuilder $snapshotBuilder,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Canonical JSON encoding used for hashing.
     *
     * JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE yields a stable byte
     * representation. Sort-order of payload rows is handled by the builder.
     *
     * @param array<string,mixed> $payload
     */
    public static function canonicalEncode(array $payload): string
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($encoded === false) {
            throw new \RuntimeException('Failed to JSON-encode audit-freeze payload: ' . json_last_error_msg());
        }
        return $encoded;
    }

    /**
     * Build + persist a new freeze.
     *
     * @param list<string> $frameworkCodes
     */
    public function create(
        Tenant $tenant,
        string $name,
        DateTimeInterface $stichtag,
        array $frameworkCodes,
        string $purpose,
        ?string $notes,
        User $actor,
    ): AuditFreeze {
        $payload = $this->snapshotBuilder->build($tenant, $stichtag, $frameworkCodes);
        $hash = hash('sha256', self::canonicalEncode($payload));

        $freeze = new AuditFreeze();
        $freeze->setTenant($tenant);
        $freeze->setFreezeName($name);
        $freeze->setStichtag($stichtag);
        $freeze->setCreatedBy($actor);
        $freeze->setFrameworkCodes($frameworkCodes);
        $freeze->setPurpose($purpose);
        $freeze->setNotes($notes);
        $freeze->setPayloadJson($payload);
        $freeze->setPayloadSha256($hash);

        $this->entityManager->persist($freeze);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'admin.audit_freeze.created',
            AuditFreeze::class,
            $freeze->getId(),
            null,
            [
                'freeze_name' => $name,
                'stichtag' => $freeze->getStichtag()->format('Y-m-d'),
                'purpose' => $purpose,
                'frameworks' => $freeze->getFrameworkCodes(),
                'payload_sha256' => $hash,
            ],
            sprintf(
                'Audit-Freeze "%s" erstellt (Stichtag %s, Hash %s…)',
                $name,
                $freeze->getStichtag()->format('Y-m-d'),
                substr($hash, 0, 12)
            ),
        );

        return $freeze;
    }

    /**
     * Re-hash the stored payload and compare with the stored hash.
     * Returns true = payload unchanged since freeze creation.
     */
    public function verify(AuditFreeze $freeze): bool
    {
        $recomputed = hash('sha256', self::canonicalEncode($freeze->getPayloadJson()));
        return hash_equals($freeze->getPayloadSha256(), $recomputed);
    }

    /**
     * @return AuditFreeze[]
     */
    public function listFor(Tenant $tenant): array
    {
        return $this->freezeRepository->findByTenant($tenant);
    }
}
