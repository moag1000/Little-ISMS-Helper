<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\LifecycleController;
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
 * The four-eyes approver of a lifecycle transition arrives as a user id in the
 * request body; an approver of another organisation than the record's must be
 * refused (User is not covered by the TenantFilter). The CSRF attribute is
 * outside this test — the action is invoked directly.
 */
final class LifecycleApproverTenantTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $actor;
    private string $uid;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('lat', true));

        $this->tenant = (new Tenant())->setName('LAT ' . $this->uid)->setCode('lat_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->actor = $this->makeUser('actor', $this->tenant);
        $this->em->flush();

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->actor, 'main', $this->actor->getRoles()));
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant);
    }

    private function makeUser(string $name, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($name . '_' . $this->uid . '@example.com')
            ->setFirstName('First')
            ->setLastName($name)
            ->setRoles(['ROLE_MANAGER'])
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function approvedDocument(): Document
    {
        $doc = (new Document())
            ->setTenant($this->tenant)
            ->setFilename('lat.md')
            ->setOriginalFilename('lat.md')
            ->setMimeType('text/markdown')
            ->setFileSize(1)
            ->setFilePath('virtual:test')
            ->setCategory('policy')
            ->setUploadedAt(new \DateTimeImmutable());
        $doc->setStatus('approved');
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    private function postTransition(Document $doc, User $approver): array
    {
        $request = Request::create('/lifecycle/document/' . $doc->getId() . '/transition', 'POST', content: (string) json_encode([
            'transition' => 'publish',
            'four_eyes_approver_id' => $approver->getId(),
        ]));
        $response = static::getContainer()->get(LifecycleController::class)
            ->transition('document', (int) $doc->getId(), $request);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    #[Test]
    public function approverOfAnotherOrganisationIsRefused(): void
    {
        $foreignTenant = (new Tenant())->setName('LAT F ' . $this->uid)->setCode('latf_' . $this->uid);
        $this->em->persist($foreignTenant);
        $foreign = $this->makeUser('foreign', $foreignTenant);
        $doc = $this->approvedDocument();

        [$status, $body] = $this->postTransition($doc, $foreign);

        self::assertSame(422, $status);
        self::assertSame('four_eyes_approver_invalid', $body['error'] ?? null);
        $this->em->refresh($doc);
        self::assertSame('approved', $doc->getStatus());
    }

    #[Test]
    public function approverOfTheRecordOrganisationPassesTheTenantCheck(): void
    {
        $colleague = $this->makeUser('colleague', $this->tenant);
        $doc = $this->approvedDocument();

        [, $body] = $this->postTransition($doc, $colleague);

        self::assertNotSame('four_eyes_approver_invalid', $body['error'] ?? null);
    }

    #[Test]
    public function bulkTransitionRefusesAnApproverOfAnotherOrganisationPerRecord(): void
    {
        $foreignTenant = (new Tenant())->setName('LAT B ' . $this->uid)->setCode('latb_' . $this->uid);
        $this->em->persist($foreignTenant);
        $foreign = $this->makeUser('bulkforeign', $foreignTenant);
        $first = $this->approvedDocument();
        $second = $this->approvedDocument();

        $request = Request::create('/lifecycle/document/bulk-transition', 'POST', content: (string) json_encode([
            'transition' => 'publish',
            'ids' => [$first->getId(), $second->getId()],
            'four_eyes_approver_id' => $foreign->getId(),
        ]));
        $response = static::getContainer()->get(LifecycleController::class)->bulkTransition('document', $request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame([], $body['succeeded']);
        self::assertSame('four_eyes_approver_invalid', $body['failed'][(string) $first->getId()] ?? null);
        self::assertSame('four_eyes_approver_invalid', $body['failed'][(string) $second->getId()] ?? null);
        $this->em->refresh($first);
        self::assertSame('approved', $first->getStatus());
    }

    #[Test]
    public function unknownApproverIdAnswersExactlyLikeAForeignOne(): void
    {
        $foreignTenant = (new Tenant())->setName('LAT U ' . $this->uid)->setCode('latu_' . $this->uid);
        $this->em->persist($foreignTenant);
        $foreign = $this->makeUser('unknowncheck', $foreignTenant);
        $doc = $this->approvedDocument();
        [$foreignStatus, $foreignBody] = $this->postTransition($doc, $foreign);

        $url = '/lifecycle/document/' . $doc->getId() . '/transition';
        $request = Request::create($url, 'POST', content: (string) json_encode([
            'transition' => 'publish',
            'four_eyes_approver_id' => 999999999,
        ]));
        $response = static::getContainer()->get(LifecycleController::class)
            ->transition('document', (int) $doc->getId(), $request);

        self::assertSame($foreignStatus, $response->getStatusCode());
        self::assertSame($foreignBody, json_decode((string) $response->getContent(), true));

        $bulk = Request::create('/lifecycle/document/bulk-transition', 'POST', content: (string) json_encode([
            'transition' => 'publish',
            'ids' => [$doc->getId()],
            'four_eyes_approver_id' => 999999999,
        ]));
        $bulkBody = json_decode((string) static::getContainer()->get(LifecycleController::class)
            ->bulkTransition('document', $bulk)->getContent(), true);
        self::assertSame('four_eyes_approver_invalid', $bulkBody['failed'][(string) $doc->getId()] ?? null);
    }
}
