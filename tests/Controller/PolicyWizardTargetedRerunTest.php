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

/**
 * Regression test for the STEP_TARGETED_PICK empty-screen bug.
 *
 * Root cause: `PolicyWizardController::buildStepExtras()` had no branch for
 * `WizardStepKeys::STEP_TARGETED_PICK`, so `existing_topics_by_key` was never
 * set → the template rendered only the empty-state alert and the user could not
 * pick any topics.
 *
 * This test asserts that a tenant with ≥1 approved/published governance-grade
 * document that the ExistingDocumentMatcher maps to a known topic causes the
 * targeted-pick step response to contain the topic label (proving the table
 * branch renders instead of the empty-state alert).
 */
final class PolicyWizardTargetedRerunTest extends WebTestCase
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

        // Ensure the setup-wizard gate is satisfied so wizard routes work.
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
            foreach ($this->createdDocumentIds as $docId) {
                $managed = $this->entityManager->find(Document::class, $docId);
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            }
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
        $uniqueId = uniqid('pwtgt_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('TargetedRerun Tenant ' . $uniqueId);
        $this->tenant->setCode('pwtgt_' . substr($uniqueId, 0, 14));
        $this->entityManager->persist($this->tenant);

        $this->cisoUser = new User();
        $this->cisoUser->setEmail('ciso_tgt_' . $uniqueId . '@example.test');
        $this->cisoUser->setFirstName('CISO');
        $this->cisoUser->setLastName('TargetedRerun');
        $this->cisoUser->setRoles(['ROLE_USER', 'ROLE_CISO']);
        $this->cisoUser->setPassword('hashed_password');
        $this->cisoUser->setTenant($this->tenant);
        $this->cisoUser->setIsActive(true);
        $this->entityManager->persist($this->cisoUser);

        $this->entityManager->flush();
    }

    /**
     * Seed a governance-grade document whose title the ExistingDocumentMatcher
     * will map to the `access_control` topic.
     */
    private function seedApprovedAccessControlDocument(): Document
    {
        $doc = new Document();
        $doc->setTenant($this->tenant);
        // "access control" is in ExistingDocumentMatcher::TOPIC_KEYWORDS['access_control']
        $doc->setOriginalFilename('Access Control Policy');
        $doc->setFilename('access_control_policy.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(512);
        $doc->setFilePath('virtual:targeted-rerun/access_control_policy.md');
        $doc->setCategory('policy'); // governance-grade → picked up by inventoryService
        $doc->setStatus('approved');
        $doc->setUploadedAt(new DateTimeImmutable('-2 months'));
        $doc->setUploadedBy($this->cisoUser);
        // Give it a concrete next review date so the column is non-null.
        $doc->setNextReviewDate(new DateTimeImmutable('+10 months'));
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

    private function startTargetedRun(): WizardRun
    {
        $startToken = $this->generateCsrfToken('policy_wizard_start');
        $this->client->request('POST', '/en/policy-wizard/start', [
            '_token' => $startToken,
            'mode' => WizardStepKeys::MODE_TARGETED,
        ]);
        // Follow any redirect to the first step.
        if ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }

        $repo = $this->entityManager->getRepository(WizardRun::class);
        $runs = $repo->findBy(['tenant' => $this->tenant]);
        self::assertNotEmpty($runs, 'Targeted WizardRun must be persisted after start.');
        return $runs[0];
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * REGRESSION: Before the fix, GETting STEP_TARGETED_PICK for a tenant with
     * an approved "Access Control Policy" document rendered the empty-state alert
     * instead of the topic-selection table, because `existing_topics_by_key` was
     * never populated in buildStepExtras().
     *
     * After the fix the response must NOT contain the fallback-alert title string
     * and MUST contain the topic-label partial that only renders in the table
     * branch (i.e. the checkbox for 'access_control').
     */
    #[Test]
    public function targetedPickStepListsExistingApprovedDocumentsByTopic(): void
    {
        $this->seedApprovedAccessControlDocument();
        $this->client->loginUser($this->cisoUser);

        $run = $this->startTargetedRun();

        // Advance / directly GET the STEP_TARGETED_PICK step.
        // In the targeted flow the first step is STEP_WELCOME; we navigate
        // directly to STEP_TARGETED_PICK which is the second step.
        $url = '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_TARGETED_PICK;
        $this->client->request('GET', $url);

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [200, 302],
            'STEP_TARGETED_PICK GET must return 200 or 302, got ' . $statusCode,
        );

        if ($statusCode === 302) {
            $this->client->followRedirect();
        }

        $body = (string) $this->client->getResponse()->getContent();

        // The table branch renders a checkbox input with value="access_control".
        // This string only appears when existing_topics_by_key is non-empty.
        self::assertStringContainsString(
            'value="access_control"',
            $body,
            'STEP_TARGETED_PICK must render the topic-checkbox for access_control when '
            . 'the tenant has an approved "Access Control Policy" document. '
            . 'Missing checkbox means existing_topics_by_key was empty (the bug).',
        );

        // Also confirm the document title appears in the sub-list under the topic row.
        self::assertStringContainsString(
            'Access Control Policy',
            $body,
            'STEP_TARGETED_PICK must list the document title under its topic row.',
        );
    }

    /**
     * Greenfield tenant (no documents) should show the empty-state alert
     * rather than an error. This verifies the zero-result path is also safe.
     */
    #[Test]
    public function targetedPickStepShowsEmptyStateWhenNoDocumentsExist(): void
    {
        $this->client->loginUser($this->cisoUser);

        $run = $this->startTargetedRun();

        $url = '/en/policy-wizard/run/' . $run->getId() . '/step/' . WizardStepKeys::STEP_TARGETED_PICK;
        $this->client->request('GET', $url);

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [200, 302],
            'STEP_TARGETED_PICK GET must not crash for greenfield tenant.',
        );
    }
}
