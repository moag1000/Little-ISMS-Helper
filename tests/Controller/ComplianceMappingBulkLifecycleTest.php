<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Bulk lifecycle change of (global) compliance mappings: only the
 * 5-transition matrix through compliance_mapping_lifecycle, ROLE_MANAGER for
 * draft/review, ROLE_ADMIN for the operational states.
 */
final class ComplianceMappingBulkLifecycleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private User $user;
    private User $manager;
    private User $admin;
    private ComplianceRequirement $source;
    private ComplianceRequirement $target;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = substr(uniqid('', true), -10);
        $tenant = (new Tenant())->setName('CM ' . $uid)->setCode('cm_' . $uid);
        $this->persist($tenant);
        $this->user = $this->makeUser('u', $uid, ['ROLE_USER'], $tenant);
        $this->manager = $this->makeUser('m', $uid, ['ROLE_MANAGER'], $tenant);
        $this->admin = $this->makeUser('a', $uid, ['ROLE_ADMIN'], $tenant);

        $framework = (new ComplianceFramework())->setCode('CMFW-' . $uid)->setName('FW ' . $uid)->setVersion('1.0')
            ->setApplicableIndustry('all')->setRegulatoryBody('Test')->setMandatory(false)->setActive(true);
        $this->persist($framework);
        $this->source = $this->makeRequirement($framework, 'S-' . $uid);
        $this->target = $this->makeRequirement($framework, 'T-' . $uid);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $row = $this->em->find($entity::class, $entity->getId());
                if ($row !== null) {
                    $this->em->remove($row);
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
    public function deprecatedMappingIsRestoredToPublished(): void
    {
        $mapping = $this->makeMapping('deprecated');

        $result = $this->post($this->admin, [$mapping->getId()], 'published');

        self::assertSame(1, $result['changed']);
        self::assertSame('published', $this->reload($mapping)->getLifecycleState());
    }

    #[Test]
    public function draftCannotJumpStraightToPublished(): void
    {
        $mapping = $this->makeMapping('draft');

        $result = $this->post($this->admin, [$mapping->getId()], 'published');

        self::assertSame(0, $result['changed']);
        self::assertSame('transition_not_allowed', $result['rejected'][0]['reason']);
        self::assertSame('draft', $this->reload($mapping)->getLifecycleState());
    }

    #[Test]
    public function managerMaySubmitForReviewButNotApprove(): void
    {
        $draft = $this->makeMapping('draft');
        $inReview = $this->makeMapping('review');

        self::assertSame(1, $this->post($this->manager, [$draft->getId()], 'review')['changed']);
        self::assertSame('review', $this->reload($draft)->getLifecycleState());
        self::assertSame('in_review', $this->reload($draft)->getReviewStatus());

        $result = $this->post($this->manager, [$inReview->getId()], 'approved');
        self::assertSame('forbidden', $result['rejected'][0]['reason']);
        self::assertSame('review', $this->reload($inReview)->getLifecycleState());
    }

    #[Test]
    public function plainUserCannotChangeGlobalMappings(): void
    {
        $mapping = $this->makeMapping('draft');

        $this->client->loginUser($this->user);
        $token = $this->bulkToken();
        $this->client->request('POST', '/en/compliance/mapping/bulk-status-change', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['ids' => [$mapping->getId()], 'newStatus' => 'review', '_token' => $token]));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('draft', $this->reload($mapping)->getLifecycleState());
    }

    #[Test]
    public function plainUserCannotOpenTheMappingEditor(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/en/compliance/mapping/new');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @param list<int|null> $ids
     * @return array{ok: bool, changed: int, rejected: list<array{id: int, reason: string}>}
     */
    private function post(User $as, array $ids, string $newStatus): array
    {
        $this->client->loginUser($as);
        $token = $this->bulkToken();
        $this->client->request('POST', '/en/compliance/mapping/bulk-status-change', [], [], ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['ids' => $ids, 'newStatus' => $newStatus, '_token' => $token]));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function bulkToken(): string
    {
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $value = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/bulk_action', $value);
        $session->save();

        return $value;
    }

    private function reload(ComplianceMapping $mapping): ComplianceMapping
    {
        $this->em->clear();

        return $this->em->find(ComplianceMapping::class, $mapping->getId());
    }

    private function makeMapping(string $state): ComplianceMapping
    {
        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($this->em->find(ComplianceRequirement::class, $this->source->getId()));
        $mapping->setTargetRequirement($this->em->find(ComplianceRequirement::class, $this->target->getId()));
        $mapping->setMappingPercentage(80);
        $mapping->setLifecycleState($state);
        $this->persist($mapping);
        $this->em->flush();

        return $mapping;
    }

    private function makeRequirement(ComplianceFramework $framework, string $reqId): ComplianceRequirement
    {
        $requirement = new ComplianceRequirement();
        $requirement->setFramework($framework);
        $requirement->setRequirementId($reqId);
        $requirement->setTitle('Requirement ' . $reqId);
        $requirement->setDescription('Requirement description');
        $requirement->setCategory('test');
        $requirement->setPriority('medium');
        $requirement->setRequirementType('core');
        $this->persist($requirement);

        return $requirement;
    }

    /** @param list<string> $roles */
    private function makeUser(string $prefix, string $uid, array $roles, Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail($prefix . '_' . $uid . '@cm.test')->setFirstName('T')->setLastName('U')
            ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->persist($user);

        return $user;
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        $this->created[] = $entity;
    }
}
