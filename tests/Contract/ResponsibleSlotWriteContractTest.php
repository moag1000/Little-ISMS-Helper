<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Responsibility\SelfAssignmentCheck;
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
 * S28 (F-04) adds the create path: classes whose responsibility opens an action carry
 * #[ResponsibilityGrantsAuthority] (found from controllers, lifecycle and approval YAML),
 * and creating one with oneself in charge is refused for a plain user, allowed for a manager.
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
                'Slot %s was not probed (skipped: %s)',
                $slot,
                json_encode($skipped, JSON_UNESCAPED_SLASHES),
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

    /**
     * S28 (F-04): every class whose responsibility opens an action — a controller
     * action "role OR responsible", a lifecycle step `responsible: true`, an approval
     * step `approver_role: owner` — carries #[ResponsibilityGrantsAuthority], so a
     * plain user cannot create it with themselves in charge. Found here, not listed.
     */
    #[Test]
    public function everyClassWhoseResponsibilityGrantsAnActionCarriesTheAttribute(): void
    {
        $found = self::authorityClasses();
        foreach (['App\Entity\Incident', 'App\Entity\Risk', 'App\Entity\DataSubjectRequest', 'App\Entity\DataBreach'] as $must) {
            self::assertArrayHasKey($must, $found, 'Discovery missed ' . $must);
        }
        $missing = [];
        foreach ($found as $class => $where) {
            if (isset(self::NOT_AUTHORITY[$class])) {
                continue;
            }
            if (!SelfAssignmentCheck::grantsAuthority($class)) {
                $missing[] = sprintf('%s (%s)', $class, $where);
            }
        }
        self::assertSame([], $missing, 'Responsibility for these opens an action; add #[ResponsibilityGrantsAuthority] (S28).');
    }

    /**
     * S28 (F-04): creating a record of such a class with oneself in a slot that makes
     * one responsible is refused for a plain user and allowed for a manager — every slot
     * the resolver reads, found by probing like the update test above.
     */
    #[Test]
    public function aPlainUserCannotCreateAnAuthorityRecordWithThemselvesInCharge(): void
    {
        $tested = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();
            if ($meta->isMappedSuperclass || $meta->getReflectionClass()->isAbstract()
                || !SelfAssignmentCheck::grantsAuthority($class)) {
                continue;
            }
            foreach (self::personReferences($meta) as $field => $toMany) {
                foreach (['actor', 'manager'] as $who) {
                    $creator = $who === 'actor' ? $this->actor : $this->manager; // fresh after reopen()
                    try {
                        $record = (new ContractRecordFactory($this->em))->create($class, $this->tenant, 'slot create');
                    } catch (\Throwable) {
                        $this->reopen();
                        continue 3;
                    }
                    $value = $this->valueNamingActor($meta, $field);
                    if ($creator === $this->manager) {
                        $value = is_a($meta->getAssociationTargetClass($field), Person::class, true)
                            ? $this->managerPerson()
                            : $this->manager;
                    }
                    if (!$this->wouldBeResponsible($record, $meta, $field, $value, $creator)) {
                        $this->reopen();
                        continue 2; // no source makes the slot's holder responsible
                    }
                    $this->assign($meta, $record, $field, $value);
                    $label = $class . '::' . $field;
                    $this->login($creator);
                    try {
                        $this->em->flush();
                        if ($creator === $this->actor) {
                            self::fail(sprintf('%s: a plain user created the record with themselves in charge (S28).', $label));
                        }
                        $tested[$label] = true;
                    } catch (AccessDeniedException $e) {
                        if ($creator === $this->manager) {
                            self::fail(sprintf('%s: a manager was refused (%s).', $label, $e->getMessage()));
                        }
                    } catch (\Throwable $e) {
                        if ($creator === $this->actor) {
                            // not storable at all: the refusal could not be told apart
                            $this->login(null);
                            $this->reopen();
                            continue 3;
                        }
                    } finally {
                        $this->login(null);
                        $this->reopen();
                    }
                }
            }
        }

        foreach (['App\Entity\Incident::responsiblePerson', 'App\Entity\Risk::riskOwner', 'App\Entity\DataSubjectRequest::assignedTo'] as $slot) {
            self::assertArrayHasKey($slot, $tested, 'Create slot not probed: ' . $slot . ' (probed: ' . implode(', ', array_keys($tested)) . ')');
        }
        self::assertGreaterThanOrEqual(20, count($tested), 'Too few create slots probed: ' . implode(', ', array_keys($tested)));
    }

    /** Classes that are exempt although their responsibility appears somewhere — with the reason. */
    private const array NOT_AUTHORITY = [
        // Maßnahmen: everybody who may edit the task may act on it anyway (EDIT_OR_RESPONSIBLE); one's own
        // to-do is created with oneself as owner. Management-review tasks are added only by a manager (addTask).
        'App\Entity\ActionItem' => true,
        // The requester names the second person; the four-eyes check refuses self-approval on its own.
        'App\Entity\FourEyesApprovalRequest' => true,
    ];

    /** @return array<class-string, string> class → where its responsibility opens an action */
    private static function authorityClasses(): array
    {
        $found = [];
        $dir = dirname(__DIR__, 2) . '/src/Controller';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.php')) {
                continue;
            }
            $class = 'App\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], substr((string) $file, strlen($dir) + 1));
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class) as $attribute) {
                    $isGranted = $attribute->newInstance();
                    if (!$isGranted->attribute instanceof \Symfony\Component\ExpressionLanguage\Expression
                        || !str_contains((string) $isGranted->attribute, RecordResponsibleVoter::RESPONSIBLE)
                        || !is_string($isGranted->subject)) {
                        continue;
                    }
                    foreach ($method->getParameters() as $parameter) {
                        $type = $parameter->getType();
                        if ($parameter->getName() === $isGranted->subject && $type instanceof \ReflectionNamedType
                            && str_starts_with($type->getName(), 'App\\Entity\\')) {
                            $found[$type->getName()] ??= $class . '::' . $method->getName();
                        }
                    }
                }
            }
        }

        $root = dirname(__DIR__, 2) . '/config/workflows';
        foreach (glob($root . '/*.yaml') ?: [] as $yamlFile) {
            foreach ((\Symfony\Component\Yaml\Yaml::parseFile($yamlFile)['framework']['workflows'] ?? []) as $name => $workflow) {
                foreach ($workflow['transitions'] ?? [] as $transition => $definition) {
                    if (($definition['metadata']['responsible'] ?? false) === true) {
                        foreach ((array) ($workflow['supports'] ?? []) as $supported) {
                            $found[$supported] ??= $name . '.' . $transition . ' responsible: true';
                        }
                    }
                }
            }
        }
        foreach (glob($root . '/regulatory/*.yaml') ?: [] as $yamlFile) {
            foreach ((\Symfony\Component\Yaml\Yaml::parseFile($yamlFile)['framework']['workflows'] ?? []) as $name => $workflow) {
                $metadata = $workflow['metadata'] ?? [];
                foreach (\App\Workflow\Loader\RegulatoryWorkflowLoader::stepsOf($metadata) ?? [] as $step) {
                    if (($step['approver_role'] ?? null) !== 'owner') {
                        continue;
                    }
                    $subject = \App\Service\WorkflowService::subjectClass($metadata['regulatory_metadata']['entity_type'] ?? null);
                    if ($subject !== null) {
                        $found[$subject] ??= $name . ' approver_role: owner';
                    }
                }
            }
        }
        ksort($found);

        return $found;
    }

    private function managerPerson(): Person
    {
        $person = (new Person())->setFullName('Slot Manager Person')->setTenant($this->tenant)->setLinkedUser($this->manager);
        $this->em->persist($person);

        return $person;
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

    private function wouldBeResponsible(object $record, ClassMetadata $meta, string $field, User|Person $value, ?User $who = null): bool
    {
        $resolver = static::getContainer()->get(ResponsibilityResolver::class);
        $this->assign($meta, $record, $field, $value);
        try {
            foreach ($resolver->responsibleFor($record) as $user) {
                if ($user === ($who ?? $this->actor)) {
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
