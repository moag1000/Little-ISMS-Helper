<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * S20 contract: nobody without manager scope makes themselves responsible for
 * a record — whatever slot a deadline source reads and whatever path writes it.
 *
 * Responsibility is a right (`RECORD_RESPONSIBLE`: four-eyes approval, risk
 * treatment decision, statutory report, closing a breach). The slots are not
 * listed here: for every entity a deadline source knows, every User/Person
 * association is set to the probing person's account (or Person) and kept as
 * a slot when the source then names that person as holder or deputy. For each
 * slot that makes the person responsible, the save must be refused for a
 * plain user and pass for a manager. A new source, a new slot or a new
 * deputies list is covered the day it is added; a guard that misses to-many
 * slots, Person-typed slots or a class turns this test red.
 *
 * The flush is the one place every write passes (form, REST, MCP save_record,
 * import), so refusing there covers all of them; the web and API paths have
 * their own test ({@see \App\Tests\Security\SelfAssignmentPathsTest}).
 */
final class ResponsibleSlotWriteContractTest extends KernelTestCase
{
    /** Slots that must be among the discovered ones — the probe is never vacuous. */
    private const array MUST_FIND = [
        'App\Entity\Incident::responsiblePerson',
        'App\Entity\InternalAudit::leadAuditorUser',
        'App\Entity\InternalAudit::auditTeamMembers',
        'App\Entity\FourEyesApprovalRequest::requestedApproverPerson',
        'App\Entity\DataSubjectRequest::assignedTo',
        'App\Entity\Risk::riskOwner',
    ];

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $actor;
    private Person $actorPerson;
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(5));
        $this->tenant = (new Tenant())->setName('Slot ' . $id)->setCode('slot_' . $id);
        $this->em->persist($this->tenant);
        $this->actor = $this->user('slot-user-' . $id, ['ROLE_USER']);
        $this->manager = $this->user('slot-manager-' . $id, ['ROLE_MANAGER']);
        $this->actorPerson = (new Person())->setFullName('Slot Person ' . $id)->setTenant($this->tenant)->setLinkedUser($this->actor);
        $this->em->persist($this->actorPerson);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('security.token_storage')->setToken(null);
        parent::tearDown();
    }

    #[Test]
    public function aPlainUserCannotMakeThemselvesResponsibleThroughAnySlot(): void
    {
        $registry = static::getContainer()->get(DeadlineSourceRegistry::class);
        $tested = [];
        $skipped = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass || !$meta->hasAssociation('tenant')
                || $meta->getReflectionClass()->isAbstract() || self::personReferences($meta) === []) {
                continue;
            }
            $class = $meta->getName();
            $factory = new ContractRecordFactory($this->em);
            try {
                $record = $factory->create($class, $this->tenant, 'slot probe');
            } catch (\Throwable $e) {
                $skipped[$class] = 'not buildable: ' . $e->getMessage();
                $this->reopen();
                continue;
            }
            $sources = $registry->allForRecord($record);
            if ($sources === []) {
                $this->reopen(); // drops the record and what the factory built for it
                continue;
            }
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                $skipped[$class] = 'not storable: ' . $e->getMessage();
                $this->reopen();
                continue;
            }

            foreach (self::personReferences($meta) as $field => $toMany) {
                $value = $this->valueNamingActor($meta, $field);
                if (!$this->sourcesName($sources, $record, $meta, $field, $value)) {
                    continue; // no source reads it: not a slot
                }
                if (!$this->wouldBeResponsible($record, $meta, $field, $value)) {
                    continue; // another holder resolves first (a deputy only counts without one)
                }
                $label = $class . '::' . $field;
                $stored = $meta->getFieldValue($record, $field);
                $this->login($this->actor);
                $this->assign($meta, $record, $field, $value);
                try {
                    $this->em->flush();
                    self::fail(sprintf('%s: a plain user made themselves responsible (S20).', $label));
                } catch (AccessDeniedException) {
                    // refused, as it must be
                } finally {
                    $this->login(null);
                    $this->restore($meta, $record, $field, $value, $stored);
                }
                self::assertTrue($this->em->isOpen(), $label . ': the refusal closed the EntityManager');
                $tested[$label] = true;
            }
            $this->login(null);
            $this->reopen();
        }

        foreach (self::MUST_FIND as $slot) {
            self::assertArrayHasKey($slot, $tested, sprintf(
                'Slot %s was not probed (skipped: %s)', $slot, json_encode($skipped, JSON_UNESCAPED_SLASHES),
            ));
        }
        self::assertGreaterThanOrEqual(25, count($tested), 'Too few slots probed: ' . implode(', ', array_keys($tested)));
    }

    #[Test]
    public function aManagerMayAssignAndTheHolderMayHandOn(): void
    {
        $factory = new ContractRecordFactory($this->em);
        $incident = $factory->create(\App\Entity\Incident::class, $this->tenant, 'slot manager');
        $this->em->flush();

        $this->login($this->manager);
        $incident->setResponsiblePerson($this->actorPerson);
        $this->em->flush(); // manager scope: allowed

        $other = (new Person())->setFullName('Slot Other')->setTenant($this->tenant);
        $this->em->persist($other);
        $this->em->flush();

        $this->login($this->actor);
        $incident->setResponsiblePerson($other);
        $this->em->flush(); // the holder hands the incident on: allowed
        self::assertSame($other, $incident->getResponsiblePerson());
    }

    #[Test]
    public function onlyAManagerLinksAPersonToAnAccount(): void
    {
        $dpo = (new Person())->setFullName('Slot DPO')->setTenant($this->tenant);
        $this->em->persist($dpo);
        $this->em->flush();

        $this->login($this->actor);
        $dpo->setLinkedUser($this->actor);
        try {
            $this->em->flush();
            self::fail('A plain user linked a person to their own account (S20).');
        } catch (AccessDeniedException) {
        }
        $dpo->setLinkedUser(null);

        $fresh = (new Person())->setFullName('Slot Fresh')->setTenant($this->tenant)->setLinkedUser($this->actor);
        $this->em->persist($fresh);
        try {
            $this->em->flush();
            self::fail('A plain user created a person linked to an account (S20).');
        } catch (AccessDeniedException) {
        }
        $this->em->detach($fresh);

        $this->login($this->manager);
        $dpo->setLinkedUser($this->actor);
        $this->em->flush();
        self::assertSame($this->actor, $dpo->getLinkedUser());
    }

    /** @return array<string, bool> field → to-many */
    private static function personReferences(ClassMetadata $meta): array
    {
        $fields = [];
        foreach ($meta->getAssociationNames() as $field) {
            $target = $meta->getAssociationTargetClass($field);
            $owning = $meta->getAssociationMapping($field)->isOwningSide();
            if ($owning && (is_a($target, User::class, true) || is_a($target, Person::class, true))) {
                $fields[$field] = $meta->isCollectionValuedAssociation($field);
            }
        }

        return $fields;
    }

    private function valueNamingActor(ClassMetadata $meta, string $field): User|Person
    {
        return is_a($meta->getAssociationTargetClass($field), Person::class, true) ? $this->actorPerson : $this->actor;
    }

    /** @param list<\App\Service\Deadline\DeadlineSource> $sources */
    private function sourcesName(array $sources, object $record, ClassMetadata $meta, string $field, User|Person $value): bool
    {
        $this->assign($meta, $record, $field, $value);
        try {
            foreach ($sources as $source) {
                try {
                    $responsibility = $source->responsibleOf($record);
                } catch (\Throwable) {
                    continue;
                }
                foreach ([...$responsibility->holders, ...$responsibility->deputies] as $named) {
                    if ($named === $value) {
                        return true;
                    }
                }
            }

            return false;
        } finally {
            $this->unassign($meta, $record, $field, $value);
        }
    }

    private function wouldBeResponsible(object $record, ClassMetadata $meta, string $field, User|Person $value): bool
    {
        $resolver = static::getContainer()->get(ResponsibilityResolver::class);
        $this->assign($meta, $record, $field, $value);
        try {
            foreach ($resolver->responsibleFor($record) as $user) {
                if ($user === $this->actor) {
                    return true;
                }
            }

            return false;
        } finally {
            $this->unassign($meta, $record, $field, $value);
        }
    }

    private function assign(ClassMetadata $meta, object $record, string $field, User|Person $value): void
    {
        $current = $meta->getFieldValue($record, $field);
        if ($meta->isCollectionValuedAssociation($field)) {
            if ($current instanceof Collection && !$current->contains($value)) {
                $current->add($value);
            }

            return;
        }
        $meta->setFieldValue($record, $field, $value);
    }

    private function unassign(ClassMetadata $meta, object $record, string $field, User|Person $value): void
    {
        $current = $meta->getFieldValue($record, $field);
        if ($meta->isCollectionValuedAssociation($field)) {
            if ($current instanceof Collection) {
                $current->removeElement($value);
                if ($current instanceof \Doctrine\ORM\PersistentCollection) {
                    $current->setDirty(false);
                }
            }

            return;
        }
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($record)[$field] ?? null;
        $meta->setFieldValue($record, $field, $original);
    }

    /**
     * Back to the stored row: a refused flush has already copied the new value into the
     * unit of work's original data (change sets are computed before onFlush).
     */
    private function restore(ClassMetadata $meta, object $record, string $field, User|Person $value, mixed $stored): void
    {
        if ($meta->isCollectionValuedAssociation($field)) {
            $this->unassign($meta, $record, $field, $value);

            return;
        }
        $meta->setFieldValue($record, $field, $stored);
        $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($record), $field, $stored);
    }

    private function login(?User $user): void
    {
        static::getContainer()->get('security.token_storage')->setToken(
            $user === null ? null : new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
    }

    private function reopen(): void
    {
        if (!$this->em->isOpen()) {
            static::getContainer()->get('doctrine')->resetManager();
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
        }
        $this->em->clear();
        // The probe reads its own rows back by id; the request filters (organisation, archive) are not under test.
        foreach (array_keys($this->em->getFilters()->getEnabledFilters()) as $filter) {
            $this->em->getFilters()->disable($filter);
        }
        $this->tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        $this->actor = $this->em->find(User::class, $this->actor->getId());
        $this->manager = $this->em->find(User::class, $this->manager->getId());
        $this->actorPerson = $this->em->find(Person::class, $this->actorPerson->getId());
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '@example.test')
            ->setFirstName('Slot')
            ->setLastName($name)
            ->setRoles($roles)
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
