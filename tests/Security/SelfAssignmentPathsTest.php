<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AuditChecklist;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\IncidentType;
use App\Form\PersonType;
use App\Mcp\McpSettings;
use App\Repository\AuditChecklistRepository;
use App\Service\ApiTokenManager;
use App\Tests\Contract\Support\ContractRecordFactory;
use App\Tests\Support\LoginWithSecondFactor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * S20 on each path a plain user (ROLE_USER, no manager scope) takes:
 *
 * - web form: the person picker of a record they do not hold refuses their own
 *   Person with a form error; the account link of a Person is read-only;
 * - REST (PATCH) and MCP `save_record`: refused, the record stays unchanged;
 * - audit checklist: only an auditor or the audit's lead auditor / team saves
 *   verdicts, and a verdict feeds fulfilment only once the audit is reported.
 *
 * The slots themselves are discovered in {@see \App\Tests\Contract\ResponsibleSlotWriteContractTest}.
 */
final class SelfAssignmentPathsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;
    private Person $userPerson;
    private Person $holder;
    private Incident $incident;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $this->tenant = (new Tenant())->setName('Self ' . $id)->setCode('self_' . $id);
        $em->persist($this->tenant);
        $this->user = (new User())
            ->setEmail('self-assign-' . $id . '@example.test')
            ->setFirstName('Sam')
            ->setLastName('Self')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $em->persist($this->user);
        $this->userPerson = (new Person())->setFullName('Sam Self ' . $id)->setTenant($this->tenant)->setLinkedUser($this->user);
        $this->holder = (new Person())->setFullName('Hold Er ' . $id)->setTenant($this->tenant);
        $em->persist($this->userPerson);
        $em->persist($this->holder);
        $this->incident = (new ContractRecordFactory($em))->create(Incident::class, $this->tenant, 'self probe ' . $id);
        $this->incident->setResponsiblePerson($this->holder);
        $em->flush();
    }

    #[Test]
    public function theIncidentFormRefusesOnesOwnPersonAsResponsible(): void
    {
        $this->actAs($this->user);
        $form = static::getContainer()->get('form.factory')->create(IncidentType::class, $this->incident, ['csrf_protection' => false]);
        $form->submit(['responsiblePerson' => (string) $this->userPerson->getId()], false);

        self::assertFalse($form->isValid(), 'a plain user put themselves in charge of a foreign incident');
        self::assertStringContainsString(
            static::getContainer()->get('translator')->trans('responsibility.self_assignment', [], 'validators'),
            (string) $form->getErrors(true),
        );
    }

    #[Test]
    public function thePersonFormKeepsTheAccountLinkReadOnlyForAPlainUser(): void
    {
        $this->actAs($this->user);
        $form = static::getContainer()->get('form.factory')->create(PersonType::class, $this->holder);
        self::assertTrue($form->get('linkedUser')->isDisabled());
    }

    #[Test]
    public function restAndMcpRefuseTheSelfAssignment(): void
    {
        $settings = static::getContainer()->get(McpSettings::class);
        $settings->setEnabled($this->tenant, true);
        $settings->setWriteEnabled($this->tenant, true);
        $token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'S20', canWrite: true);
        $personIri = '/api/people/' . $this->userPerson->getId();

        $this->client->request('PATCH', '/api/incidents/' . $this->incident->getId(), server: [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: (string) json_encode(['responsiblePerson' => $personIri]));
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'REST accepted the self-assignment');
        self::assertSame($this->holder->getId(), $this->reloadedResponsible(), 'REST changed the responsible person');

        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'save_record',
            'arguments' => ['resource' => 'Incident', 'id' => $this->incident->getId(), 'data' => ['responsiblePerson' => $personIri]],
        ]]));
        self::assertSame($this->holder->getId(), $this->reloadedResponsible(), 'MCP save_record changed the responsible person');
    }

    #[Test]
    public function onlyAuditorsAndTheAuditTeamSaveVerdicts(): void
    {
        $em = $this->em();
        $tenant = $em->find(Tenant::class, $this->tenant->getId());
        $factory = new ContractRecordFactory($em);
        $foreign = $factory->create(InternalAudit::class, $tenant, 'self audit foreign');
        $own = $factory->create(InternalAudit::class, $tenant, 'self audit own');
        $own->setLeadAuditorPerson($em->find(Person::class, $this->userPerson->getId()));
        $em->flush();
        $router = static::getContainer()->get('router');
        $body = (string) json_encode(['_token' => 'none', 'items' => []]);

        LoginWithSecondFactor::login($this->client, $em->find(User::class, $this->user->getId()));
        $this->client->request('POST', $router->generate('app_audit_checklist_save', ['id' => $foreign->getId(), '_locale' => 'de']),
            server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'), 'denied by the role gate, not by the token check');

        // As lead auditor the gate opens (the request then fails only on the missing CSRF token).
        LoginWithSecondFactor::login($this->client, $this->em()->find(User::class, $this->user->getId()));
        $this->client->request('POST', $router->generate('app_audit_checklist_save', ['id' => $own->getId(), '_locale' => 'de']),
            server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertSame(['error' => 'Invalid CSRF token'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    #[Test]
    public function aVerdictCountsOnlyOnceTheAuditIsReported(): void
    {
        $em = $this->em();
        $tenant = $em->find(Tenant::class, $this->tenant->getId());
        $factory = new ContractRecordFactory($em);
        $item = $factory->create(AuditChecklist::class, $tenant, 'self verdict');
        $item->setVerificationStatus('compliant');
        $item->markAsVerified('Sam');
        $audit = $item->getAudit();
        self::assertInstanceOf(InternalAudit::class, $audit);
        $meta = $em->getClassMetadata(InternalAudit::class);
        $meta->setFieldValue($audit, 'status', 'planned');
        $em->flush();

        $repository = static::getContainer()->get(AuditChecklistRepository::class);
        $since = new \DateTimeImmutable('-1 day');
        self::assertNotContains($item, $repository->findVerdictsSince($tenant, $since), 'a planned audit fed fulfilment');

        $meta->setFieldValue($audit, 'status', 'reported');
        $em->flush();
        self::assertContains($item, $repository->findVerdictsSince($tenant, $since));
    }

    private function actAs(User $user): void
    {
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function reloadedResponsible(): ?int
    {
        $em = $this->em();
        $em->clear();

        return $em->find(Incident::class, $this->incident->getId())?->getResponsiblePerson()?->getId();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
