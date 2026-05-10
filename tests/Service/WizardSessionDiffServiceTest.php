<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\WizardSession;
use App\Service\WizardSessionDiffService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WizardSessionDiffService — V4-EF-3 Wizard-History Diff-View.
 */
class WizardSessionDiffServiceTest extends TestCase
{
    private WizardSessionDiffService $service;

    protected function setUp(): void
    {
        $this->service = new WizardSessionDiffService();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeSession(int $score, array $assessmentResults): WizardSession
    {
        $session = new WizardSession();
        $session->setWizardType(WizardSession::WIZARD_ISO27001);
        $session->setOverallScore($score);
        $session->setAssessmentResults($assessmentResults);
        $session->setStatus(WizardSession::STATUS_COMPLETED);

        // Force createdAt/updatedAt (lifecycle callbacks not invoked in unit tests)
        $ref = new \ReflectionClass($session);
        foreach (['createdAt', 'updatedAt'] as $prop) {
            $p = $ref->getProperty($prop);
            $p->setValue($session, new DateTimeImmutable());
        }

        return $session;
    }

    private function assessmentWithOneCategory(
        string $catKey,
        string $catName,
        array $items,
    ): array {
        return [
            $catKey => [
                'name'  => $catName,
                'score' => 50,
                'items' => $items,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    #[Test]
    public function diffIdenticalSnapshotsReturnsAllUnchanged(): void
    {
        $items = [
            'check_a' => ['score' => 100, 'label' => 'Check A'],
            'check_b' => ['score' => 50,  'label' => 'Check B'],
        ];

        $from = $this->makeSession(75, $this->assessmentWithOneCategory('access', 'Access Control', $items));
        $to   = $this->makeSession(75, $this->assessmentWithOneCategory('access', 'Access Control', $items));

        $result = $this->service->diff($from, $to);

        self::assertSame(0, $result['summary']['added']);
        self::assertSame(0, $result['summary']['removed']);
        self::assertSame(0, $result['summary']['changed']);
        self::assertSame(2, $result['summary']['unchanged']);
        self::assertSame(0.0, $result['score_delta']);
    }

    #[Test]
    public function diffDetectsChangedItems(): void
    {
        $fromItems = ['check_a' => ['score' => 50, 'label' => 'Check A']];
        $toItems   = ['check_a' => ['score' => 80, 'label' => 'Check A']];

        $from = $this->makeSession(50, $this->assessmentWithOneCategory('access', 'Access', $fromItems));
        $to   = $this->makeSession(80, $this->assessmentWithOneCategory('access', 'Access', $toItems));

        $result = $this->service->diff($from, $to);

        self::assertSame(1, $result['summary']['changed']);
        self::assertSame(0, $result['summary']['added']);
        self::assertSame(0, $result['summary']['removed']);
        self::assertSame(30.0, $result['score_delta']);

        $item = $result['sections']['access']['items'][0];
        self::assertSame('changed', $item['changeType']);
        self::assertSame(50, $item['oldAnswer']);
        self::assertSame(80, $item['newAnswer']);
    }

    #[Test]
    public function diffDetectsAddedItems(): void
    {
        // $to has an extra check not present in $from
        $fromItems = ['check_a' => ['score' => 100, 'label' => 'Check A']];
        $toItems   = [
            'check_a' => ['score' => 100, 'label' => 'Check A'],
            'check_b' => ['score' => 60,  'label' => 'Check B'],
        ];

        $from = $this->makeSession(100, $this->assessmentWithOneCategory('access', 'Access', $fromItems));
        $to   = $this->makeSession(80,  $this->assessmentWithOneCategory('access', 'Access', $toItems));

        $result = $this->service->diff($from, $to);

        self::assertSame(1, $result['summary']['added']);
        self::assertSame(1, $result['summary']['unchanged']);
        self::assertSame(0, $result['summary']['removed']);

        $addedItem = array_values(array_filter(
            $result['sections']['access']['items'],
            static fn($i) => $i['changeType'] === 'added',
        ))[0];
        self::assertSame('check_b', $addedItem['question']);
        self::assertNull($addedItem['oldAnswer']);
        self::assertSame(60, $addedItem['newAnswer']);
    }

    #[Test]
    public function diffDetectsRemovedItems(): void
    {
        // $from has a check that no longer exists in $to
        $fromItems = [
            'check_a' => ['score' => 80, 'label' => 'Check A'],
            'check_b' => ['score' => 60, 'label' => 'Check B'],
        ];
        $toItems = ['check_a' => ['score' => 80, 'label' => 'Check A']];

        $from = $this->makeSession(70, $this->assessmentWithOneCategory('access', 'Access', $fromItems));
        $to   = $this->makeSession(80, $this->assessmentWithOneCategory('access', 'Access', $toItems));

        $result = $this->service->diff($from, $to);

        self::assertSame(1, $result['summary']['removed']);
        self::assertSame(1, $result['summary']['unchanged']);

        $removedItem = array_values(array_filter(
            $result['sections']['access']['items'],
            static fn($i) => $i['changeType'] === 'removed',
        ))[0];
        self::assertSame('check_b', $removedItem['question']);
        self::assertSame(60, $removedItem['oldAnswer']);
        self::assertNull($removedItem['newAnswer']);
    }

    #[Test]
    public function diffHandlesNewCategoryInTo(): void
    {
        $from = $this->makeSession(60, $this->assessmentWithOneCategory('access', 'Access', [
            'check_a' => ['score' => 60, 'label' => 'A'],
        ]));
        $toResults = $this->assessmentWithOneCategory('access', 'Access', [
            'check_a' => ['score' => 60, 'label' => 'A'],
        ]);
        $toResults['crypto'] = [
            'name'  => 'Cryptography',
            'score' => 100,
            'items' => ['check_crypto' => ['score' => 100, 'label' => 'Crypto Check']],
        ];
        $to = $this->makeSession(80, $toResults);

        $result = $this->service->diff($from, $to);

        self::assertSame(1, $result['summary']['added']);   // check_crypto is added
        self::assertSame(1, $result['summary']['unchanged']); // check_a unchanged
        self::assertArrayHasKey('crypto', $result['sections']);
    }

    #[Test]
    public function diffHandlesEntireCategoryRemovedInTo(): void
    {
        $fromResults = $this->assessmentWithOneCategory('access', 'Access', [
            'check_a' => ['score' => 80, 'label' => 'A'],
        ]);
        $fromResults['crypto'] = [
            'name'  => 'Cryptography',
            'score' => 100,
            'items' => ['check_crypto' => ['score' => 100, 'label' => 'Crypto']],
        ];
        $from = $this->makeSession(90, $fromResults);
        $to   = $this->makeSession(80, $this->assessmentWithOneCategory('access', 'Access', [
            'check_a' => ['score' => 80, 'label' => 'A'],
        ]));

        $result = $this->service->diff($from, $to);

        self::assertSame(1, $result['summary']['removed']);
        self::assertSame(1, $result['summary']['unchanged']);
    }

    #[Test]
    public function diffReturnsEmptyWhenBothSessionsHaveNoResults(): void
    {
        $from = $this->makeSession(0, []);
        $to   = $this->makeSession(0, []);

        $result = $this->service->diff($from, $to);

        self::assertSame([], $result['sections']);
        self::assertSame(0, $result['summary']['total']);
        self::assertSame(0.0, $result['score_delta']);
    }

    #[Test]
    public function diffScoreDeltaIsCorrect(): void
    {
        $from = $this->makeSession(55, []);
        $to   = $this->makeSession(78, []);

        $result = $this->service->diff($from, $to);

        self::assertSame(23.0, $result['score_delta']);
        self::assertSame(55, $result['from_score']);
        self::assertSame(78, $result['to_score']);
    }
}
