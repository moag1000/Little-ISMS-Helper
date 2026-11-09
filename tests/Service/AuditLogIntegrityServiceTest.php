<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Exception\Security\AuditChainBrokenException;
use App\Exception\Tenant\TenantNotFoundException;
use App\Job\JobContext;
use App\Job\VerifyAuditLogChainJob;
use App\Repository\SystemSettingsRepository;
use App\Service\Job\JobStatusService;
use App\Service\Job\JobTenantScope;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\AuditLogIntegrityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AUD-02 chain per organisation (B3, sweep 5 F3): every organisation has its own
 * chain, the predecessor is read past the tenant filter, verification walks one
 * organisation (or all) with flat memory, and old cross-organisation links are
 * accepted only while the row they name still exists.
 */
final class AuditLogIntegrityServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLogIntegrityService $integrity;
    private Tenant $a;
    private Tenant $b;
    private int $counter = 0;
    private mixed $legacySetting = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->integrity = new AuditLogIntegrityService($this->em, 'test-secret-for-the-chain');
        $this->a = $this->tenant('a');
        $this->b = $this->tenant('b');
        $this->em->flush();
        $this->legacySetting = $this->em->getConnection()->fetchOne(
            "SELECT value FROM system_settings WHERE category = 'audit_log' AND setting_key = 'hmac_legacy_until_id'",
        );
    }

    /** No transactional rollback in this suite (DAMA is off): remove what the test wrote. */
    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $ids = [(int) $this->a->getId(), (int) $this->b->getId()];
        $connection->executeStatement('DELETE FROM audit_log WHERE tenant_id IN (?, ?)', $ids);
        $connection->executeStatement('DELETE FROM tenant WHERE id IN (?, ?)', $ids);
        $where = "category = 'audit_log' AND setting_key = 'hmac_legacy_until_id'";
        if ($this->legacySetting === false) {
            $connection->executeStatement('DELETE FROM system_settings WHERE ' . $where);
        } else {
            $connection->executeStatement('UPDATE system_settings SET value = ? WHERE ' . $where, [$this->legacySetting]);
        }
        parent::tearDown();
    }

    #[Test]
    public function eachOrganisationChainsOnlyItsOwnRows(): void
    {
        $a1 = $this->row($this->a);
        $b1 = $this->row($this->b);
        $a2 = $this->row($this->a);
        $b2 = $this->row($this->b);

        self::assertNull($a1->getPreviousHmac());
        self::assertSame($a1->getHmac(), $a2->getPreviousHmac());
        self::assertSame($b1->getHmac(), $b2->getPreviousHmac());
        self::assertSame([], $this->integrity->verifyChain($this->a)->issues);
        self::assertSame([], $this->integrity->verifyChain($this->b)->issues);
    }

    #[Test]
    public function signingIgnoresTheTenantFilterOfTheRequest(): void
    {
        $a1 = $this->row($this->a);
        $filters = $this->em->getFilters();
        $filter = $filters->enable('tenant_filter');
        $filter->setParameter('tenant_id', (string) $this->b->getId());
        try {
            $a2 = $this->row($this->a);
        } finally {
            $filters->disable('tenant_filter');
        }

        self::assertSame($a1->getHmac(), $a2->getPreviousHmac());
    }

    #[Test]
    public function aRewrittenRowIsReported(): void
    {
        $this->row($this->a);
        $a2 = $this->row($this->a);
        $this->row($this->a);
        $this->em->getConnection()->executeStatement(
            'UPDATE audit_log SET description = ? WHERE id = ?',
            ['rewritten', $a2->getId()],
        );
        $this->em->clear();

        self::assertSame([['id' => $a2->getId(), 'reason' => 'hmac_invalid']], $this->integrity->verifyChain($this->a)->issues);
        self::assertSame([], $this->integrity->verifyChain($this->b)->issues);
    }

    #[Test]
    public function aDeletedRowBreaksTheNextLinkButTheOldestRowIsTheAnchor(): void
    {
        $a1 = $this->row($this->a);
        $a2 = $this->row($this->a);
        $a3 = $this->row($this->a);
        $connection = $this->em->getConnection();

        // Retention deletes the oldest rows: the remaining oldest row anchors the chain.
        $connection->executeStatement('DELETE FROM audit_log WHERE id = ?', [$a1->getId()]);
        self::assertSame([], $this->integrity->verifyChain($this->a)->issues);

        $connection->executeStatement('DELETE FROM audit_log WHERE id = ?', [$a2->getId()]);
        $this->row($this->a);
        self::assertSame([], $this->integrity->verifyChain($this->a)->issues, 'a3 is now the anchor');

        $a5 = $this->row($this->a);
        $a6 = $this->row($this->a);
        $connection->executeStatement('DELETE FROM audit_log WHERE id = ?', [$a5->getId()]);
        self::assertSame(
            [['id' => $a6->getId(), 'reason' => 'previous_hmac_mismatch']],
            $this->integrity->verifyChain($this->a)->issues,
        );
        self::assertNotNull($a3->getId());
    }

    #[Test]
    public function aLinkFromBeforeTheSplitCountsWhileTheNamedRowExists(): void
    {
        $this->row($this->a);
        $b1 = $this->row($this->b);
        // Signed before B3 in a console run: the predecessor was B's newest row.
        $legacy = $this->row($this->a, $b1->getHmac());
        self::assertSame([], $this->integrity->verifyChain($this->a)->issues);

        $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE id = ?', [$b1->getId()]);
        self::assertSame(
            [['id' => $legacy->getId(), 'reason' => 'previous_hmac_mismatch']],
            $this->integrity->verifyChain($this->a)->issues,
        );
    }

    #[Test]
    public function verificationKeepsMemoryFlatAndCountsEveryChain(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->row($this->a);
            $this->row($this->b);
        }
        $checked = 0;
        $issues = $this->integrity->verifyChain(null, true, static function (int $n) use (&$checked): void {
            $checked = $n;
        })->issues;

        self::assertSame([], array_values(array_filter(
            $issues,
            fn (array $i): bool => in_array($i['id'], $this->idsOf($this->a, $this->b), true),
        )));
        self::assertGreaterThanOrEqual(6, $checked);
        self::assertSame(3, $this->integrity->countSigned($this->a));
        self::assertSame(0, $this->em->getUnitOfWork()->size(), 'the walk clears the EntityManager');
    }

    #[Test]
    public function aStoredRowVerifiesAfterReload(): void
    {
        $log = $this->log($this->a);
        $log->setCreatedAt(new \DateTimeImmutable('2026-11-09 12:34:56.789123'));
        $this->integrity->sign($log);
        $this->em->persist($log);
        $this->em->flush();
        $id = $log->getId();
        $this->em->clear();

        $reloaded = $this->em->find(AuditLog::class, $id);
        self::assertInstanceOf(AuditLog::class, $reloaded);
        self::assertTrue($this->integrity->verify($reloaded), 'the signature covers what the column stores');
    }

    #[Test]
    public function rowsSignedBeforeTheFixAreUnverifiableNotTampered(): void
    {
        $old = $this->row($this->a);
        $this->row($this->a);
        // Before B3 the signature covered microseconds the column dropped: simulate with a changed payload.
        $this->em->getConnection()->executeStatement(
            "UPDATE audit_log SET created_at = '2020-01-01 00:00:00' WHERE id = ?",
            [$old->getId()],
        );
        $settings = static::getContainer()->get(SystemSettingsRepository::class);
        $settings->setSetting('audit_log', 'hmac_legacy_until_id', $old->getId());
        $this->em->clear();

        $report = (new AuditLogIntegrityService($this->em, 'test-secret-for-the-chain', $settings))->verifyChain($this->a);

        self::assertSame([], $report->issues);
        self::assertSame(1, $report->unverifiable);
        self::assertSame(2, $report->checked);
    }

    #[Test]
    public function withoutSecretNothingIsSignedAndTheCheckSaysSo(): void
    {
        $off = new AuditLogIntegrityService($this->em, '');
        $log = $this->log($this->a);
        $off->sign($log);

        self::assertNull($log->getHmac());
        self::assertSame([['id' => 0, 'reason' => 'hmac_secret_not_configured']], $off->verifyChain($this->a)->issues);
    }

    #[Test]
    public function theJobChecksOnlyTheOrganisationOfTheCallerAndFailsRedOnABreak(): void
    {
        $this->row($this->a);
        $this->row($this->a);
        $b1 = $this->row($this->b);
        $this->row($this->b);
        $this->em->getConnection()->executeStatement(
            'UPDATE audit_log SET description = ? WHERE id = ?',
            ['rewritten', $b1->getId()],
        );
        $this->em->clear();
        $container = static::getContainer();
        $statusService = $container->get(JobStatusService::class);
        $job = new VerifyAuditLogChainJob(
            $this->integrity,
            $container->get(JobTenantScope::class),
            $container->get(TranslatorInterface::class),
        );

        $okId = $statusService->create('test.audit_chain');
        $job->run(new JobContext($okId, $statusService, ['tenantId' => $this->a->getId(), 'locale' => 'en']));
        self::assertStringContainsString('2 entries checked', (string) ($statusService->read($okId)['message'] ?? ''));
        $statusService->delete($okId);

        $redId = $statusService->create('test.audit_chain');
        try {
            $job->run(new JobContext($redId, $statusService, ['tenantId' => $this->b->getId(), 'locale' => 'en']));
            self::fail('A broken chain must fail the job.');
        } catch (AuditChainBrokenException $e) {
            self::assertStringContainsString('#' . $b1->getId() . ' (hmac_invalid)', $e->getMessage());
        } finally {
            $statusService->delete($redId);
        }

        $this->expectException(TenantNotFoundException::class);
        $job->run(new JobContext($statusService->create('test.audit_chain'), $statusService, ['locale' => 'en']));
    }

    private function tenant(string $suffix): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Chain ' . $suffix);
        $tenant->setCode('chain-' . $suffix . '-' . bin2hex(random_bytes(4)));
        $this->em->persist($tenant);

        return $tenant;
    }

    private function log(Tenant $tenant): AuditLog
    {
        $log = new AuditLog();
        $log->setTenant($tenant);
        $log->setEntityType('Asset');
        $log->setEntityId(1);
        $log->setAction('update');
        $log->setUserName('chain@example.com');
        $log->setDescription('entry ' . ++$this->counter . ' ' . bin2hex(random_bytes(6)));
        $log->setCreatedAt(new \DateTimeImmutable());

        return $log;
    }

    private function row(Tenant $tenant, ?string $previous = null): AuditLog
    {
        $tenant = $this->em->contains($tenant) ? $tenant : $this->em->getReference(Tenant::class, $tenant->getId());
        $log = $this->log($tenant);
        $previous === null ? $this->integrity->sign($log) : $this->integrity->signWithPrevious($log, $previous);
        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    /**
     * @return list<int>
     */
    private function idsOf(Tenant ...$tenants): array
    {
        $ids = array_map(static fn (Tenant $t): int => (int) $t->getId(), $tenants);

        return array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM audit_log WHERE tenant_id IN (' . implode(',', $ids) . ')',
        ));
    }
}
