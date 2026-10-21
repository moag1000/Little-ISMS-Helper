<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Import\EntityMapperRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The structured owner columns (user by e-mail, person by name or e-mail) of
 * the six dual-state entities import tenant-scoped, exactly like Control; the
 * ISMSObjective free-text owner is required only while neither is given.
 */
final class OwnerColumnsImportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EntityMapperRegistry $registry;
    private Tenant $own;
    private User $ownUser;
    private User $foreignUser;
    private Person $ownPerson;
    private string $uid;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->registry = self::getContainer()->get(EntityMapperRegistry::class);
        $this->em->beginTransaction();

        $this->uid = substr(str_replace('.', '', uniqid('', true)), -10);
        $this->own = (new Tenant())->setName('Own ' . $this->uid)->setCode('oi_' . $this->uid);
        $other = (new Tenant())->setName('Other ' . $this->uid)->setCode('oo_' . $this->uid);
        $this->em->persist($this->own);
        $this->em->persist($other);
        $this->ownUser = $this->user('own', $this->own);
        $this->foreignUser = $this->user('foreign', $other);
        $this->ownPerson = (new Person())->setFullName('Pia Owner ' . $this->uid)->setTenant($this->own)
            ->setEmail('pia.' . $this->uid . '@example.com');
        $this->em->persist($this->ownPerson);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    private function user(string $prefix, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($prefix . '.' . $this->uid . '@example.com')
            ->setFirstName('F')->setLastName('L')
            ->setRoles(['ROLE_USER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    /** @return iterable<string, array{string, string}> */
    public static function entities(): iterable
    {
        yield 'ISMSObjective' => ['ISMSObjective', 'responsiblePerson'];
        yield 'Location' => ['Location', 'responsiblePerson'];
        yield 'Patch' => ['Patch', 'responsiblePerson'];
        yield 'Vulnerability' => ['Vulnerability', 'responsiblePerson'];
        yield 'InterestedParty' => ['InterestedParty', 'contactPerson'];
        yield 'Supplier' => ['Supplier', 'contactPerson'];
        yield 'Control' => ['Control', 'responsiblePerson'];
    }

    #[Test]
    #[DataProvider('entities')]
    public function userByEmailAndPersonByNameOrEmailResolveInTheOwnTenant(string $type, string $base): void
    {
        $mapper = $this->registry->getMapperFor($type);

        $data = $mapper->toEntityData([
            $base . 'User' => $this->ownUser->getEmail(),
            $base . 'Ref' => 'Pia Owner ' . $this->uid,
        ], null, $this->own);
        self::assertSame($this->ownUser, $data[$base . 'User']);
        self::assertSame($this->ownPerson, $data[$base . 'Ref']);

        $byMail = $mapper->toEntityData([$base . 'Ref' => $this->ownPerson->getEmail()], null, $this->own);
        self::assertSame($this->ownPerson, $byMail[$base . 'Ref'], 'person found by e-mail');
    }

    #[Test]
    #[DataProvider('entities')]
    public function aUserOfAnotherOrganisationNeverResolves(string $type, string $base): void
    {
        $data = $this->registry->getMapperFor($type)->toEntityData(
            [$base . 'User' => $this->foreignUser->getEmail(), 'responsiblePerson' => 'free text'],
            null,
            $this->own,
        );

        self::assertArrayNotHasKey($base . 'User', $data);
    }

    #[Test]
    public function objectiveOwnerTextIsOnlyRequiredWhenNeitherUserNorPersonIsGiven(): void
    {
        $mapper = $this->registry->getMapperFor('ISMSObjective');
        $row = ['title' => 'T', 'description' => 'D', 'category' => 'integrity', 'targetDate' => '2027-01-01'];

        self::assertNotEmpty($mapper->validate($row)['errors'], 'no owner at all is refused');
        self::assertSame([], $mapper->validate($row + ['responsiblePersonUser' => 'a@b.test'])['errors']);
        self::assertSame([], $mapper->validate($row + ['responsiblePersonRef' => 'Someone'])['errors']);
        self::assertSame([], $mapper->validate($row + ['responsiblePerson' => 'Text'])['errors']);
    }

    #[Test]
    public function objectiveWithOnlyAnUnresolvableOwnerIsRefusedAtCommitTime(): void
    {
        $mapper = $this->registry->getMapperFor('ISMSObjective');
        $row = ['title' => 'T', 'description' => 'D', 'category' => 'integrity', 'targetDate' => '2027-01-01'];

        // Resolvable user: fine without free text.
        $ok = $mapper->toEntityData($row + ['responsiblePersonUser' => $this->ownUser->getEmail()], null, $this->own);
        self::assertSame($this->ownUser, $ok['responsiblePersonUser']);

        // A user of another organisation does not resolve → the row would be ownerless.
        $this->expectException(\App\Exception\Import\ImportRowInvalidException::class);
        $mapper->toEntityData($row + ['responsiblePersonUser' => $this->foreignUser->getEmail()], null, $this->own);
    }
}
