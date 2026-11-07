<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns adopted proposals into register entries. The one write path for
 * proposals — the register page and the suggestion queue (Paket 5) both call it.
 * Every entry is written through the ORM, so the audit log records each one.
 */
final class RegisterProposalAdopter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly IsmsRegisterEntryRepository $entries,
    ) {
    }

    /**
     * @param list<RegisterProposal> $proposals
     * @return list<IsmsRegisterEntry>
     */
    public function adopt(Tenant $tenant, array $proposals, ?User $by = null): array
    {
        $today = new DateTimeImmutable('today');
        $created = [];
        $backLinks = [];
        foreach ($proposals as $proposal) {
            $entry = (new IsmsRegisterEntry($proposal->type))
                ->setTenant($tenant)
                ->setTitle(mb_substr($proposal->title, 0, 255))
                ->setDescription($proposal->description)
                ->setCategory($proposal->category)
                ->setReference($proposal->reference !== null ? mb_substr($proposal->reference, 0, 255) : null)
                ->setOrigin(mb_substr($proposal->origin, 0, 120))
                ->setNextReviewDate($today->modify(sprintf('+%d months', $proposal->type->reviewMonths())));
            $owner = $proposal->ownerUserId !== null ? $this->users->findByIdsInTenant([$proposal->ownerUserId], $tenant) : [];
            $entry->setOwnerUser($owner[0] ?? ($proposal->type === RegisterType::ROLE ? null : $by));
            foreach ($proposal->links as $field => $ids) {
                $entry->replaceLinks($field, $ids);
            }
            $this->entityManager->persist($entry);
            $created[] = $entry;
            $backLinks[] = [$entry, $proposal->backLinks];
        }
        $this->entityManager->flush();

        foreach ($backLinks as [$entry, $links]) {
            foreach ($links as $field => $ids) {
                foreach ($this->entries->findBy(['id' => $ids, 'tenant' => $tenant]) as $target) {
                    $target->replaceLinks($field, [...$target->linkedIds($field), (int) $entry->getId()]);
                }
            }
        }
        if ($backLinks !== []) {
            $this->entityManager->flush();
        }

        return $created;
    }
}
