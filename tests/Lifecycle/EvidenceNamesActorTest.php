<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Controller\LifecycleController;
use App\Entity\AuditFinding;
use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Entity\FourEyesApprovalRequest;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Entity\User;
use App\Lifecycle\EventListener\LifecycleEvidenceStamper;
use App\Lifecycle\Exception\FourEyesRequiredException;
use App\Lifecycle\FourEyes\LifecycleCountersign;
use App\Lifecycle\TransitionAccess;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * S27 (8th assessment F-01b): evidence names the person who acted. A second
 * person merely named by somebody else (request body, form field) is never
 * stamped as approver, verifier or closer and never satisfies four eyes; the
 * second person confirms in their own session (counter-sign request).
 *
 * Discovers every `four_eyes: true` transition in config/workflows and checks
 * that naming another person than the logged-in one is refused for each.
 */
final class EvidenceNamesActorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private string $uid;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('ena', true));
        $this->tenant = (new Tenant())->setName('ENA ' . $this->uid)->setCode('ena_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant); // @tenant-pin-ok: test fixture, kernel is shut down after each test
    }

    #[Test]
    public function namingAnotherPersonNeverConfirmsAFourEyesTransition(): void
    {
        $actor = $this->user('actor', ['ROLE_CISO']);
        $named = $this->user('named', ['ROLE_CISO']);
        $this->loginAs($actor);
        $access = static::getContainer()->get(TransitionAccess::class);

        $fourEyes = $this->fourEyesTransitions();
        self::assertNotSame([], $fourEyes);
        foreach ($fourEyes as [$workflow, $transition]) {
            try {
                $access->assertAllowed(new \stdClass(), $workflow, $transition, $actor, $named, false, false);
                self::fail(sprintf('%s.%s accepted a second person who did not confirm', $workflow, $transition));
            } catch (FourEyesRequiredException $e) {
                self::assertSame('approver_not_confirmed', $e->reasonCode);
            }
        }

        self::assertTrue($access->isConfirmedApprover($actor), 'the logged-in person confirms themselves');
        self::assertTrue($access->isSessionApprover($actor));
        self::assertFalse($access->isSessionApprover($named));
    }

    #[Test]
    public function theStamperNamesTheActorUnlessTheSecondPersonConfirmed(): void
    {
        $actor = $this->user('actor', ['ROLE_CISO']);
        $named = $this->user('named', ['ROLE_CISO']);
        $unconfirmed = ['user' => $actor, 'four_eyes_approver' => $named, 'four_eyes_confirmed' => false];

        $change = new ChangeRequest();
        $this->complete($change, 'approve', $unconfirmed);
        self::assertSame($actor->getFullName(), $change->getApprovedBy());

        $audit = new InternalAudit();
        $this->complete($audit, 'approve', $unconfirmed);
        self::assertSame($actor, $audit->getApprovedBy());

        $finding = new AuditFinding();
        $this->complete($finding, 'verify', $unconfirmed);
        self::assertSame($actor, $finding->getNcVerifiedBy());

        $confirmed = new InternalAudit();
        $this->complete($confirmed, 'approve', ['user' => $actor, 'four_eyes_approver' => $named, 'four_eyes_confirmed' => true]);
        self::assertSame($named, $confirmed->getApprovedBy(), 'a confirmed second person carries the step');
    }

    #[Test]
    public function aFourEyesTransitionWaitsForTheSecondPersonsOwnConfirmation(): void
    {
        $actor = $this->user('actor', ['ROLE_MANAGER']);
        $colleague = $this->user('colleague', ['ROLE_MANAGER']);
        $doc = $this->approvedDocument();
        $this->loginAs($actor);

        $request = Request::create('/lifecycle/document/' . $doc->getId() . '/transition', 'POST', content: (string) json_encode([
            'transition' => 'publish',
            'four_eyes_approver_id' => $colleague->getId(),
        ]));
        $response = static::getContainer()->get(LifecycleController::class)->transition('document', (int) $doc->getId(), $request);
        $body = json_decode((string) $response->getContent(), true);

        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue($body['countersign_requested'] ?? false);
        $this->em->refresh($doc);
        self::assertSame('approved', $doc->getStatus(), 'naming the colleague publishes nothing');

        $countersign = static::getContainer()->get(FourEyesApprovalRequestRepository::class)->find((int) $body['request_id']);
        self::assertInstanceOf(FourEyesApprovalRequest::class, $countersign);
        self::assertSame($colleague->getId(), $countersign->getRequestedApprover()?->getId());

        $this->loginAs($colleague);
        static::getContainer()->get(LifecycleCountersign::class)->confirm($countersign, $colleague);

        $this->em->refresh($doc);
        $this->em->refresh($countersign);
        self::assertSame('published', $doc->getStatus());
        self::assertSame(FourEyesApprovalRequest::STATUS_APPROVED, $countersign->getStatus());
        self::assertSame($colleague->getId(), $countersign->getApprovedBy()?->getId());
    }

    #[Test]
    public function theRequesterCannotConfirmTheirOwnRequest(): void
    {
        $actor = $this->user('actor', ['ROLE_MANAGER']);
        $this->user('colleague', ['ROLE_MANAGER']);
        $doc = $this->approvedDocument();
        $this->loginAs($actor);

        $countersign = static::getContainer()->get(LifecycleCountersign::class)
            ->request($doc, 'document', 'document_lifecycle', 'publish', $actor, null);

        $this->expectException(\App\Exception\BusinessRule\BusinessRuleException::class);
        static::getContainer()->get(LifecycleCountersign::class)->confirm($countersign, $actor);
    }

    /** @return list<array{0: string, 1: string}> */
    private function fourEyesTransitions(): array
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 2) . '/config/workflows/*.yaml') ?: [] as $file) {
            $config = Yaml::parseFile($file);
            foreach (($config['framework']['workflows'] ?? []) as $workflow => $definition) {
                foreach (($definition['transitions'] ?? []) as $name => $transition) {
                    if (($transition['metadata']['four_eyes'] ?? false) === true) {
                        $found[] = [(string) $workflow, (string) $name];
                    }
                }
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $context */
    private function complete(object $subject, string $transition, array $context): void
    {
        (new LifecycleEvidenceStamper())->onCompleted(new CompletedEvent(
            $subject,
            new Marking(['x' => 1]),
            new Transition($transition, ['a'], ['b']),
            $this->createStub(WorkflowInterface::class),
            $context,
        ));
    }

    private function approvedDocument(): Document
    {
        $doc = (new Document())
            ->setTenant($this->tenant)
            ->setFilename('ena.md')
            ->setOriginalFilename('ena.md')
            ->setMimeType('text/markdown')
            ->setFileSize(1)
            ->setFilePath('virtual:test')
            ->setCategory('policy')
            ->setUploadedAt(new \DateTimeImmutable());
        $doc->setStatus('approved'); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($name))
            ->setLastName('ENA')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
