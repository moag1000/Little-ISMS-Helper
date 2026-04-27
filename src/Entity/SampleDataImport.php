<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SampleDataImportRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks entities created via sample-data imports so they can later be removed
 * in a targeted way (no guesswork, no cascade-delete of user data).
 */
#[ORM\Entity(repositoryClass: SampleDataImportRepository::class)]
#[ORM\Table(name: 'sample_data_import')]
#[ORM\Index(name: 'idx_sample_key_tenant', columns: ['sample_key', 'tenant_id'])]
class SampleDataImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $sampleKey = '';

    #[ORM\Column(length: 255)]
    private string $entityClass = '';

    #[ORM\Column]
    private int $entityId = 0;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $importedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $importedBy = null;

    public function __construct()
    {
        $this->importedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSampleKey(): string { return $this->sampleKey; }
    public function setSampleKey(string $key): static { $this->sampleKey = $key; return $this; }

    public function getEntityClass(): string { return $this->entityClass; }
    public function setEntityClass(string $class): static { $this->entityClass = $class; return $this; }

    public function getEntityId(): int { return $this->entityId; }
    public function setEntityId(int $id): static { $this->entityId = $id; return $this; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getImportedAt(): DateTimeImmutable { return $this->importedAt; }

    public function getImportedBy(): ?User { return $this->importedBy; }
    public function setImportedBy(?User $user): static { $this->importedBy = $user; return $this; }
}
