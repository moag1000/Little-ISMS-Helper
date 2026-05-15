<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentStatus;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for F16 Risk-Incident cross-link controller endpoints.
 * Sprint 9B.
 *
 * Tests:
 *   - link-incident POST with invalid CSRF → redirect
 *   - unlink-incident POST with invalid CSRF → redirect
 *   - link-risk POST with invalid CSRF → redirect
 *   - unlink-risk POST with invalid CSRF → redirect
 */
final class RiskIncidentLinkControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private ?Tenant $tenant = null;
    private ?User $manager = null;
    private ?Risk $risk = null;
    private ?Incident $incident = null;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        // Remove links first (cascade may handle it, but be explicit)
        $links = $this->em->getRepository(RiskIncidentLink::class)->findAll();
        foreach ($links as $link) {
            if ($link->getTenant() === $this->tenant) {
                try { $this->em->remove($link); } catch (\Throwable) {}
            }
        }

        foreach ([$this->incident, $this->risk] as $entity) {
            if ($entity) {
                try {
                    $managed = $this->em->find(get_class($entity), $entity->getId());
                    if ($managed) { $this->em->remove($managed); }
                } catch (\Throwable) {}
            }
        }

        foreach ([$this->manager] as $user) {
            if ($user) {
                try {
                    $managed = $this->em->find(User::class, $user->getId());
                    if ($managed) { $this->em->remove($managed); }
                } catch (\Throwable) {}
            }
        }

        if ($this->tenant) {
            try {
                $managed = $this->em->find(Tenant::class, $this->tenant->getId());
                if ($managed) { $this->em->remove($managed); }
            } catch (\Throwable) {}
        }

        try { $this->em->flush(); } catch (\Throwable) {}
    }

    private function createFixtures(): void
    {
        $uid = uniqid('ril_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('RIL Tenant ' . $uid);
        $this->tenant->setCode('ril_' . substr(md5($uid . microtime(true)), 0, 16));
        $this->em->persist($this->tenant);

        $this->manager = new User();
        $this->manager->setEmail('manager_ril_' . $uid . '@example.com');
        $this->manager->setFirstName('Mgr');
        $this->manager->setLastName('RIL');
        $this->manager->setRoles(['ROLE_MANAGER', 'ROLE_USER']);
        $this->manager->setPassword('hashed');
        $this->manager->setTenant($this->tenant);
        $this->manager->setIsActive(true);
        $this->em->persist($this->manager);

        $asset = new Asset();
        $asset->setName('RIL Asset ' . $uid);
        $asset->setAssetType('hardware');
        $asset->setOwner('IT');
        $asset->setStatus('active');
        $asset->setTenant($this->tenant);
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);
        $this->em->persist($asset);

        $this->risk = new Risk();
        $this->risk->setTitle('RIL Risk ' . $uid);
        $this->risk->setCategory('security');
        $this->risk->setDescription('test');
        $this->risk->setThreat('threat');
        $this->risk->setVulnerability('vuln');
        $this->risk->setAsset($asset);
        $this->risk->setProbability(3);
        $this->risk->setLikelihoodJustification('test lj');
        $this->risk->setImpact(3);
        $this->risk->setImpactJustification('test ij');
        $this->risk->setResidualProbability(2);
        $this->risk->setResidualImpact(2);
        $this->risk->setTreatmentStrategy(TreatmentStrategy::Mitigate);
        $this->risk->setStatus(RiskStatus::Identified);
        $this->risk->setRiskOwner($this->manager);
        $this->risk->setTenant($this->tenant);
        $this->em->persist($this->risk);

        $this->incident = new Incident();
        $this->incident->setTitle('RIL Incident ' . $uid);
        $this->incident->setDescription('desc');
        $this->incident->setCategory('security');
        $this->incident->setStatus(IncidentStatus::Reported);
        $this->incident->setTenant($this->tenant);
        $this->incident->setReportedBy($this->manager->getEmail() ?? 'test@test.com');
        $this->incident->setIncidentNumber('INC-RIL-' . substr($uid, 0, 8));
        $this->em->persist($this->incident);

        $this->em->flush();
    }

    #[Test]
    public function linkIncidentPostWithInvalidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->manager);

        $riskId = $this->risk->getId();
        $incidentId = $this->incident->getId();

        $this->client->request('POST', "/de/risk/{$riskId}/link-incident", [
            'incident_id' => $incidentId,
            'link_type'   => 'related',
            '_token'      => 'invalid_token',
        ]);

        // CSRF invalid → redirects back to risk show (3xx)
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function unlinkIncidentPostWithInvalidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->manager);

        $riskId = $this->risk->getId();

        $this->client->request('POST', "/de/risk/{$riskId}/unlink-incident/99999", [
            '_token' => 'invalid_token',
        ]);

        self::assertResponseRedirects();
    }

    #[Test]
    public function linkRiskPostWithInvalidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->manager);

        $incidentId = $this->incident->getId();
        $riskId     = $this->risk->getId();

        $this->client->request('POST', "/de/incident/{$incidentId}/link-risk", [
            'risk_id'  => $riskId,
            'link_type'=> 'suspected',
            '_token'   => 'bad_token',
        ]);

        self::assertResponseRedirects();
    }

    #[Test]
    public function unlinkRiskPostWithInvalidCsrfIsRejected(): void
    {
        $this->client->loginUser($this->manager);

        $incidentId = $this->incident->getId();

        $this->client->request('POST', "/de/incident/{$incidentId}/unlink-risk/99999", [
            '_token' => 'bad_token',
        ]);

        self::assertResponseRedirects();
    }
}
