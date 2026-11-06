<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * End-to-end bulk action "Review bestätigen" (POST /{type}/bulk-review):
 * sets the review dates per type, writes one logBulk batch, rejects records
 * outside the periodic review or the organisation, refuses non-managers.
 */
final class BulkReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private Tenant $tenant;
    private Tenant $other;
    private User $manager;
    private User $auditor;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->uid = substr(uniqid('', true), -8);
        $this->tenant = $this->keep((new Tenant())->setName('Review ' . $this->uid)->setCode('rv_' . $this->uid));
        $this->other = $this->keep((new Tenant())->setName('Other ' . $this->uid)->setCode('ro_' . $this->uid));
        $this->manager = $this->user('mgr_' . $this->uid, ['ROLE_MANAGER']);
        $this->auditor = $this->user('aud_' . $this->uid, ['ROLE_AUDITOR']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            } catch (\Throwable) {
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function riskReviewSetsNextDateFromLevelOrOwnIntervalAndLogsOneBatch(): void
    {
        $critical = $this->risk(5, 5, RiskStatus::Assessed);
        $ownInterval = $this->risk(1, 1, RiskStatus::Assessed)->setReviewIntervalDays(30);
        $closed = $this->risk(2, 2, RiskStatus::Closed);
        $foreign = $this->risk(2, 2, RiskStatus::Assessed, $this->other);
        $this->em->flush();

        $body = $this->confirm('risk', [$critical->getId(), $ownInterval->getId(), $closed->getId(), $foreign->getId()]);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $body['changed']);
        self::assertEqualsCanonicalizing(
            [['id' => $closed->getId(), 'reason' => 'not_reviewable'], ['id' => $foreign->getId(), 'reason' => 'tenant_mismatch']],
            $body['rejected'],
        );
        self::assertNotEmpty($body['batch_id']);

        $this->em->clear();
        self::assertSame($this->inDays(90), $this->em->find(Risk::class, $critical->getId())?->getReviewDate()?->format('Y-m-d'));
        self::assertSame($this->inDays(30), $this->em->find(Risk::class, $ownInterval->getId())?->getReviewDate()?->format('Y-m-d'));
        self::assertNull($this->em->find(Risk::class, $closed->getId())?->getReviewDate());

        $batch = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'Risk' AND new_values LIKE ?",
            ['%"batch_id":"' . $body['batch_id'] . '"%'],
        );
        self::assertSame(1, $batch, 'one batch entry');
        $rows = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_type = 'Risk' AND new_values LIKE ?",
            ['%"_batch_id":"' . $body['batch_id'] . '"%'],
        );
        self::assertSame(2, $rows, 'one entry per confirmed risk');
    }

    #[Test]
    public function controlReviewKeepsItsCadenceAndSkipsNotApplicable(): void
    {
        $quarterly = $this->control(true)
            ->setLastReviewDate(new DateTime('-120 days'))->setNextReviewDate(new DateTime('-30 days'));
        $na = $this->control(false);
        $this->em->flush();

        $body = $this->confirm('soa', [$quarterly->getId(), $na->getId()]);

        self::assertSame(1, $body['changed']);
        self::assertSame([['id' => $na->getId(), 'reason' => 'not_reviewable']], $body['rejected']);
        $this->em->clear();
        $control = $this->em->find(Control::class, $quarterly->getId());
        self::assertSame($this->inDays(0), $control?->getLastReviewDate()?->format('Y-m-d'));
        self::assertSame($this->inDays(90), $control?->getNextReviewDate()?->format('Y-m-d'));
    }

    #[Test]
    public function documentReviewUsesTheReviewIntervalAndSkipsDrafts(): void
    {
        $published = $this->document('published')->setReviewIntervalMonths(6);
        $draft = $this->document('draft');
        $this->em->flush();

        $body = $this->confirm('document', [$published->getId(), $draft->getId()]);

        self::assertSame(1, $body['changed']);
        self::assertSame([['id' => $draft->getId(), 'reason' => 'not_reviewable']], $body['rejected']);
        $this->em->clear();
        self::assertSame(
            (new DateTime('today'))->modify('+6 months')->format('Y-m-d'),
            $this->em->find(Document::class, $published->getId())?->getNextReviewDate()?->format('Y-m-d'),
        );
    }

    #[Test]
    public function supplierReviewRecordsTheAssessmentAndSkipsTerminated(): void
    {
        $active = $this->supplier('active');
        $terminated = $this->supplier('terminated');
        $this->em->flush();

        $body = $this->confirm('supplier', [$active->getId(), $terminated->getId()]);

        self::assertSame(1, $body['changed']);
        self::assertSame([['id' => $terminated->getId(), 'reason' => 'not_reviewable']], $body['rejected']);
        $this->em->clear();
        $supplier = $this->em->find(Supplier::class, $active->getId());
        self::assertSame($this->inDays(0), $supplier?->getLastSecurityAssessment()?->format('Y-m-d'));
        self::assertSame(
            (new DateTime('today'))->modify('+12 months')->format('Y-m-d'),
            $supplier?->getNextAssessmentDate()?->format('Y-m-d'),
        );
    }

    #[Test]
    public function nothingConfirmableAnswersBadRequest(): void
    {
        $closed = $this->risk(2, 2, RiskStatus::Closed);
        $this->em->flush();

        $body = $this->confirm('risk', [$closed->getId()]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(0, $body['changed']);
    }

    #[Test]
    public function auditorIsRefused(): void
    {
        $risk = $this->risk(3, 3, RiskStatus::Assessed);
        $this->em->flush();

        $this->confirm('risk', [$risk->getId()], $this->auditor);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertNull($this->em->find(Risk::class, $risk->getId())?->getReviewDate());
    }

    #[Test]
    public function missingCsrfTokenIsRefused(): void
    {
        $risk = $this->risk(3, 3, RiskStatus::Assessed);
        $this->em->flush();
        $this->client->loginUser($this->manager);

        $this->client->request('POST', '/en/risk/bulk-review', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['ids' => [$risk->getId()]]));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function dueFilterNarrowsTheRiskList(): void
    {
        $due = $this->risk(2, 2, RiskStatus::Assessed)->setTitle('Due risk ' . $this->uid)->setReviewDate(new DateTime('-1 day'));
        $current = $this->risk(2, 2, RiskStatus::Assessed)->setTitle('Current risk ' . $this->uid)->setReviewDate(new DateTime('+60 days'));
        $this->em->flush();
        $this->client->loginUser($this->manager);

        $this->client->request('GET', '/en/risk?review_overdue=1');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $due->getTitle(), $html);
        self::assertStringNotContainsString((string) $current->getTitle(), $html);
        self::assertStringContainsString('bulk-actions#bulkReview', $html, 'the bulk bar offers the action');
    }

    /**
     * @param list<int|null> $ids
     * @return array<string, mixed>
     */
    private function confirm(string $type, array $ids, ?User $as = null): array
    {
        $this->client->loginUser($as ?? $this->manager);
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/bulk_action', $token);
        $session->save();

        $this->client->request('POST', '/en/' . $type . '/bulk-review', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['ids' => $ids, '_token' => $token]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function inDays(int $days): string
    {
        return (new DateTime('today'))->modify(sprintf('+%d days', $days))->format('Y-m-d');
    }

    private function risk(int $probability, int $impact, RiskStatus $status, ?Tenant $tenant = null): Risk
    {
        return $this->keep((new Risk())->setTitle('Review risk ' . uniqid())->setCategory('security')
            ->setProbability($probability)->setImpact($impact)->setDescription('d')->setStatus($status)
            ->setTenant($tenant ?? $this->tenant));
    }

    private function control(bool $applicable): Control
    {
        return $this->keep((new Control())->setControlId('A.9.' . substr(uniqid(), -5))->setName('Ctl ' . uniqid())
            ->setDescription('d')->setCategory('technological')->setApplicable($applicable)->setTenant($this->tenant));
    }

    private function document(string $status): Document
    {
        $unique = uniqid('review_');
        $document = (new Document())->setTenant($this->tenant)->setFilename($unique . '.md')
            ->setOriginalFilename($unique . '.md')->setMimeType('text/markdown')->setFileSize(1)
            ->setFilePath('virtual:' . $unique)->setCategory('policy')->setStatus($status)
            ->setUploadedAt(new DateTimeImmutable())->setUploadedBy($this->manager);

        return $this->keep($document);
    }

    private function supplier(string $status): Supplier
    {
        return $this->keep((new Supplier())->setName('Supplier ' . uniqid())->setEmail(uniqid('s_') . '@example.com')
            ->setAddress('1 Test St')->setCriticality('high')->setStatus($status)->setServiceProvided('Cloud')
            ->setTenant($this->tenant));
    }

    /** @param list<string> $roles */
    private function user(string $local, array $roles): User
    {
        return $this->keep((new User())->setEmail($local . '@x.test')->setFirstName('Rev')->setLastName('Iewer')
            ->setRoles($roles)->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true));
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function keep(object $entity): object
    {
        $this->em->persist($entity);
        $this->created[] = $entity;

        return $entity;
    }
}
