<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AlvaHintRenderCountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant render counter for proactive Alva-Fee hints.
 *
 * Without this counter the dismissal stats are misleading: high
 * dismissal counts on a hint that fires constantly aren't the same
 * problem as on one that fires rarely. With render + dismiss data
 * the admin dashboard can show an effective "noise quotient".
 *
 * One row per (tenant, hintKey). Increments via INSERT ... ON
 * DUPLICATE KEY UPDATE in the service so we stay at one query per
 * hinted page-render.
 */
#[ORM\Entity(repositoryClass: AlvaHintRenderCountRepository::class)]
#[ORM\Table(name: 'alva_hint_render_count')]
#[ORM\UniqueConstraint(name: 'uq_alva_hint_render_count', columns: ['tenant_id', 'hint_key'])]
#[ORM\Index(name: 'idx_alva_hint_render_tenant', columns: ['tenant_id'])]
class AlvaHintRenderCount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    private ?string $hintKey = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $renderCount = 0;

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): self { $this->tenant = $tenant; return $this; }
    public function getHintKey(): ?string { return $this->hintKey; }
    public function setHintKey(string $hintKey): self { $this->hintKey = $hintKey; return $this; }
    public function getRenderCount(): int { return $this->renderCount; }
    public function setRenderCount(int $renderCount): self { $this->renderCount = $renderCount; return $this; }
}
