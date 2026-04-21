<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuidedTourStepOverrideRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sprint 13 / P5 — Tenant-/Global-Override der Default-Tour-Step-Texte.
 *
 * Die Default-Texte leben in `translations/guided_tour.{de,en}.yaml`.
 * Wenn ein Admin einen Step pro Tenant anpasst (z. B. "Hier finden Sie
 * IHRE Asset-Kategorien"), wird ein Override-Eintrag angelegt.
 *
 * Auflösungs-Reihenfolge im GuidedTourService:
 *   1. Override für (tenant_id, tour_id, step_id, locale) → gewinnt
 *   2. Globaler Override mit tenant_id = NULL (Systemweit)
 *   3. Translation-Default aus dem Language-File
 *
 * `tenant_id` NULL markiert einen System-Default-Override, der
 * nur von SUPER_ADMIN gesetzt werden darf (z. B. für SaaS-Betrieb).
 */
#[ORM\Entity(repositoryClass: GuidedTourStepOverrideRepository::class)]
#[ORM\Table(name: 'guided_tour_step_override')]
#[ORM\UniqueConstraint(
    name: 'uniq_tour_step_override',
    columns: ['tenant_id', 'tour_id', 'step_id', 'locale'],
)]
#[ORM\Index(name: 'idx_tour_step_tenant', columns: ['tenant_id'])]
class GuidedTourStepOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** NULL = System-Default-Override (SUPER_ADMIN only). */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 32)]
    private string $tourId = '';

    #[ORM\Column(length: 64)]
    private string $stepId = '';

    #[ORM\Column(length: 5)]
    private string $locale = 'de';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $titleOverride = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyOverride = null;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $updatedByEmail = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getTourId(): string
    {
        return $this->tourId;
    }

    public function setTourId(string $tourId): static
    {
        $this->tourId = $tourId;
        return $this;
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    public function setStepId(string $stepId): static
    {
        $this->stepId = $stepId;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getTitleOverride(): ?string
    {
        return $this->titleOverride;
    }

    public function setTitleOverride(?string $title): static
    {
        $this->titleOverride = $title !== null ? trim($title) ?: null : null;
        return $this;
    }

    public function getBodyOverride(): ?string
    {
        return $this->bodyOverride;
    }

    public function setBodyOverride(?string $body): static
    {
        $this->bodyOverride = $body !== null ? trim($body) ?: null : null;
        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): static
    {
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getUpdatedByEmail(): ?string
    {
        return $this->updatedByEmail;
    }

    public function setUpdatedByEmail(?string $email): static
    {
        $this->updatedByEmail = $email;
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->titleOverride === null && $this->bodyOverride === null;
    }
}
