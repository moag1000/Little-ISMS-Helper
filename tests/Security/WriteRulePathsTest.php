<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AuditLog;
use App\Entity\Incident;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Exception\Import\ImportRowInvalidException;
use App\Mcp\McpSettings;
use App\Security\ActingUser;
use App\Service\ApiTokenManager;
use App\Service\Import\ImportEntityWriter;
use App\Service\WriteRule\CorrectionReason;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * S22 on each write path a plain user (ROLE_USER, no manager scope) takes —
 * the flush guard every writer passes ({@see \App\EventListener\WriteRuleGuard}):
 *
 * - classification (`#[ManagerOrResponsibleField]`): an incident's severity changes
 *   only by a manager or the person responsible for the incident;
 * - create-only (`#[CreateOnly]`): `detectedAt` moves only by a manager;
 * - responsibility: a plain user who does not hold the record does not hand it to
 *   somebody else (S20 left that open: two plain users naming each other);
 * - REST PATCH and MCP `save_record` are refused the same way;
 * - the bulk import validates every row like the other writers (S22, W9 M1).
 *
 * Parity of form, import, REST and the attributes: {@see WritePathParityTest} (Gate 96).
 */
final class WriteRulePathsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Tenant $tenant;
    private User $user;
    private Person $userPerson;
    private Person $holder;
    private Person $colleague;
    private Incident $incident;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->em();
        $id = bin2hex(random_bytes(5));
        $this->tenant = (new Tenant())->setName('Rule ' . $id)->setCode('rule_' . $id);
        $em->persist($this->tenant);
        $this->user = $this->newUser('rule-user-' . $id, ['ROLE_USER']);
        $this->userPerson = (new Person())->setFullName('Uma User ' . $id)->setTenant($this->tenant)->setLinkedUser($this->user);
        // holders count through their account (ResponsibilityResolver), so both have one
        $this->holder = (new Person())->setFullName('Hold Er ' . $id)->setTenant($this->tenant)
            ->setLinkedUser($this->newUser('rule-holder-' . $id, ['ROLE_USER']));
        $this->colleague = (new Person())->setFullName('Col League ' . $id)->setTenant($this->tenant)
            ->setLinkedUser($this->newUser('rule-colleague-' . $id, ['ROLE_USER']));
        foreach ([$this->userPerson, $this->holder, $this->colleague] as $person) {
            $em->persist($person);
        }
        $this->incident = (new ContractRecordFactory($em))->create(Incident::class, $this->tenant, 'rule probe ' . $id);
        $this->incident->setResponsiblePerson($this->holder);
        $this->incident->setSeverity(IncidentSeverity::High);
        $em->flush();
    }

    #[Test]
    public function aPlainUserCannotDowngradeTheClassificationOfAForeignIncident(): void
    {
        $this->actAs($this->user);
        $this->incident->setSeverity(IncidentSeverity::Low);

        $this->expectException(AccessDeniedException::class);
        $this->em()->flush();
    }

    #[Test]
    public function theResponsiblePersonAndAManagerMayClassify(): void
    {
        $em = $this->em();
        $this->incident->setResponsiblePerson($this->userPerson);
        $em->flush(); // no token yet: setting up the holder

        $this->actAs($this->user);
        $this->incident->setSeverity(IncidentSeverity::Medium);
        $em->flush();

        $this->actAs($this->newUser('rule-manager-' . bin2hex(random_bytes(4)), ['ROLE_MANAGER']));
        $this->incident->setSeverity(IncidentSeverity::Critical);
        $em->flush();

        self::assertSame(IncidentSeverity::Critical, $this->reloaded()->getSeverity());
    }

    #[Test]
    public function detectedAtMovesOnlyByAManager(): void
    {
        $em = $this->em();
        $this->incident->setResponsiblePerson($this->userPerson);
        $em->flush();

        // even the responsible person does not move the deadline anchor
        $this->actAs($this->user);
        $this->incident->setDetectedAt(new \DateTimeImmutable('+2 days'));
        try {
            $em->flush();
            self::fail('the responsible person moved detectedAt');
        } catch (AccessDeniedException $e) {
            self::assertStringContainsString('detectedAt', $e->getMessage());
        }
    }

    #[Test]
    public function aManagerCorrectsDetectedAtOnlyWithAReason(): void
    {
        // S28 (F-06): the correction is the manager's, and it carries its reason into the audit log.
        $em = $this->em();
        $this->actAs($this->newUser('rule-mgr-' . bin2hex(random_bytes(4)), ['ROLE_MANAGER']));
        $this->incident->setDetectedAt(new \DateTimeImmutable('-3 days'));
        try {
            $em->flush();
            self::fail('a manager moved detectedAt without a reason');
        } catch (AccessDeniedException $e) {
            self::assertStringContainsString('reason', $e->getMessage());
        }

        static::getContainer()->get(CorrectionReason::class)->provide('Erstmeldung nannte den falschen Tag');
        // a refused flush already took the value into the unit of work: correct to another day
        $this->incident->setDetectedAt(new \DateTimeImmutable('-4 days'));
        $em->flush();

        $log = $em->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'create_only_corrected', 'entityType' => 'Incident', 'entityId' => $this->incident->getId()],
        );
        self::assertInstanceOf(AuditLog::class, $log, 'the correction left no audit-log entry');
        self::assertStringContainsString('Erstmeldung nannte den falschen Tag', (string) $log->getNewValues());
    }

    #[Test]
    public function aJobWritesWithTheRightsOfWhoStartedIt(): void
    {
        // S28 (F-07): no token (Messenger worker) — the guard checks the dispatching person.
        static::getContainer()->get('security.token_storage')->setToken(null);
        $this->incident->setSeverity(IncidentSeverity::Low);
        $this->expectException(AccessDeniedException::class);
        static::getContainer()->get(ActingUser::class)->runAs($this->user, fn () => $this->em()->flush());
    }

    #[Test]
    public function aPlainUserDoesNotHandSomebodyElsesRecordOn(): void
    {
        $this->actAs($this->user);
        $this->incident->setResponsiblePerson($this->colleague);

        $this->expectException(AccessDeniedException::class);
        $this->em()->flush();
    }

    #[Test]
    public function theHolderHandsTheRecordOn(): void
    {
        $em = $this->em();
        $this->incident->setResponsiblePerson($this->userPerson);
        $em->flush();

        $this->actAs($this->user);
        $this->incident->setResponsiblePerson($this->colleague);
        $em->flush();

        self::assertSame($this->colleague->getId(), $this->reloaded()->getResponsiblePerson()?->getId());
    }

    #[Test]
    public function restAndMcpRefuseTheClassificationChange(): void
    {
        $settings = static::getContainer()->get(McpSettings::class);
        $settings->setEnabled($this->tenant, true);
        $settings->setWriteEnabled($this->tenant, true);
        $token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'S22', canWrite: true);

        $this->client->request('PATCH', '/api/incidents/' . $this->incident->getId(), server: [
            'CONTENT_TYPE' => 'application/merge-patch+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: (string) json_encode(['severity' => 'low']));
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode(), 'REST accepted the downgrade');
        self::assertSame(IncidentSeverity::High, $this->reloaded()->getSeverity(), 'REST changed the severity');

        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'save_record',
            'arguments' => ['resource' => 'Incident', 'id' => $this->incident->getId(), 'data' => ['severity' => 'low']],
        ]]));
        self::assertSame(IncidentSeverity::High, $this->reloaded()->getSeverity(), 'MCP save_record changed the severity');
    }

    #[Test]
    public function theImportValidatesEachRow(): void
    {
        $writer = static::getContainer()->get(ImportEntityWriter::class);

        // W9 M1: a link the form refuses (Assert\Url) is refused in the import too
        try {
            $writer->create('Patch', self::patchRow('KB-1', 'javascript:alert(1)'), $this->tenant);
            self::fail('the import accepted a javascript: download link');
        } catch (ImportRowInvalidException $e) {
            self::assertStringContainsString('downloadUrl', $e->getMessage());
        }
        self::assertInstanceOf(Patch::class, $writer->create('Patch', self::patchRow('KB-2', 'https://example.test/kb2'), $this->tenant));
    }

    #[Test]
    public function theImportDoesNotReRateAnAcceptedRisk(): void
    {
        $em = $this->em();
        $risk = (new ContractRecordFactory($em))->create(Risk::class, $this->tenant, 'accepted ' . bin2hex(random_bytes(4)));
        $risk->setResidualProbability(2)->setResidualImpact(2)->setFormallyAccepted(true);
        $em->flush();

        try {
            static::getContainer()->get(ImportEntityWriter::class)->update($risk, ['residualProbability' => 5], $this->tenant);
            self::fail('the import re-rated a formally accepted risk (S12 / ISO 27001 6.1.3 f)');
        } catch (ImportRowInvalidException $e) {
            self::assertStringContainsString('residualProbability', $e->getMessage());
        }
        self::assertSame(2, $risk->getResidualProbability(), 'the refused row left a half-applied value behind');
    }

    /** @return array<string, string> */
    private static function patchRow(string $id, string $url): array
    {
        return ['patchId' => $id, 'title' => 'probe', 'description' => 'probe', 'vendor' => 'Acme', 'product' => 'Widget', 'downloadUrl' => $url];
    }

    /** @param list<string> $roles */
    private function newUser(string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '@example.test')
            ->setFirstName('Rule')
            ->setLastName($name)
            ->setRoles($roles)
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function actAs(User $user): void
    {
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function reloaded(): Incident
    {
        $em = $this->em();
        $em->clear();
        $incident = $em->find(Incident::class, $this->incident->getId());
        self::assertInstanceOf(Incident::class, $incident);

        return $incident;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
