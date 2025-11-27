<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\SystemSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: SystemSettingsRepository::class)]
#[ORM\Table(name: 'system_settings')]
#[ORM\UniqueConstraint(name: 'UNIQ_CATEGORY_KEY', columns: ['category', 'setting_key'])]
#[UniqueEntity(fields: ['category', 'key'], message: 'This setting already exists in this category')]
class SystemSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Setting category: 'application', 'email', 'auth', 'security', 'features'
     */
    #[ORM\Column(length: 50)]
    private ?string $category = null;

    /**
     * Setting key within category (e.g., 'default_locale', 'smtp_host')
     */
    #[ORM\Column(name: 'setting_key', length: 100)]
    private ?string $key = null;

    /**
     * Setting value (stored as JSON for flexibility)
     */
    #[ORM\Column(type: Types::JSON)]
    private mixed $value = null;

    /**
     * Encrypted value for sensitive data (passwords, API keys, etc.)
     * Uses Symfony's sodium encryption
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedValue = null;

    /**
     * Whether this setting contains sensitive data that should be encrypted
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isEncrypted = false;

    /**
     * Human-readable description of this setting
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    /**
     * User who last modified this setting
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getEncryptedValue(): ?string
    {
        return $this->encryptedValue;
    }

    public function setEncryptedValue(?string $encryptedValue): static
    {
        $this->encryptedValue = $encryptedValue;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function setIsEncrypted(bool $isEncrypted): static
    {
        $this->isEncrypted = $isEncrypted;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?string $updatedBy): static
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}
