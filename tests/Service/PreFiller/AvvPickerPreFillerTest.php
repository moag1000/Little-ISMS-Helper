<?php

declare(strict_types=1);

namespace App\Tests\Service\PreFiller;

use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\PreFiller\AvvPickerPreFiller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AvvPickerPreFillerTest extends TestCase
{
    #[Test]
    public function returnsAllSuppliersWhenNoLegacyProcessorsJson(): void
    {
        $tenant = new Tenant();
        $s1 = (new Supplier())->setName('AWS GmbH');
        $s2 = (new Supplier())->setName('Mailgun');

        $repo = $this->createMock(SupplierRepository::class);
        $repo->method('findBy')->with(['tenant' => $tenant])->willReturn([$s1, $s2]);

        $pa = new ProcessingActivity();
        $candidates = (new AvvPickerPreFiller($repo))->candidatesFor($pa, $tenant);

        $this->assertCount(2, $candidates);
    }

    #[Test]
    public function reordersSuppliersWhenLegacyProcessorNamesMatch(): void
    {
        $tenant = new Tenant();
        $s1 = (new Supplier())->setName('AWS GmbH');
        $s2 = (new Supplier())->setName('Mailgun GmbH');
        $s3 = (new Supplier())->setName('Other');

        $repo = $this->createMock(SupplierRepository::class);
        $repo->method('findBy')->willReturn([$s1, $s2, $s3]);

        $pa = (new ProcessingActivity())
            ->setProcessors([['name' => 'Mailgun', 'contact' => 'x@y.com']]);

        $candidates = (new AvvPickerPreFiller($repo))->candidatesFor($pa, $tenant);

        $this->assertSame($s2, $candidates[0], 'Mailgun should rank first via name-match');
        $this->assertCount(3, $candidates);
    }

    #[Test]
    public function returnsEmptyWhenNoSuppliersExist(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(SupplierRepository::class);
        $repo->method('findBy')->willReturn([]);

        $candidates = (new AvvPickerPreFiller($repo))->candidatesFor(new ProcessingActivity(), $tenant);

        $this->assertSame([], $candidates);
    }
}
