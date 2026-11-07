<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ObligationNoticeRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reminder a person received about their duties: the daily digest or an
 * urgent notice for a statutory hour deadline. It is the "already sent" record
 * (unique per person and key) and the person's own in-app notification.
 */
#[ORM\Entity(repositoryClass: ObligationNoticeRepository::class)]
#[ORM\Table(name: 'obligation_notice')]
#[ORM\UniqueConstraint(name: 'uniq_obligation_notice_user_key', columns: ['user_id', 'notice_key'])]
#[ORM\Index(name: 'idx_obligation_notice_user_created', columns: ['user_id', 'created_at'])]
class ObligationNotice
{
    public const string KIND_DIGEST = 'digest';
    public const string KIND_URGENT = 'urgent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'notice_key', length: 190)]
    private string $noticeKey;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 255)]
    private string $title;

    /** Path inside the application the notice links to. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dueAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Tenant $tenant,
        User $user,
        string $noticeKey,
        string $kind,
        string $title,
        ?string $link = null,
        ?DateTimeImmutable $dueAt = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->tenant = $tenant;
        $this->user = $user;
        $this->noticeKey = mb_substr($noticeKey, 0, 190);
        $this->kind = $kind;
        $this->title = mb_substr($title, 0, 255);
        $this->link = $link !== null ? mb_substr($link, 0, 255) : null;
        $this->dueAt = $dueAt;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNoticeKey(): string
    {
        return $this->noticeKey;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getDueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
