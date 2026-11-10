<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Asset;
use App\Entity\DataBreach;
use App\Entity\Risk;
use App\Lifecycle\DeletionPolicy;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * W3 + S29: an asset leaves the inventory only through retire → dispose (four
 * eyes) and then stays as disposal evidence; evidence in an end state is never
 * deleted. The full discovery over every lifecycle is
 * {@see EndStateDeletionContractTest} (Gate 100).
 */
final class DeletionPolicyTest extends KernelTestCase
{
    private DeletionPolicy $policy;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->policy = self::getContainer()->get(DeletionPolicy::class);
    }

    #[Test]
    public function anAssetInUseIsNotDeletable(): void
    {
        $asset = (new Asset())->setStatus('active')->setCreatedAt(new \DateTimeImmutable('-2 years'));

        self::assertNotNull($this->policy->refusal($asset));
        self::assertNotNull($this->policy->refusal($asset->setStatus('retired')), 'retired still needs the four-eyes dispose step');
    }

    #[Test]
    public function aDisposedAssetIsKeptAsEvidenceAndAFreshTypoIsDeletable(): void
    {
        $disposed = (new Asset())->setStatus('disposed')->setCreatedAt(new \DateTimeImmutable('-2 years'));

        self::assertSame('lifecycle.errors.delete_end_state', $this->policy->refusal($disposed)?->translationKey);
        self::assertNull($this->policy->refusal((new Asset())->setStatus('active')));
    }

    #[Test]
    public function aNotifiedBreachIsKept(): void
    {
        $breach = new DataBreach();
        $breach->setStatus('authority_notified');

        self::assertNotNull($this->policy->refusal($breach));
    }

    #[Test]
    public function anUnsavedRiskBeforeAnyDecisionIsDeletable(): void
    {
        self::assertNull($this->policy->refusal(new Risk()));
    }
}
