<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\PushSubscriptionController;
use App\Controller\DashboardLayoutController;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * State-changing actions that used to accept requests without any CSRF token
 * (dashboard layout, framework assessment, push subscriptions, report
 * regeneration, audit-freeze PDF, incident auto-detect, mapping-onboarding
 * reset, document bulk export). Each must reject a missing / forged token and
 * accept the real one.
 */
final class CsrfStateChangingActionsTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $admin = null;
    private ?int $incidentId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('csrfsc_', true);
        $this->tenant = (new Tenant())->setName('CSRF ' . $uid)->setCode('cs_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->admin = (new User())
            ->setEmail('csrf_' . $uid . '@x.test')->setFirstName('C')->setLastName('S')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ([
            ['DELETE FROM incident WHERE id = ?', $this->incidentId],
            ['DELETE FROM dashboard_layout WHERE user_id = ?', $this->admin?->getId()],
            ['DELETE FROM users WHERE id = ?', $this->admin?->getId()],
            ['DELETE FROM tenant WHERE id = ?', $this->tenant?->getId()],
        ] as [$sql, $id]) {
            if ($id === null) {
                continue;
            }
            try {
                $conn->executeStatement($sql, [$id]);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    /**
     * [method, url, csrf token id, token transport ('header'|'body')]
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function protectedActions(): iterable
    {
        yield 'dashboard layout save' => ['POST', '/en/dashboard-layout/config', DashboardLayoutController::CSRF_ID, 'header'];
        yield 'dashboard layout reset' => ['POST', '/en/dashboard-layout/reset', DashboardLayoutController::CSRF_ID, 'header'];
        yield 'dashboard widget update' => ['PATCH', '/en/dashboard-layout/widget/risk_matrix', DashboardLayoutController::CSRF_ID, 'header'];
        yield 'framework assessment' => ['POST', '/en/compliance/framework/999999/assess', 'assess_framework', 'body'];
        yield 'push subscribe' => ['POST', '/en/api/push/subscribe', PushSubscriptionController::CSRF_ID, 'header'];
        yield 'push unsubscribe' => ['POST', '/en/api/push/unsubscribe', PushSubscriptionController::CSRF_ID, 'header'];
        yield 'push test' => ['POST', '/en/api/push/test', PushSubscriptionController::CSRF_ID, 'header'];
        yield 'license report regenerate' => ['POST', '/en/about/licenses/generate', 'report_regenerate', 'header'];
        yield 'admin license report' => ['POST', '/en/admin/licensing/generate', 'report_regenerate', 'header'];
        yield 'security report regenerate' => ['POST', '/en/about/security/generate', 'report_regenerate', 'header'];
        yield 'audit freeze pdf' => ['POST', '/en/audit-freeze/999999/generate-pdf', 'audit_freeze_generate_pdf_999999', 'body'];
        yield 'mapping onboarding reset' => ['POST', '/en/compliance/mapping-onboarding/reset', 'mapping_onboarding', 'body'];
        yield 'document bulk export' => ['POST', '/en/document/bulk-export', 'bulk_action', 'body'];
    }

    #[Test]
    #[DataProvider('protectedActions')]
    public function rejectsMissingToken(string $method, string $url, string $tokenId, string $transport): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request($method, $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    #[DataProvider('protectedActions')]
    public function rejectsForgedToken(string $method, string $url, string $tokenId, string $transport): void
    {
        $this->client->loginUser($this->admin);
        $this->seedSessionCsrfToken($this->client, $tokenId);
        $this->send($method, $url, 'forged-token', $transport);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function dashboardLayoutAcceptsValidToken(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, DashboardLayoutController::CSRF_ID);
        $this->send('POST', '/en/dashboard-layout/config', $token, 'header', ['widgets' => []]);

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
    }

    #[Test]
    public function frameworkAssessmentRequiresManagerAndPassesWithToken(): void
    {
        $user = (new User())
            ->setEmail('csrf_user_' . uniqid() . '@x.test')->setFirstName('U')->setLastName('U')
            ->setRoles(['ROLE_USER'])->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        try {
            $this->client->loginUser($user);
            $token = $this->seedSessionCsrfToken($this->client, 'assess_framework');
            $this->client->request('POST', '/en/compliance/framework/999999/assess', ['_token' => $token]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'ROLE_USER must not re-assess');
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
        }

        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, 'assess_framework');
        $this->client->request('POST', '/en/compliance/framework/999999/assess', ['_token' => $token]);
        // Token + role accepted → controller runs → unknown framework.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function pushUnsubscribeOnlyRemovesOwnSubscriptionAndAcceptsToken(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->seedSessionCsrfToken($this->client, PushSubscriptionController::CSRF_ID);
        $this->send('POST', '/en/api/push/unsubscribe', $token, 'header', ['endpoint' => 'https://push.example/none']);

        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['success']);
    }

    #[Test]
    public function incidentAutoDetectRequiresItsPerIncidentToken(): void
    {
        $incident = (new Incident())
            ->setTitle('CSRF auto-detect')->setIncidentNumber('INC-' . uniqid())
            ->setDescription('d')->setCategory('security')
            ->setSeverity(IncidentSeverity::Medium)->setStatus(IncidentStatus::Reported)
            ->setTenant($this->tenant);
        $this->em->persist($incident);
        $this->em->flush();
        $this->incidentId = $incident->getId();
        $url = '/en/incident/' . $this->incidentId . '/auto-detect-processes';

        $this->client->loginUser($this->admin);
        $this->client->request('POST', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $token = $this->seedSessionCsrfToken($this->client, 'auto-detect' . $this->incidentId);
        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertResponseRedirects('/en/incident/' . $this->incidentId);
    }

    /** @param array<string, mixed> $payload */
    private function send(string $method, string $url, string $token, string $transport, array $payload = []): void
    {
        if ($transport === 'header') {
            $this->client->request($method, $url, [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ], json_encode($payload ?: new \stdClass()));

            return;
        }
        $this->client->request($method, $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload + ['_token' => $token]));
    }
}
