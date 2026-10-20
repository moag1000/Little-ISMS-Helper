<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The tenant filter used to switch off for every account without an
 * organisation, so such an account read every organisation's data through
 * any plain findAll(). Only a SUPER_ADMIN stays instance-wide; any other
 * account without an organisation sees no organisation's rows and gets no
 * default organisation context.
 */
final class TenantFilterNoOrganisationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $organisation;
    private Risk $risk;
    /** @var list<User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $marker = uniqid();

        $this->organisation = (new Tenant())->setCode('nf_org_' . $marker)->setName('Organisation ' . $marker);
        $this->em->persist($this->organisation);
        $this->risk = new Risk();
        $this->risk->setTitle('Foreign risk ' . $marker);
        $this->risk->setCategory('security');
        $this->risk->setDescription('Risk of another organisation');
        $this->risk->setThreat('Threat');
        $this->risk->setVulnerability('Vulnerability');
        $this->risk->setProbability(3);
        $this->risk->setLikelihoodJustification('Likelihood justification');
        $this->risk->setImpact(4);
        $this->risk->setImpactJustification('Impact justification');
        $this->risk->setResidualProbability(2);
        $this->risk->setResidualImpact(2);
        $this->risk->setTreatmentStrategy(TreatmentStrategy::Mitigate);
        $this->risk->setStatus(RiskStatus::Identified);
        $this->risk->setTenant($this->organisation);
        $this->em->persist($this->risk);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Plain DBAL: the request armed the tenant filter on the shared entity manager.
        $connection = $this->em->getConnection();
        foreach ($this->users as $user) {
            $connection->delete('users', ['id' => $user->getId()]);
        }
        $connection->delete('risk', ['id' => $this->risk->getId()]);
        $connection->delete('tenant', ['id' => $this->organisation->getId()]);
        parent::tearDown();
    }

    #[Test]
    public function managerWithoutOrganisationSeesNoOrganisationsData(): void
    {
        $this->client->loginUser($this->userWithoutOrganisation(['ROLE_MANAGER']));

        $this->client->request('GET', '/en/analytics/api/heat-map');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            $this->risk->getTitle(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function superAdminWithoutOrganisationStaysInstanceWide(): void
    {
        $this->client->loginUser($this->userWithoutOrganisation(['ROLE_SUPER_ADMIN']));

        $this->client->request('GET', '/en/analytics/api/heat-map');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $this->risk->getTitle(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function accountWithoutOrganisationGetsNoDefaultOrganisationContext(): void
    {
        $this->client->disableReboot();
        $this->client->loginUser($this->userWithoutOrganisation(['ROLE_ADMIN']));
        $this->client->request('GET', '/en/analytics/api/heat-map');

        self::assertNull(static::getContainer()->get(TenantContext::class)->getCurrentTenant());
    }

    /**
     * @param list<string> $roles
     */
    private function userWithoutOrganisation(array $roles): User
    {
        $user = (new User())->setEmail('nf_' . uniqid() . '@example.com')->setFirstName('No')
            ->setLastName('Organisation')->setRoles($roles)->setPassword('hashed_password')
            ->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->users[] = $user;

        return $user;
    }
}
