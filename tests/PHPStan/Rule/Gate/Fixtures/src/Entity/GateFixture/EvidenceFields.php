<?php

declare(strict_types=1);

namespace App\Entity\GateFixture;

use App\Attribute\ApiReadOnlyEvidence;
use App\Attribute\CreateOnly;
use App\Attribute\FrozenInEndState;
use App\Attribute\ManagerOrResponsibleField;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Gate 105 fixture: every `expect:` line is an evidence-named field that says nobody owns it. */
#[ORM\Entity]
class EvidenceFields
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null; // expect: gate105.evidenceField

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiReadOnlyEvidence('the approve action')]
    private ?\DateTimeImmutable $approvedStamped = null;

    #[ORM\ManyToOne]
    private ?object $verifiedBy = null; // expect: gate105.evidenceField

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $actualCompletionDate = null; // expect: gate105.evidenceField

    #[ORM\Column(type: Types::DECIMAL, nullable: true)]
    #[FrozenInEndState('result of a sealed record')]
    private ?string $actualRpoAchieved = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[ManagerOrResponsibleField('set by the holder')]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[CreateOnly('fixed at creation')]
    private ?\DateTimeInterface $signedAt = null;

    // @evidence-ok: the supplier's own statement, no workflow of ours owns it
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $certifiedOn = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 100)]
    private string $name = '';

    // not persisted: a derived accessor field is not evidence storage
    private ?\DateTimeImmutable $closedAt = null;
}
