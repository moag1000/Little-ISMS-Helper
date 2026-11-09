<?php

declare(strict_types=1);

namespace App\Tests\Service\Supplier;

use App\Entity\SupplierCriticalityLevel;
use App\Entity\Tenant;
use App\Repository\SupplierCriticalityLevelRepository;
use App\Service\Supplier\SupplierCriticalityLabels;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The admin's supplier criticality levels reach the supplier pages (lesson 10): English label,
 * colour and default level are read, not only stored.
 */
final class SupplierCriticalityLabelsTest extends TestCase
{
    private function labels(string $locale, ?SupplierCriticalityLevel $default = null): SupplierCriticalityLabels
    {
        $vital = (new SupplierCriticalityLevel())->setCode('vital')->setLabelDe('Lebenswichtig')
            ->setLabelEn('Vital')->setColor('danger');
        $minor = (new SupplierCriticalityLevel())->setCode('minor')->setLabelDe('Nebensächlich')->setColor('info');

        $repository = $this->createStub(SupplierCriticalityLevelRepository::class);
        $repository->method('findActiveByTenant')->willReturn([$vital, $minor]);
        $repository->method('findDefaultForTenant')->willReturn($default ?? $minor);

        $request = new Request();
        $request->setLocale($locale);
        $stack = new RequestStack();
        $stack->push($request);

        return new SupplierCriticalityLabels($repository, $stack);
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty($tenant, 'id'))->setValue($tenant, 7);

        return $tenant;
    }

    #[Test]
    public function theConfiguredLabelAndColourAreShownInTheUsersLanguage(): void
    {
        $tenant = $this->tenant();

        self::assertSame(['label' => 'Vital', 'tone' => 'danger'], $this->labels('en')->describe('vital', $tenant));
        self::assertSame(['label' => 'Lebenswichtig', 'tone' => 'danger'], $this->labels('de')->describe('vital', $tenant));
        self::assertSame(['label' => 'Nebensächlich', 'tone' => 'primary'], $this->labels('en')->describe('minor', $tenant), 'no English label: German one');
        self::assertSame(['Vital' => 'vital', 'Nebensächlich' => 'minor'], $this->labels('en')->choices($tenant));
    }

    #[Test]
    public function theDefaultLevelIsTheOneTheAdminMarked(): void
    {
        self::assertSame('minor', $this->labels('de')->defaultCode($this->tenant()));
        self::assertNull($this->labels('de')->defaultCode(null));
    }

    #[Test]
    public function withoutConfiguredLevelsTheCanonicalFourApply(): void
    {
        $labels = $this->labels('en');

        self::assertSame(['label' => 'Critical', 'tone' => 'danger'], $labels->describe('critical', null));
        self::assertSame(['label' => 'Medium', 'tone' => 'primary'], $labels->describe('medium', null));
        self::assertSame(['Critical' => 'critical', 'High' => 'high', 'Medium' => 'medium', 'Low' => 'low'], $labels->choices(null));
    }
}
