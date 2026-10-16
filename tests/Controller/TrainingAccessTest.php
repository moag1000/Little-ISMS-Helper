<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Training show/edit/clone/audience-picker only carried a role check, so any
 * account that could load a training could change it. TrainingVoter now
 * requires the training to belong to the user's own organisation.
 */
final class TrainingAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $organisation;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $trainingIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->organisation = (new Tenant())->setCode('tr_acc_' . uniqid())->setName('Training access');
        $this->em->persist($this->organisation);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Plain DBAL: requests arm the tenant filter on the shared entity manager.
        $connection = $this->em->getConnection();
        foreach ($this->trainingIds as $id) {
            $connection->delete('training', ['id' => $id]);
        }
        foreach ($this->userIds as $id) {
            $connection->delete('users', ['id' => $id]);
        }
        $connection->delete('tenant', ['id' => $this->organisation->getId()]);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trainingActions(): iterable
    {
        yield 'show' => ['GET', ''];
        yield 'edit' => ['GET', '/edit'];
        yield 'edit submit' => ['POST', '/edit'];
        yield 'clone' => ['POST', '/clone'];
        yield 'audience picker' => ['GET', '/audience-picker'];
    }

    #[Test]
    #[DataProvider('trainingActions')]
    public function accountWithoutOrganisationCannotUseATrainingWithoutOrganisation(string $method, string $suffix): void
    {
        // The tenant filter lets such an account load rows that belong to no
        // organisation, so only the voter stands between it and the record.
        $training = $this->training(null);
        $this->client->loginUser($this->user(null, 'ROLE_MANAGER'));

        $this->client->request($method, '/en/training/' . $training->getId() . $suffix);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function memberOfTheOrganisationMayEditItsTraining(): void
    {
        $training = $this->training($this->organisation);
        $this->client->loginUser($this->user($this->organisation, 'ROLE_USER'));

        $this->client->request('GET', '/en/training/' . $training->getId() . '/edit');

        self::assertResponseIsSuccessful();
    }

    private function training(?Tenant $tenant): Training
    {
        $training = new Training();
        $training->setTenant($tenant);
        $training->setTitle('Access training ' . uniqid());
        $training->setDescription('Training access test');
        $training->setTrainingType('security_awareness');
        $training->setTrainer('Trainer');
        $training->setStatus('planned');
        $training->setMandatory(true);
        $training->setScheduledDate(new \DateTime('+1 month'));
        $this->em->persist($training);
        $this->em->flush();
        $this->trainingIds[] = (int) $training->getId();

        return $training;
    }

    private function user(?Tenant $tenant, string $role): User
    {
        $user = (new User())->setEmail('tr_acc_' . uniqid() . '@example.com')->setFirstName('Training')
            ->setLastName('Access')->setRoles([$role])->setPassword('hashed_password')
            ->setTenant($tenant)->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = (int) $user->getId();

        return $user;
    }
}
