<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Lifecycle\DeletionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W3: deleting an asset does not skip the four-eyes disposal — in the form,
 * the bulk action and the API alike (one policy, three entry points).
 */
final class DeletionPolicyTest extends TestCase
{
    #[Test]
    public function anAssetInUseIsNotDeletable(): void
    {
        $asset = (new Asset())->setStatus('active')->setCreatedAt(new \DateTimeImmutable('-2 years'));

        self::assertNotNull(DeletionPolicy::refusal($asset));
        self::assertNotNull(DeletionPolicy::refusal($asset->setStatus('retired')), 'retired still needs the four-eyes dispose step');
    }

    #[Test]
    public function aDisposedAssetOrAFreshTypoIsDeletable(): void
    {
        self::assertNull(DeletionPolicy::refusal((new Asset())->setStatus('disposed')->setCreatedAt(new \DateTimeImmutable('-2 years'))));
        self::assertNull(DeletionPolicy::refusal((new Asset())->setStatus('active')));
    }

    #[Test]
    public function otherRecordsAreNotGoverned(): void
    {
        self::assertNull(DeletionPolicy::refusal(new Risk()));
    }

    #[Test]
    public function everyDeleteEntryPointAsksThePolicy(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['src/Controller/AssetController.php', 'src/Service/BulkDeleteService.php', 'src/State/TenantAwareStateProcessor.php'] as $file) {
            self::assertStringContainsString('DeletionPolicy::refusal(', (string) file_get_contents($root . '/' . $file), $file);
        }
    }
}
