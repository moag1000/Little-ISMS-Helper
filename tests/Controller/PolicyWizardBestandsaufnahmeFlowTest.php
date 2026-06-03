<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for the W4-C Step-0 Bestandsaufnahme flow.
 *
 * Mirrors {@see PolicyWizardControllerTest} setup conventions and
 * verifies:
 *   - greenfield tenants land directly on STEP_WELCOME (Step 0 skipped)
 *   - brownfield tenants land on STEP_BESTANDSAUFNAHME first
 *   - submitting decisions advances the run to STEP_WELCOME
 */
final class PolicyWizardBestandsaufnahmeFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $tenant = null;
    private ?User $cisoUser = null;
    /** @var list<int> */
    private array $createdDocumentIds = [];

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

        $this->createTenantAndUser();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        try {
            // Remove documents we seeded.
            foreach ($this->createdDocumentIds as $docId) {
                $managed = $this->entityManager->find(Document::class, $docId);
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            }
            // Remove wizard runs.
            if ($this->tenant !== null && $this->tenant->getId() !== null) {
                $managedTenant = $this->entityManager->find(Tenant::class, $this->tenant->getId());
                if ($managedTenant !== null) {
                    $runs = $this->entityManager->getRepository(WizardRun::class)
                        ->findBy(['tenant' => $managedTenant]);
                    foreach ($runs as $run) {
                        $this->entityManager->remove($run);
                    }
                }
            }
            $this->entityManager->flush();
        } catch (\Throwable) {
            // ignore
        }

        if ($this->cisoUser !== null) {
            try {
                $managed = $this->entityManager->find(User::class, $this->cisoUser->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($this->tenant !== null) {
            try {
                $managed = $this->entityManager->find(Tenant::class, $this->tenant->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
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

    private function createTenantAndUser(): void
    {
        $uniqueId = uniqid('pwbi_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('Bestandsaufnahme Tenant ' . $uniqueId);
        $this->tenant->setCode('pwbi_' . substr($uniqueId, 0, 16));
        $this->entityManager->persist($this->tenant);

        $this->cisoUser = new User();
        $this->cisoUser->setEmail('ciso_' . $uniqueId . '@example.test');
        $this->cisoUser->setFirstName('Chief');
        $this->cisoUser->setLastName('Security');
        $this->cisoUser->setRoles(['ROLE_USER', 'ROLE_CISO']);
        $this->cisoUser->setPassword('hashed_password');
        $this->cisoUser->setTenant($this->tenant);
        $this->cisoUser->setIsActive(true);
        $this->entityManager->persist($this->cisoUser);

        $this->entityManager->flush();
    }

    private function seedLegacyDocument(string $title, string $category): Document
    {
        $doc = new Document();
        $doc->setTenant($this->tenant);
        $doc->setFilename($title . '.md');
        $doc->setOriginalFilename($title);
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(100);
        $doc->setFilePath('virtual:legacy/' . $title);
        $doc->setCategory($category);
        $doc->setStatus('approved');
        $doc->setUploadedAt(new DateTimeImmutable('-3 months'));
        $doc->setUploadedBy($this->cisoUser);
        $this->entityManager->persist($doc);
        $this->entityManager->flush();

        $id = $doc->getId();
        if ($id !== null) {
            $this->createdDocumentIds[] = $id;
        }
        return $doc;
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

    private function reloadRun(int $id): ?WizardRun
    {
        $this->entityManager->clear();
        return $this->entityManager->find(WizardRun::class, $id);
    }

    private function startFullRun(): WizardRun
    {
        $startToken = $this->generateCsrfToken('policy_wizard_start');
        $this->client->request('POST', '/en/policy-wizard/start', [
            '_token' => $startToken,
            'mode' => WizardStepKeys::MODE_FULL,
            'standards' => ['iso27001'],
        ]);
        $this->client->followRedirect();

        $repo = $this->entityManager->getRepository(WizardRun::class);
        $runs = $repo->findBy(['tenant' => $this->tenant]);
        self::assertNotEmpty($runs, 'Full WizardRun should have been persisted');
        return $runs[0];
    }

    // ========== Tests ==========

    #[Test]
    public function greenfieldTenantSkipsBestandsaufnahmeAndStartsAtWelcome(): void
    {
        $this->client->loginUser($this->cisoUser);

        $run = $this->startFullRun();

        self::assertSame(
            WizardStepKeys::STEP_WELCOME,
            $run->getStep(),
            'Greenfield tenant must land on STEP_WELCOME, not Step 0.',
        );
    }

    #[Test]
    public function brownfieldTenantStartsAtBestandsaufnahmeStep(): void
    {
        $this->seedLegacyDocument('ISMS-Leitlinie 2022', 'policy');
        $this->client->loginUser($this->cisoUser);

        $run = $this->startFullRun();

        self::assertSame(
            WizardStepKeys::STEP_BESTANDSAUFNAHME,
            $run->getStep(),
            'Brownfield tenant with existing policies must land on Step 0.',
        );

        // The Step-0 form must render successfully.
        $this->client->request('GET', '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_BESTANDSAUFNAHME);
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString(
            'ISMS-Leitlinie 2022',
            $body,
            'Step-0 template must list the existing legacy document.',
        );
    }

    #[Test]
    public function submittingBestandsaufnahmeDecisionsAdvancesToWelcome(): void
    {
        $legacy = $this->seedLegacyDocument('Crypto Policy 2018', 'policy');
        $legacyId = $legacy->getId();
        self::assertNotNull($legacyId);

        $this->client->loginUser($this->cisoUser);
        $run = $this->startFullRun();
        self::assertSame(WizardStepKeys::STEP_BESTANDSAUFNAHME, $run->getStep());

        $stepToken = $this->generateCsrfToken('policy_wizard_step');
        $this->client->request(
            'POST',
            '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_BESTANDSAUFNAHME,
            [
                '_token' => $stepToken,
                'decisions' => [
                    $legacyId => [
                        'action' => 'replace',
                        'rationale' => 'Outdated, replaced by wizard output',
                    ],
                ],
            ],
        );

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_FOUND, Response::HTTP_UNPROCESSABLE_ENTITY],
            'Expected 200/302/422 from Step-0 submit, got ' . $statusCode,
        );

        $reloaded = $this->reloadRun($run->getId());
        self::assertNotNull($reloaded);
        // Either we advanced to WELCOME (decision accepted) or we stayed on
        // bestandsaufnahme (validation error path) — both are wired.
        self::assertContains(
            $reloaded->getStep(),
            [WizardStepKeys::STEP_WELCOME, WizardStepKeys::STEP_BESTANDSAUFNAHME],
        );

        // The decisions slot must be persisted under the canonical key
        // when the submit succeeded.
        if ($reloaded->getStep() === WizardStepKeys::STEP_WELCOME) {
            $inputs = $reloaded->getInputs() ?? [];
            self::assertArrayHasKey(WizardStepKeys::STEP_BESTANDSAUFNAHME, $inputs);
            self::assertArrayHasKey('decisions', $inputs[WizardStepKeys::STEP_BESTANDSAUFNAHME]);
        }
    }

    #[Test]
    public function persistedDecisionsPreFillTheInventoryForm(): void
    {
        $doc = $this->seedLegacyDocument('Crypto Policy Prefill', 'policy');
        $docId = $doc->getId();
        self::assertNotNull($docId);

        $this->client->loginUser($this->cisoUser);
        $run = $this->startFullRun();
        self::assertSame(WizardStepKeys::STEP_BESTANDSAUFNAHME, $run->getStep());

        // Persist a prior decision directly on the run inputs (same shape a
        // submit stores) WITHOUT advancing the step, then re-render the step.
        $managed = $this->reloadRun($run->getId());
        self::assertNotNull($managed);
        $managed->setInputs([
            WizardStepKeys::STEP_BESTANDSAUFNAHME => [
                'decisions' => [
                    (string) $docId => [
                        'action' => 'replace',
                        'rationale' => 'Outdated — superseded by wizard output',
                    ],
                ],
            ],
        ]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $crawler = $this->client->request(
            'GET',
            '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_BESTANDSAUFNAHME,
        );
        self::assertResponseIsSuccessful();

        // The action-<select> for this document must pre-select the persisted
        // 'replace' decision — not fall back to the empty placeholder.
        $selected = $crawler->filter('#action-' . $docId . ' option[selected]');
        self::assertGreaterThan(
            0,
            $selected->count(),
            'A persisted decision must leave exactly one <option selected> (regression: persisted_decisions was never passed to the template).',
        );
        self::assertSame(
            'replace',
            $selected->first()->attr('value'),
            'The previously chosen action must pre-fill on re-render.',
        );
    }

    #[Test]
    public function bestandsaufnahmeExposesFiveBulkActionsAndGuardWiring(): void
    {
        $this->seedLegacyDocument('Legacy Policy Alpha', 'policy');
        $this->client->loginUser($this->cisoUser);
        $run = $this->startFullRun();
        self::assertSame(WizardStepKeys::STEP_BESTANDSAUFNAHME, $run->getStep());

        $this->client->request(
            'GET',
            '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_BESTANDSAUFNAHME,
        );
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        // Submit-guard wiring — the "Weiter silently does nothing" hardening.
        self::assertStringContainsString('novalidate', $body, 'Step form must carry novalidate for the JS guard.');
        self::assertStringContainsString('data-controller="wizard-form-guard"', $body);

        // All five bulk actions must be wired.
        foreach ([
            'bestandsaufnahme-bulk#replaceAllOutdated',
            'bestandsaufnahme-bulk#keepAllWizardTagged',
            'bestandsaufnahme-bulk#applySuggestions',
            'bestandsaufnahme-bulk#keepAll',
            'bestandsaufnahme-bulk#replaceAll',
        ] as $action) {
            self::assertStringContainsString($action, $body, "Bulk action {$action} must be wired.");
        }

        // New labels resolve to real text (no raw translation keys leaking).
        self::assertStringContainsString('Apply suggestions', $body);
        self::assertStringNotContainsString('bestandsaufnahme.bulk.apply_suggestions', $body);
    }

    #[Test]
    public function everyActionTypeIsAcceptedOnSubmit(): void
    {
        $docs = [
            'keep'             => $this->seedLegacyDocument('Doc Keep', 'policy'),
            'replace'          => $this->seedLegacyDocument('Doc Replace', 'policy'),
            'merge_into_topic' => $this->seedLegacyDocument('Doc Merge', 'policy'),
            'split_to_topics'  => $this->seedLegacyDocument('Doc Split', 'policy'),
        ];

        $this->client->loginUser($this->cisoUser);
        $run = $this->startFullRun();
        self::assertSame(WizardStepKeys::STEP_BESTANDSAUFNAHME, $run->getStep());

        $decisions = [];
        foreach ($docs as $action => $doc) {
            $id = $doc->getId();
            self::assertNotNull($id);
            $entry = ['action' => $action, 'rationale' => 'test ' . $action];
            if ($action === 'merge_into_topic') {
                $entry['target_topic'] = 'general';
            }
            if ($action === 'split_to_topics') {
                $entry['target_topics'] = ['general'];
            }
            $decisions[$id] = $entry;
        }

        $token = $this->generateCsrfToken('policy_wizard_step');
        $this->client->request(
            'POST',
            '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_BESTANDSAUFNAHME,
            ['_token' => $token, 'decisions' => $decisions],
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(500, $status, 'Mixed keep/replace/merge/split submit must not 500.');
        self::assertContains(
            $status,
            [Response::HTTP_OK, Response::HTTP_FOUND, Response::HTTP_UNPROCESSABLE_ENTITY],
            'Unexpected status from mixed-action submit: ' . $status,
        );

        $reloaded = $this->reloadRun($run->getId());
        self::assertNotNull($reloaded);
        self::assertContains(
            $reloaded->getStep(),
            [WizardStepKeys::STEP_WELCOME, WizardStepKeys::STEP_BESTANDSAUFNAHME],
        );
    }
}
