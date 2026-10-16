<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AnswerLibraryEntry;
use App\Entity\AuditProgram;
use App\Entity\Authority\DoraRegisterOfInformation;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression: #[IsCsrfTokenValid('prefix_{id}')] is never interpolated, so the
 * check compared against the literal text "prefix_{id}" and every real request
 * was rejected. The ids are now Expressions built from the resolved entity and
 * must match exactly what the templates render (csrf_token('prefix_' ~ x.id)).
 */
final class CsrfExpressionTokenIdTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;
    /** @var list<array{string, int}> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('csrfx_', true);
        $this->tenant = (new Tenant())->setName('CSRF X ' . $uid)->setCode('cx_' . substr($uid, -8))
            ->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        $this->em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('csrfx_' . $uid . '@x.test')->setFirstName('C')->setLastName('X')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $tenantId = $this->tenant->getId();
        $statements = array_merge(
            array_map(static fn (array $row): array => ['DELETE FROM ' . $row[0] . ' WHERE id = ?', $row[1]], array_reverse($this->cleanup)),
            [
                ['DELETE FROM notification_rule WHERE tenant_id = ?', $tenantId],
                ['DELETE FROM notification_channel WHERE tenant_id = ?', $tenantId],
                ['DELETE FROM answer_library_entry WHERE tenant_id = ?', $tenantId],
                ['DELETE FROM audit_program WHERE tenant_id = ?', $tenantId],
                ['DELETE FROM dora_register_of_information WHERE tenant_id = ?', $tenantId],
                ['DELETE FROM users WHERE id = ?', $this->admin->getId()],
                ['DELETE FROM tenant WHERE id = ?', $tenantId],
            ],
        );
        foreach ($statements as [$sql, $id]) {
            try {
                $conn->executeStatement($sql, [$id]);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function notificationChannelVerifyAndDeleteAcceptTemplateToken(): void
    {
        $channel = $this->persist((new NotificationChannel())->setTenant($this->tenant)
            ->setName('CSRF channel')->setType(NotificationChannel::TYPE_IN_APP));
        $id = $channel->getId();
        $this->client->loginUser($this->admin);

        $this->post('/en/admin/notification/channel/' . $id . '/verify', 'notification_channel_verify_' . $id);
        self::assertResponseRedirects('/en/admin/notification/channel');
        self::assertNotNull($this->fetchOne('SELECT verified_at FROM notification_channel WHERE id = ?', $id));

        $this->post('/en/admin/notification/channel/' . $id . '/delete', 'notification_channel_delete_' . $id);
        self::assertResponseRedirects('/en/admin/notification/channel');
        self::assertFalse($this->fetchOne('SELECT id FROM notification_channel WHERE id = ?', $id));
    }

    #[Test]
    public function notificationRuleToggleAndDeleteAcceptTemplateToken(): void
    {
        $rule = $this->persist((new NotificationRule())->setTenant($this->tenant)
            ->setName('CSRF rule')->setEventType('incident.created')->setIsActive(true));
        $id = $rule->getId();
        $this->client->loginUser($this->admin);

        $this->post('/en/admin/notification/rule/' . $id . '/toggle', 'notification_rule_toggle_' . $id);
        self::assertResponseRedirects('/en/admin/notification/rule/' . $id);
        self::assertSame(0, (int) $this->fetchOne('SELECT is_active FROM notification_rule WHERE id = ?', $id));

        $this->post('/en/admin/notification/rule/' . $id . '/delete', 'notification_rule_delete_' . $id);
        self::assertResponseRedirects('/en/admin/notification/rule');
        self::assertFalse($this->fetchOne('SELECT id FROM notification_rule WHERE id = ?', $id));
    }

    #[Test]
    public function notificationTemplateApplyAcceptsTemplateToken(): void
    {
        $template = $this->persist((new NotificationTemplate())->setTenant($this->tenant)
            ->setTemplateKey('csrf_' . uniqid())->setName('CSRF template ' . uniqid())
            ->setDefaultEventType('incident.created')->setCategory('incident'));
        $this->cleanup[] = ['notification_template', (int) $template->getId()];
        $id = $template->getId();
        $this->client->loginUser($this->admin);

        $this->post('/en/admin/notification/template/' . $id . '/apply', 'notification_template_apply_' . $id);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertStringContainsString('/en/admin/notification/rule/', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, (int) $this->fetchOne('SELECT COUNT(*) FROM notification_rule WHERE tenant_id = ?', $this->tenant->getId()));
    }

    #[Test]
    public function auditProgramDeleteAcceptsTemplateToken(): void
    {
        $program = $this->persist((new AuditProgram())->setTenant($this->tenant)->setName('CSRF program'));
        $id = $program->getId();
        $this->client->loginUser($this->admin);

        $this->post('/en/audit-programs/' . $id . '/delete', 'delete-audit-program-' . $id);
        self::assertResponseRedirects('/en/audit-programs');
        self::assertFalse($this->fetchOne('SELECT id FROM audit_program WHERE id = ?', $id));
    }

    #[Test]
    public function doraRoiMarkSubmittedAcceptsTemplateToken(): void
    {
        $record = $this->persist((new DoraRegisterOfInformation())->setTenant($this->tenant)
            ->setReportingDate(new \DateTimeImmutable('2026-12-31')));
        $id = $record->getId();
        $this->client->loginUser($this->admin);

        $this->post('/en/authority/dora-roi/' . $id . '/mark-submitted', 'dora_roi_mark_submitted_' . $id, ['confirmation_number' => 'CONF-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertSame('CONF-1', $this->fetchOne('SELECT confirmation_number FROM dora_register_of_information WHERE id = ?', $id));
    }

    #[Test]
    public function answerLibraryUseAndDeleteAcceptTemplateToken(): void
    {
        $entry = $this->persist((new AnswerLibraryEntry())->setTenant($this->tenant)
            ->setQuestion('Q?')->setAnswer('A.')->setCreatedBy($this->admin));
        $id = $entry->getId();
        $moduleActive = static::getContainer()->get(ModuleConfigurationService::class)->isModuleActive('answer_library');
        $this->client->loginUser($this->admin);

        $this->post('/en/answer-library/' . $id . '/use', 'answer_library_use_' . $id);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND, 'valid token must pass the CSRF check');
        if ($moduleActive) {
            self::assertSame(1, (int) $this->fetchOne('SELECT use_count FROM answer_library_entry WHERE id = ?', $id));
        }

        // The HTML form posts (no _method override in this app), so POST must reach delete.
        $this->post('/en/answer-library/' . $id . '/delete', 'answer_library_delete_' . $id);
        self::assertResponseStatusCodeSame(Response::HTTP_FOUND, 'valid token must pass the CSRF check');
        if ($moduleActive) {
            self::assertFalse($this->fetchOne('SELECT id FROM answer_library_entry WHERE id = ?', $id));
        }
    }

    #[Test]
    public function tokenForAnotherEntityIsRejected(): void
    {
        $a = $this->persist((new NotificationRule())->setTenant($this->tenant)->setName('A')->setEventType('incident.created'));
        $b = $this->persist((new NotificationRule())->setTenant($this->tenant)->setName('B')->setEventType('incident.created'));
        $this->client->loginUser($this->admin);

        $this->post('/en/admin/notification/rule/' . $b->getId() . '/delete', 'notification_rule_delete_' . $a->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotFalse($this->fetchOne('SELECT id FROM notification_rule WHERE id = ?', $b->getId()));
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    /** @param array<string, string> $extra */
    private function post(string $url, string $tokenId, array $extra = []): void
    {
        $token = $this->seedSessionCsrfToken($this->client, $tokenId);
        $this->client->request('POST', $url, $extra + ['_token' => $token]);
    }

    private function fetchOne(string $sql, ?int $id): mixed
    {
        return $this->em->getConnection()->fetchOne($sql, [$id]);
    }
}
