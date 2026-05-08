<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for the Mode 2 (Targeted re-run) flow + the
 * Konzern-Defaults landing page (W3-J).
 *
 * Mirrors {@see PolicyWizardControllerTest} setup conventions: real
 * tenant + user fixtures, CSRF token bootstrapped via a GET, and run
 * progression validated by reloading the WizardRun aggregate.
 */
class PolicyWizardTargetedFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $cisoUser = null;
    private ?User $unprivilegedUser = null;
    private ?User $groupCisoUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            if (
                str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        try {
            if ($this->testTenant !== null && $this->testTenant->getId() !== null) {
                $managedTenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($managedTenant !== null) {
                    $runs = $this->entityManager->getRepository(WizardRun::class)
                        ->findBy(['tenant' => $managedTenant]);
                    foreach ($runs as $run) {
                        $this->entityManager->remove($run);
                    }
                    $this->entityManager->flush();
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        foreach ([$this->cisoUser, $this->unprivilegedUser, $this->groupCisoUser] as $u) {
            if ($u === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find(User::class, $u->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($this->testTenant !== null) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant !== null) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('pwt_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('TargetedFlow Tenant ' . $uniqueId);
        $this->testTenant->setCode('pwt_' . substr($uniqueId, 0, 17));
        $this->entityManager->persist($this->testTenant);

        $this->cisoUser = new User();
        $this->cisoUser->setEmail('ciso_' . $uniqueId . '@example.test');
        $this->cisoUser->setFirstName('Chief');
        $this->cisoUser->setLastName('Security');
        $this->cisoUser->setRoles(['ROLE_USER', 'ROLE_CISO']);
        $this->cisoUser->setPassword('hashed_password');
        $this->cisoUser->setTenant($this->testTenant);
        $this->cisoUser->setIsActive(true);
        $this->entityManager->persist($this->cisoUser);

        $this->unprivilegedUser = new User();
        $this->unprivilegedUser->setEmail('user_' . $uniqueId . '@example.test');
        $this->unprivilegedUser->setFirstName('Plain');
        $this->unprivilegedUser->setLastName('User');
        $this->unprivilegedUser->setRoles(['ROLE_USER']);
        $this->unprivilegedUser->setPassword('hashed_password');
        $this->unprivilegedUser->setTenant($this->testTenant);
        $this->unprivilegedUser->setIsActive(true);
        $this->entityManager->persist($this->unprivilegedUser);

        // Group-CISO user — the only role with KONZERN_DEFAULTS access.
        $this->groupCisoUser = new User();
        $this->groupCisoUser->setEmail('gciso_' . $uniqueId . '@example.test');
        $this->groupCisoUser->setFirstName('Group');
        $this->groupCisoUser->setLastName('Security');
        $this->groupCisoUser->setRoles(['ROLE_USER', 'ROLE_CISO', 'ROLE_GROUP_CISO']);
        $this->groupCisoUser->setPassword('hashed_password');
        $this->groupCisoUser->setTenant($this->testTenant);
        $this->groupCisoUser->setIsActive(true);
        $this->entityManager->persist($this->groupCisoUser);

        $this->entityManager->flush();
    }

    private function generateCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/en/policy-wizard');
        $session = $this->client->getRequest()->getSession();

        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $tokenValue);
        $session->save();

        return $tokenValue;
    }

    private function startTargetedRun(): WizardRun
    {
        $startToken = $this->generateCsrfToken('policy_wizard_start');
        $this->client->request('POST', '/en/policy-wizard/start', [
            '_token' => $startToken,
            'mode' => WizardStepKeys::MODE_TARGETED,
        ]);
        $this->client->followRedirect();

        $repo = $this->entityManager->getRepository(WizardRun::class);
        $runs = $repo->findBy(['tenant' => $this->testTenant]);
        self::assertNotEmpty($runs, 'Targeted WizardRun should have been persisted');
        return $runs[0];
    }

    private function reloadRun(int $id): ?WizardRun
    {
        $this->entityManager->clear();
        return $this->entityManager->find(WizardRun::class, $id);
    }

    // ========== Tests ==========

    #[Test]
    public function testTargetedPickTopicsRendersForm(): void
    {
        $this->client->loginUser($this->cisoUser);
        $run = $this->startTargetedRun();

        // The Mode 2 sub-flow lands on welcome first, then targeted_pick.
        // Submit welcome to advance into the targeted_pick step.
        $stepToken = $this->generateCsrfToken('policy_wizard_step');
        $this->client->request('POST', '/en/policy-wizard/run/' . $run->getId() . '/step/welcome', [
            '_token' => $stepToken,
            'standards' => ['iso27001'],
            'mode' => WizardStepKeys::MODE_TARGETED,
        ]);
        $this->client->followRedirect();

        // The next step pointer should now be targeted_pick_topics.
        $reloaded = $this->reloadRun($run->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            WizardStepKeys::STEP_TARGETED_PICK,
            $reloaded->getStep(),
            'Targeted run should land on targeted_pick_topics after welcome',
        );

        // Render the step form — it must render the targeted-pick title
        // (either the table with the Stimulus hook or the empty-state
        // alert when the tenant has no approved documents yet).
        $this->client->request('GET', '/en/policy-wizard/run/' . $reloaded->getId() . '/step/targeted_pick_topics');
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        $hasController = str_contains($body, 'data-controller="policy-wizard-targeted-pick"');
        $hasEmptyAlert = str_contains($body, 'Pick topics') || str_contains($body, 'topic policies');
        self::assertTrue(
            $hasController || $hasEmptyAlert,
            'Targeted-pick template must render either the Stimulus-driven table or the empty-state alert',
        );
    }

    #[Test]
    public function testTargetedPickTopicsLimitsToTen(): void
    {
        $this->client->loginUser($this->cisoUser);
        $run = $this->startTargetedRun();

        // Submit welcome to advance to targeted_pick.
        $welcomeToken = $this->generateCsrfToken('policy_wizard_step');
        $this->client->request('POST', '/en/policy-wizard/run/' . $run->getId() . '/step/welcome', [
            '_token' => $welcomeToken,
            'standards' => ['iso27001'],
            'mode' => WizardStepKeys::MODE_TARGETED,
        ]);

        // Submit 11 topics — backend should clamp to 10 and surface a
        // validation error.
        $pickToken = $this->generateCsrfToken('policy_wizard_step');
        $tooMany = [];
        for ($i = 1; $i <= 11; $i++) {
            $tooMany[] = 'topic_' . $i;
        }
        $this->client->request('POST', '/en/policy-wizard/run/' . $run->getId() . '/step/targeted_pick_topics', [
            '_token' => $pickToken,
            'topics' => $tooMany,
        ]);

        // The step must NOT advance — either re-render with errors (422)
        // or redirect back to the same step.
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_FOUND],
            'Expected 200/302/422 when submitting >10 topics, got ' . $statusCode,
        );

        $reloaded = $this->reloadRun($run->getId());
        self::assertNotNull($reloaded);
        $persistedTopics = $reloaded->getTargetedTopics() ?? [];
        self::assertLessThanOrEqual(
            10,
            count($persistedTopics),
            'targetedTopics must never exceed the 10-topic cap',
        );
    }

    #[Test]
    public function testTargetedDiffPreviewRendersDiffTable(): void
    {
        $this->client->loginUser($this->cisoUser);
        $run = $this->startTargetedRun();

        // Pre-seed the run pointer to the diff step + persist some
        // picked topics so the template has data to render.
        $managedRun = $this->entityManager->find(WizardRun::class, $run->getId());
        self::assertNotNull($managedRun);
        $managedRun->setStep(WizardStepKeys::STEP_TARGETED_DIFF);
        $managedRun->setTargetedTopics(['risk_classification', 'operational_baselines']);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->request('GET', '/en/policy-wizard/run/' . $run->getId() . '/step/targeted_diff_preview');
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        // Either the diff table or the "no drift" alert must render —
        // both are acceptable depending on whether a previous COMPLETED
        // run exists. The form should always include the confirm checkbox.
        self::assertStringContainsString(
            'name="confirm"',
            $body,
            'Diff-preview template must always include the confirm checkbox',
        );
    }

    #[Test]
    public function testTargetedGenerateCallsComplete(): void
    {
        $this->client->loginUser($this->cisoUser);
        $run = $this->startTargetedRun();

        // Jump the run to the targeted_generate step + populate the
        // required state (picked topics, finding ref).
        $managedRun = $this->entityManager->find(WizardRun::class, $run->getId());
        self::assertNotNull($managedRun);
        $managedRun->setStep(WizardStepKeys::STEP_TARGETED_GENERATE);
        $managedRun->setTargetedTopics(['risk_classification']);
        $managedRun->setFindingReference('NCR-2026-04');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Render the terminal step.
        $this->client->request('GET', '/en/policy-wizard/run/' . $run->getId() . '/step/targeted_generate');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('NCR-2026-04', $body, 'Targeted-generate must echo the finding reference');

        // Trigger the complete action.
        $completeToken = $this->generateCsrfToken('policy_wizard_complete');
        $this->client->request('POST', '/en/policy-wizard/run/' . $run->getId() . '/complete', [
            '_token' => $completeToken,
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Accept 200 (rendered result), 302 (flash redirect), or 409
        // (hierarchy conflict re-render) — all prove the route is wired.
        self::assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_FOUND, Response::HTTP_CONFLICT],
            'Expected 200/302/409 from targeted complete, got ' . $statusCode,
        );
    }

    #[Test]
    public function testKonzernDefaultsLandingRendersForCisoOnly(): void
    {
        // Unprivileged user — must NOT reach the page (redirect via flash).
        $this->client->loginUser($this->unprivilegedUser);
        $this->client->request('GET', '/en/policy-wizard/konzern-defaults');
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [Response::HTTP_FOUND, Response::HTTP_FORBIDDEN],
            'Unprivileged users must be denied or redirected, got ' . $statusCode,
        );

        // Group-CISO — must successfully render the landing page.
        $this->client->restart();
        $this->client->loginUser($this->groupCisoUser);
        $this->client->request('GET', '/en/policy-wizard/konzern-defaults');
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        // The CTA form must reference the konzern_defaults_start route.
        self::assertStringContainsString(
            '/policy-wizard/konzern-defaults/start',
            $body,
            'Konzern-Defaults landing must expose the start CTA',
        );
    }
}
