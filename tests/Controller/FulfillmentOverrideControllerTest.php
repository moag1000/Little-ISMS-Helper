<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Tests\Controller\Trait\SessionCsrfTokenTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paket 3: a requirement computed from controls takes a hand value only as an
 * override with reason and review date, and goes back to its rule on request.
 */
final class FulfillmentOverrideControllerTest extends WebTestCase
{
    use SessionCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $manager;
    private User $auditor;
    private User $user;
    private ComplianceRequirement $requirement;
    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('ovr_', true);

        $this->tenant = (new Tenant())->setName('Override ' . $uid)->setCode('ovr_' . $uid);
        $this->persist($this->tenant);
        $this->manager = $this->makeUser('mgr_' . $uid, ['ROLE_MANAGER']);
        $this->auditor = $this->makeUser('aud_' . $uid, ['ROLE_AUDITOR']);
        $this->user = $this->makeUser('usr_' . $uid, ['ROLE_USER']);

        $framework = new ComplianceFramework();
        $framework->setCode('OVR_' . strtoupper(substr(md5($uid), 0, 8)));
        $framework->setName('Override Test Framework');
        $framework->setVersion('1.0');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('test');
        $this->persist($framework);

        $control = new Control();
        $control->setTenant($this->tenant);
        $control->setControlId('OVR-' . substr(md5($uid), 0, 6));
        $control->setName('Override test control');
        $control->setDescription('test');
        $control->setCategory('organizational');
        $control->setApplicable(true);
        $control->setImplementationStatus('implemented');
        $this->persist($control);

        $this->requirement = new ComplianceRequirement();
        $this->requirement->setFramework($framework);
        $this->requirement->setRequirementId('OVR-1');
        $this->requirement->setTitle('Override requirement');
        $this->requirement->setDescription('test');
        $this->requirement->setCategory('test');
        $this->requirement->setPriority('medium');
        $this->requirement->setRequirementType('core');
        $this->requirement->addMappedControl($control);
        $this->persist($this->requirement);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->em->getRepository(ComplianceRequirementFulfillment::class)->findBy(['requirement' => $this->requirement->getId()]) as $f) {
                $this->em->remove($f);
            }
            $this->em->flush();
            foreach (array_reverse($this->created) as $entity) {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                }
            }
            $this->em->flush();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function overrideNeedsAReasonAndFollowRuleRestoresTheRuleValue(): void
    {
        $this->client->loginUser($this->manager);
        $id = (int) $this->requirement->getId();

        $token = $this->seedSessionCsrfToken($this->client, 'fulfillment_override_' . $id);
        $this->client->request('POST', '/en/compliance/requirement/' . $id . '/fulfillment/override', [
            '_token' => $token,
            'percentage' => '40',
            'reason' => 'kurz',
            'review_due' => (new \DateTimeImmutable('+3 months'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();
        $refused = $this->fulfillment(required: false);
        self::assertFalse($refused?->isOverridden() ?? false, 'A reason under ten characters is refused.');

        $token = $this->seedSessionCsrfToken($this->client, 'fulfillment_override_' . $id);
        $this->client->request('POST', '/en/compliance/requirement/' . $id . '/fulfillment/override', [
            '_token' => $token,
            'percentage' => '40',
            'reason' => 'Control wirkt nur am Hauptstandort',
            'review_due' => (new \DateTimeImmutable('+3 months'))->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();
        $f = $this->fulfillment();
        self::assertSame(40, $f->getFulfillmentPercentage());
        self::assertSame(100, $f->getDerivedPercentage());
        self::assertSame(ComplianceRequirementFulfillment::OVERRIDE_MANUAL, $f->getOverrideSource());

        $this->client->request('GET', '/en/compliance/fulfillment/overrides');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'OVR-1');

        $token = $this->seedSessionCsrfToken($this->client, 'fulfillment_follow_rule_' . $id);
        $this->client->request('POST', '/en/compliance/requirement/' . $id . '/fulfillment/follow-rule', ['_token' => $token]);
        self::assertResponseRedirects();
        $f = $this->fulfillment();
        self::assertFalse($f->isOverridden());
        self::assertSame(100, $f->getFulfillmentPercentage());
    }

    #[Test]
    public function auditorsReadTheListButCannotOverride(): void
    {
        $this->client->loginUser($this->auditor);
        $this->client->request('GET', '/en/compliance/fulfillment/overrides');
        self::assertResponseIsSuccessful();

        $id = (int) $this->requirement->getId();
        $token = $this->seedSessionCsrfToken($this->client, 'fulfillment_override_' . $id);
        $this->client->request('POST', '/en/compliance/requirement/' . $id . '/fulfillment/override', [
            '_token' => $token,
            'percentage' => '10',
            'reason' => 'Auditor darf das nicht',
            'review_due' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function plainUsersDoNotSeeTheList(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/en/compliance/fulfillment/overrides');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function fulfillment(bool $required = true): ?ComplianceRequirementFulfillment
    {
        $this->em->clear();
        $f = $this->em->getRepository(ComplianceRequirementFulfillment::class)->findOneBy([
            'tenant' => $this->tenant->getId(),
            'requirement' => $this->requirement->getId(),
        ]);
        if ($required) {
            self::assertInstanceOf(ComplianceRequirementFulfillment::class, $f);
        }

        return $f;
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $id, array $roles): User
    {
        $user = new User();
        $user->setEmail($id . '@example.com');
        $user->setFirstName('T');
        $user->setLastName('U');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->persist($user);

        return $user;
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        $this->created[] = $entity;
    }
}
