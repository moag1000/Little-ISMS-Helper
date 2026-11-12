<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * S12 / NIS-2 Art. 23: a report time gets onto an incident only through
 * "Als gemeldet markieren" (the form shows it read-only) — by a manager or the
 * incident's responsible, at the time the report went out (not in the future,
 * not before detection).
 */
final class IncidentReportingMarkTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Incident $incident;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('irm_' . substr(md5($uid), 0, 10));
        $this->em->persist($this->tenant);
        $this->incident = (new Incident())->setTitle('Ausfall ' . $uid)->setIncidentNumber('INC-' . $uid)
            ->setDescription('Ausfall des Portals')->setCategory('security')
            ->setSeverity(IncidentSeverity::High)->setStatus(IncidentStatus::Reported)->setTenant($this->tenant);
        $this->incident->setDetectedAt(new DateTimeImmutable('-2 days'));
        $this->incident->setNotificationRequired(true);
        $this->em->persist($this->incident);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->incident, ...$this->users, $this->tenant] as $entity) {
            try {
                $found = $this->em->find($entity::class, $entity->getId());
                if ($found !== null) {
                    $this->em->remove($found);
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
    public function aPlainUserMayNotMarkAReport(): void
    {
        $this->client->loginUser($this->user(['ROLE_USER']));

        $this->post('earlyWarningReportedAt', '');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function aManagerRecordsTheTimeTheReportWentOut(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));
        $at = (new DateTimeImmutable('-1 day'))->setTime(9, 30);

        $this->post('earlyWarningReportedAt', $at->format('Y-m-d\TH:i'), 'Portal-Quittung 4711');

        self::assertResponseRedirects();
        self::assertSame($at->format('Y-m-d H:i'), $this->reload()->getEarlyWarningReportedAt()?->format('Y-m-d H:i'));
    }

    #[Test]
    public function aBackdatedTimeWithoutPortalReferenceIsRefused(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));

        $this->post('earlyWarningReportedAt', (new DateTimeImmutable('-1 day'))->format('Y-m-d\TH:i'));

        self::assertNull($this->reload()->getEarlyWarningReportedAt());
    }

    #[Test]
    public function aTimeWithinClockSkewNeedsNoReason(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));

        $this->post('earlyWarningReportedAt', (new DateTimeImmutable('-5 minutes'))->format('Y-m-d\TH:i'));

        self::assertNotNull($this->reload()->getEarlyWarningReportedAt());
    }

    #[Test]
    public function aTimeInTheFutureOrBeforeDetectionIsRefused(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));

        $this->post('earlyWarningReportedAt', (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'));
        self::assertNull($this->reload()->getEarlyWarningReportedAt());

        $this->post('earlyWarningReportedAt', (new DateTimeImmutable('-5 days'))->format('Y-m-d\TH:i'));
        self::assertNull($this->reload()->getEarlyWarningReportedAt());
    }

    #[Test]
    public function withoutATimeItIsNow(): void
    {
        $this->client->loginUser($this->user(['ROLE_MANAGER']));

        $this->post('earlyWarningReportedAt', '');

        $reported = $this->reload()->getEarlyWarningReportedAt();
        self::assertNotNull($reported);
        self::assertLessThan(120, abs((new DateTimeImmutable())->getTimestamp() - $reported->getTimestamp()));
    }

    private function post(string $field, string $reportedAt, string $reason = ''): void
    {
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/incident_reporting_' . $this->incident->getId(), $token);
        $session->save();
        $this->client->request('POST', '/en/incident/' . $this->incident->getId() . '/reporting/' . $field . '/mark', [
            '_token' => $token,
            'reported_at' => $reportedAt,
            'backdate_reason' => $reason,
        ]);
    }

    private function reload(): Incident
    {
        $this->em->clear();

        return $this->em->find(Incident::class, $this->incident->getId());
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setEmail(uniqid('irm_', true) . '@example.test')->setFirstName('Mia')->setLastName('Meldung')
            ->setRoles($roles)->setPassword('hashed')->setTenant($this->em->find(Tenant::class, $this->tenant->getId()))->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->users[] = $user;

        return $user;
    }
}
