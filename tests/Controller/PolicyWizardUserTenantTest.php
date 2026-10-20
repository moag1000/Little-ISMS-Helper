<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Policy wizard: every submitted User id must belong to the run's
 * organisation (approver slots: an eligible approver of it). A foreign id is
 * rejected with a 422, never stored and never rendered (name / e-mail).
 */
final class PolicyWizardUserTenantTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $ciso;
    private User $foreignAdmin;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('pwu', true));

        $this->tenant = (new Tenant())->setName('PWU ' . $this->uid)->setCode('pwu_' . $this->uid);
        $foreign = (new Tenant())->setName('PWU F ' . $this->uid)->setCode('pwuf_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->persist($foreign);
        $this->ciso = $this->user('ciso', ['ROLE_USER', 'ROLE_CISO', 'ROLE_ADMIN'], $this->tenant);
        $this->foreignAdmin = $this->user('foreignadmin', ['ROLE_ADMIN', 'ROLE_DPO'], $foreign);
        $this->em->flush();
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles, Tenant $tenant): User
    {
        $user = (new User())->setEmail($name . '_' . $this->uid . '@example.test')->setFirstName('First')
            ->setLastName(ucfirst($name) . $this->uid)->setRoles($roles)->setPassword('x')
            ->setTenant($tenant)->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function runAt(string $step): WizardRun
    {
        $run = (new WizardRun())->setTenant($this->tenant)->setStartedByUser($this->ciso)
            ->setMode(WizardStepKeys::MODE_FULL)->setStartedAt(new \DateTimeImmutable())
            ->setStatus(WizardStepKeys::STATUS_IN_PROGRESS)->setStandardsAdopted(['iso27001'])
            ->setStep($step)->setInputs([]);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function stepToken(): string
    {
        $this->client->request('GET', '/en/policy-wizard');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/policy_wizard_step', $token);
        $session->save();

        return $token;
    }

    /**
     * @return iterable<string, array{string, \Closure(int): array<string, mixed>, string}>
     */
    public static function slotProvider(): iterable
    {
        yield 'roles: approval chain' => [
            WizardStepKeys::STEP_ROLES,
            static fn (int $id): array => ['approval_chain' => [(string) $id]],
            'approval_chain',
        ];
        yield 'operational baselines: DPO user' => [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            static fn (int $id): array => ['dpo_user_id' => (string) $id],
            'dpo_user_id',
        ];
        yield 'operational baselines: BCM officer user' => [
            WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            static fn (int $id): array => ['bcm_officer_user_id' => (string) $id],
            'bcm_officer_user_id',
        ];
        yield 'lifecycle: default approver' => [
            WizardStepKeys::STEP_LIFECYCLE,
            static fn (int $id): array => ['default_approver_user_id' => (string) $id],
            'default_approver_user_id',
        ];
        yield 'lifecycle: approver per template' => [
            WizardStepKeys::STEP_LIFECYCLE,
            static fn (int $id): array => ['approver_per_template' => ['isms_policy' => (string) $id]],
            'approver_per_template',
        ];
    }

    /** @param \Closure(int): array<string, mixed> $payload */
    #[Test]
    #[DataProvider('slotProvider')]
    public function foreignUserIdIsRejectedNotStoredAndNotRendered(string $step, \Closure $payload, string $field): void
    {
        $run = $this->runAt($step);
        $this->client->loginUser($this->ciso);
        $token = $this->stepToken();

        $this->client->request(
            'POST',
            sprintf('/en/policy-wizard/run/%d/step/%s', $run->getId(), $step),
            ['_token' => $token] + $payload((int) $this->foreignAdmin->getId()),
        );

        self::assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('does not belong to your organisation', $html, 'tenant error shown for ' . $field);
        self::assertStringNotContainsString('foreignadmin_' . $this->uid, $html, 'foreign e-mail rendered');
        self::assertStringNotContainsString('Foreignadmin' . $this->uid, $html, 'foreign name rendered');

        $this->em->clear();
        $inputs = $this->em->find(WizardRun::class, $run->getId())->getInputs() ?? [];
        self::assertArrayNotHasKey($step, $inputs, 'step input persisted despite the foreign id');
    }

    /** @param \Closure(int): array<string, mixed> $payload */
    #[Test]
    #[DataProvider('slotProvider')]
    public function userOfTheRunOrganisationPassesTheTenantCheck(string $step, \Closure $payload, string $field): void
    {
        $run = $this->runAt($step);
        $this->client->loginUser($this->ciso);
        $token = $this->stepToken();

        $this->client->request(
            'POST',
            sprintf('/en/policy-wizard/run/%d/step/%s', $run->getId(), $step),
            ['_token' => $token] + $payload((int) $this->ciso->getId()),
        );

        self::assertStringNotContainsString(
            'does not belong to your organisation',
            (string) $this->client->getResponse()->getContent(),
            'own approver rejected for ' . $field,
        );
    }
}
