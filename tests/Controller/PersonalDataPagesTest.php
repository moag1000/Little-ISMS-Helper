<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DataBreach;
use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S33 acceptance: data subject requests and data breaches are read by managers (the DPO role inherits it) and the
 * people responsible for the record — an account without relation, an auditor or top management without
 * assignment get 403 on the detail page and an empty list, the same in the dashboard and the global search.
 */
#[AllowMockObjectsWithoutExpectations]
final class PersonalDataPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    /** @var array<string, User> */
    private array $users = [];
    private DataSubjectRequest $dsr;
    private DataBreach $breach;
    private string $marker;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $modules = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);
        $container->set(\App\Service\ModuleConfigurationService::class, $modules);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->marker = substr(md5(uniqid('', true)), 0, 10);
        $this->tenant = new Tenant();
        $this->tenant->setName('S33 tenant ' . uniqid());
        $this->tenant->setCode('s33_' . uniqid());
        $this->em->persist($this->tenant);

        foreach (['plain' => 'ROLE_USER', 'holder' => 'ROLE_USER', 'auditor' => 'ROLE_AUDITOR', 'board' => 'ROLE_TOP_MGMT', 'manager' => 'ROLE_MANAGER', 'dpo' => 'ROLE_DPO'] as $key => $role) {
            $user = new User();
            $user->setEmail(sprintf('s33_%s_%s@example.com', $key, uniqid()));
            $user->setFirstName('S33');
            $user->setLastName($key);
            $user->setRoles([$role]);
            $user->setPassword('hashed_password');
            $user->setTenant($this->tenant);
            $user->setIsActive(true);
            $this->em->persist($user);
            $this->users[$key] = $user;
        }

        $this->dsr = new DataSubjectRequest();
        $this->dsr->setTenant($this->tenant);
        $this->dsr->setRequestType('access');
        $this->dsr->setDataSubjectName('Erika Mustermann ' . $this->marker);
        $this->dsr->setDataSubjectEmail('erika_' . $this->marker . '@example.com');
        $this->dsr->setDescription('Auskunft nach Art. 15');
        $this->dsr->setReceivedAt(new DateTimeImmutable('-1 day'));
        $this->dsr->setAssignedTo($this->users['holder']);
        $this->em->persist($this->dsr);

        $this->breach = new DataBreach();
        $this->breach->setTenant($this->tenant);
        $this->breach->setReferenceNumber('S33-' . $this->marker);
        $this->breach->setTitle('Breach ' . $this->marker);
        $this->breach->setStatus('draft');
        $this->breach->setSeverity('high');
        $this->breach->setDetectedAt(new DateTimeImmutable());
        $this->breach->setDataCategories(['contact']);
        $this->breach->setDataSubjectCategories(['customers']);
        $this->breach->setBreachNature('Mail to the wrong customer ' . $this->marker);
        $this->breach->setLikelyConsequences('Spam');
        $this->breach->setMeasuresTaken('Recalled');
        $this->breach->setAffectedDataSubjects(1);
        $this->breach->setRiskLevel('high');
        $this->em->persist($this->breach);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            foreach ([$this->dsr, $this->breach, ...array_values($this->users), $this->tenant] as $entity) {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            }
            $this->em->flush();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    #[Test]
    public function aDataSubjectRequestOpensOnlyForManagersAndTheResponsible(): void
    {
        $show = '/en/data-subject-request/' . $this->dsr->getId();
        foreach (['plain', 'auditor', 'board'] as $key) {
            $this->client->loginUser($this->users[$key]);
            $this->client->request('GET', $show);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $key . ' opened a request of another person');

            $this->client->request('GET', '/en/data-subject-request');
            self::assertStringNotContainsString($this->marker, (string) $this->client->getResponse()->getContent(), $key . ' sees the request in the list');
        }
        foreach (['holder', 'manager', 'dpo'] as $key) {
            $this->client->loginUser($this->users[$key]);
            $this->client->request('GET', $show);
            self::assertResponseIsSuccessful($key . ' may open the request');

            $this->client->request('GET', '/en/data-subject-request');
            self::assertStringContainsString($this->marker, (string) $this->client->getResponse()->getContent(), $key . ' misses the request in the list');
        }
    }

    #[Test]
    public function aDataBreachOpensOnlyForManagersAndTheResponsible(): void
    {
        $show = '/en/data-breach/' . $this->breach->getId();
        foreach (['plain', 'auditor', 'board'] as $key) {
            $this->client->loginUser($this->users[$key]);
            $this->client->request('GET', $show);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $key . ' opened a breach');

            foreach (['/en/data-breach', '/en/data-breach/dashboard'] as $list) {
                $this->client->request('GET', $list);
                self::assertStringNotContainsString($this->marker, (string) $this->client->getResponse()->getContent(), $key . ' sees the breach on ' . $list);
            }
        }
        foreach (['manager', 'dpo'] as $key) {
            $this->client->loginUser($this->users[$key]);
            $this->client->request('GET', $show);
            self::assertResponseIsSuccessful($key . ' may open the breach');
        }
    }

    #[Test]
    public function theGlobalSearchOffersOnlyWhatTheDetailPageOpens(): void
    {
        $search = static::getContainer()->get(\App\Service\Search\SearchService::class);
        $tokens = static::getContainer()->get('security.token_storage');

        foreach (['plain' => false, 'holder' => true, 'dpo' => true] as $key => $visible) {
            $user = $this->users[$key];
            $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles()));
            $hits = [
                ...$search->searchDataSubjectRequests($this->marker, $this->tenant),
                ...($key === 'holder' ? [] : $search->searchDataBreaches($this->marker, $this->tenant)),
            ];
            self::assertSame($visible, $hits !== [], $key . ($visible ? ' finds nothing' : ' finds records of other people'));
        }
        $tokens->setToken(null);
    }
}
