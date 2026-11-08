<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ComplianceMapping;
use App\Entity\Tenant;
use App\Entity\Workflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may change the compliance mappings every organisation uses.
 *
 * A mapping between two global catalogue requirements (owner NULL) feeds the fulfilment
 * of every organisation: organisation A downgrading ISO → NIS-2 lowers B's values in the
 * next night. On an instance with more than one organisation only the super admin
 * changes them. With a single organisation nobody else is affected, so the screens' own
 * role rules apply unchanged. A mapping that belongs to an
 * organisation (an uploaded requirement) is that organisation's and passes here; the
 * screens check its visibility (MappingAccessGuard) and roles as before.
 *
 * Attribute WRITE: subject null (the action creates or changes global mappings) or a
 * ComplianceMapping. The Doctrine backstop GlobalMappingWriteGuard asks the same question
 * on every flush.
 */
final class GlobalCatalogueVoter extends Voter
{
    public const string WRITE = 'GLOBAL_CATALOGUE_WRITE';

    private ?int $tenantCount = null;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::WRITE
            && ($subject === null || $subject instanceof ComplianceMapping || $subject instanceof Workflow);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($subject instanceof ComplianceMapping && $subject->getOwnerTenant() !== null) {
            return true;
        }
        // An organisation's own workflow definition; seeded ones (no organisation) are shared.
        if ($subject instanceof Workflow && $subject->getTenant() !== null) {
            return true;
        }
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        return $this->isSingleOrganisation();
    }

    private function isSingleOrganisation(): bool
    {
        // Plain SQL: the Doctrine tenant filter does not apply to Tenant, but a count of
        // all rows must not depend on any filter.
        $this->tenantCount ??= (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->entityManager->getClassMetadata(Tenant::class)->getTableName(),
        );

        return $this->tenantCount <= 1;
    }
}
