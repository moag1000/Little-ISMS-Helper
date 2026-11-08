<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\LifecycleController;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The bulk lifecycle transition checks the organisation of every record
 * itself and writes one AuditLogger::logBulk() batch; the batch id it returns
 * is the one on the audit rows (it used to be random bytes matching nothing).
 * The CSRF attribute is outside this test — the action is invoked directly.
 */
final class LifecycleBulkTransitionAuditTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private string $uid;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('lbt', true));

        $this->tenant = (new Tenant())->setName('LBT ' . $this->uid)->setCode('lbt_' . $this->uid);
        $this->em->persist($this->tenant);
        $actor = (new User())
            ->setEmail('actor_' . $this->uid . '@example.com')
            ->setFirstName('First')
            ->setLastName('Actor')
            ->setRoles(['ROLE_MANAGER'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($actor);
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($actor, 'main', $actor->getRoles()));
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant);
    }

    private function draftDocument(Tenant $tenant): Document
    {
        $doc = (new Document())
            ->setTenant($tenant)
            ->setFilename('lbt.md')
            ->setOriginalFilename('lbt.md')
            ->setMimeType('text/markdown')
            ->setFileSize(1)
            ->setFilePath('virtual:test')
            ->setCategory('policy')
            ->setUploadedAt(new \DateTimeImmutable());
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    #[Test]
    public function bulkTransitionReturnsTheBatchIdOfItsAuditRowsAndSkipsForeignRecords(): void
    {
        $foreignTenant = (new Tenant())->setName('LBT F ' . $this->uid)->setCode('lbtf_' . $this->uid);
        $this->em->persist($foreignTenant);
        $own = $this->draftDocument($this->tenant);
        $foreign = $this->draftDocument($foreignTenant);

        $request = Request::create('/lifecycle/document/bulk-transition', 'POST', content: (string) json_encode([
            'transition' => 'submit_for_review',
            'ids' => [$own->getId(), $foreign->getId()],
        ]));
        $body = json_decode((string) static::getContainer()->get(LifecycleController::class)
            ->bulkTransition('document', $request)->getContent(), true);

        self::assertSame([$own->getId()], $body['succeeded']);
        self::assertSame('not_found', $body['failed'][(string) $foreign->getId()] ?? null);
        $this->em->refresh($foreign);
        self::assertSame('draft', $foreign->getStatus());

        $batchId = $body['audit_log_batch_id'];
        self::assertIsString($batchId);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $batchId);

        $rows = $this->em->getRepository(AuditLog::class)->findBy(['entityType' => 'Document', 'entityId' => $own->getId()]);
        $batchRows = array_filter(
            $rows,
            static fn (AuditLog $row): bool => ($row->getNewValuesArray()['_batch_id'] ?? null) === $batchId,
        );
        self::assertCount(1, $batchRows, 'one per-entity row of the returned batch');
    }

    #[Test]
    public function bulkTransitionWithoutChangesReturnsNoBatchId(): void
    {
        $foreignTenant = (new Tenant())->setName('LBT N ' . $this->uid)->setCode('lbtn_' . $this->uid);
        $this->em->persist($foreignTenant);
        $foreign = $this->draftDocument($foreignTenant);

        $request = Request::create('/lifecycle/document/bulk-transition', 'POST', content: (string) json_encode([
            'transition' => 'submit_for_review',
            'ids' => [$foreign->getId()],
        ]));
        $body = json_decode((string) static::getContainer()->get(LifecycleController::class)
            ->bulkTransition('document', $request)->getContent(), true);

        self::assertSame([], $body['succeeded']);
        self::assertNull($body['audit_log_batch_id']);
    }
}
