<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\AlvaHintDismissal;
use App\Entity\ApiToken;
use App\Entity\DashboardLayout;
use App\Entity\IdentityProviderUserMapping;
use App\Entity\MfaToken;
use App\Entity\ObligationNotice;
use App\Entity\Person;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Entity\UserSession;
use App\Entity\UserTenantAssignment;
use App\Service\AuditLogger;
use App\Service\Upload\StoredFilePath;
use Doctrine\ORM\EntityManagerInterface;

/**
 * S29: "delete a user" is erasure of the person (GDPR Art. 17), not of the
 * evidence they left (ISO 27001 7.5.3). A hard delete used to cascade their
 * policy acknowledgements, training participations, comments and four-eyes
 * requests, or failed on any other reference — so an admin could either keep
 * the person or the evidence, never the evidence without the person.
 *
 * Erasure:
 * 1. removes the account's personal settings ({@see PERSONAL_SETTINGS}: tokens,
 *    sessions, memberships, dashboards, reminders sent — rows that exist only
 *    for this account and that a delete has always cascaded);
 * 2. anonymises the account: name, e-mail, SSO identifiers, contact data,
 *    picture, password, roles; deactivated, unlinked from its person record;
 * 3. deletes the row only when nothing else points at it — otherwise the
 *    anonymised account stays, and every acknowledgement, participation,
 *    comment and approval keeps its (now anonymous) author.
 *
 * One `user_erased` audit row, with a hash of the old address instead of it.
 * Durable test: tests/Service/User/UserErasureTest.php (every foreign key on
 * `users` either a personal setting or surviving the erasure).
 */
final readonly class UserErasure
{
    /**
     * Rows that belong to the account alone: their foreign key to `users` is
     * ON DELETE CASCADE, and erasure removes them. Every other reference is
     * evidence and survives.
     *
     * @var list<class-string>
     */
    public const array PERSONAL_SETTINGS = [
        ApiToken::class,
        MfaToken::class,
        UserSession::class,
        UserTenantAssignment::class,
        IdentityProviderUserMapping::class,
        DashboardLayout::class,
        AlvaHintDismissal::class,
        PushSubscription::class,
        ObligationNotice::class,
    ];

    public const string ANONYMOUS_FIRST_NAME = 'Anonymisiert';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private StoredFilePath $storedFilePath,
    ) {
    }

    /** @return bool true when the account row itself was deleted (nothing pointed at it) */
    public function erase(User $user): bool
    {
        $id = $user->getId();
        if ($id === null) {
            // @intentional-assertion: callers pass a loaded account; an unsaved one is a programming error
            throw new \InvalidArgumentException('Only a stored account can be erased.');
        }
        $emailHash = hash('sha256', mb_strtolower((string) $user->getEmail()));

        // Rows of every organisation the account was a member of.
        $filters = $this->entityManager->getFilters();
        $tenantFilter = $filters->isEnabled('tenant_filter');
        if ($tenantFilter) {
            $filters->suspend('tenant_filter');
        }
        try {
            $deleted = $this->anonymise($user, $id);
        } finally {
            if ($tenantFilter) {
                $filters->restore('tenant_filter');
            }
        }

        $this->auditLogger->logCustom(
            'user_erased',
            'User',
            $id,
            ['email_sha256' => $emailHash],
            ['anonymised' => true, 'row_deleted' => $deleted],
            sprintf('User #%d erased (GDPR Art. 17); %s', $id, $deleted ? 'row deleted' : 'anonymised account kept as author of evidence'),
        );

        return $deleted;
    }

    private function anonymise(User $user, int $id): bool
    {
        foreach (self::PERSONAL_SETTINGS as $class) {
            $this->entityManager->createQueryBuilder()
                ->delete($class, 's')
                ->where('s.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->execute();
        }
        foreach ($this->entityManager->getRepository(Person::class)->findBy(['linkedUser' => $user]) as $person) {
            $person->setLinkedUser(null);
        }

        $this->storedFilePath->deletePublicUpload($user->getProfilePicture(), StoredFilePath::USER_AVATARS);
        $user->setEmail(sprintf('erased-%d@erased.invalid', $id))
            ->setFirstName(self::ANONYMOUS_FIRST_NAME)
            ->setLastName('#' . $id)
            ->setPassword(null)
            ->setRoles([])
            ->setIsActive(false)
            ->setAzureObjectId(null)
            ->setAzureTenantId(null)
            ->setAzureMetadata(null)
            ->setSsoExternalId(null)
            ->setSsoProvider(null)
            ->setDepartment(null)
            ->setJobTitle(null)
            ->setPhoneNumber(null)
            ->setProfilePicture(null)
            ->setCompetencies(null)
            ->setLastLoginAt(null);
        foreach ($user->getCustomRoles()->toArray() as $role) {
            $user->removeCustomRole($role);
        }
        $this->entityManager->flush();

        if ($this->referenceCount($user) !== 0) {
            return false;
        }
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Rows of other classes that point at the account (owning to-one
     * associations); ON DELETE SET NULL ones are not counted, they survive a
     * delete anyway. Call with the tenant filter suspended.
     */
    private function referenceCount(User $user): int
    {
        $count = 0;
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass || \in_array($metadata->getName(), self::PERSONAL_SETTINGS, true)) {
                continue;
            }
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                if (!$mapping->isToOneOwningSide() || !is_a(User::class, $mapping->targetEntity, true)) {
                    continue;
                }
                if (strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? '')) === 'SET NULL') {
                    continue;
                }
                $count += (int) $this->entityManager->createQueryBuilder()
                    ->select('COUNT(r)')
                    ->from($metadata->getName(), 'r')
                    ->where(sprintf('r.%s = :user', $field))
                    ->setParameter('user', $user)
                    ->getQuery()
                    ->getSingleScalarResult();
            }
        }

        return $count;
    }
}
