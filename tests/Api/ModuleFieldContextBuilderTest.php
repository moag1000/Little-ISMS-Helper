<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Api\ModuleFieldContextBuilder;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * S4 (2026-11): a module's fields on another module's record (DORA on an
 * incident, FAIR on a risk) are ignored in an API request body while that
 * module is off — as the web form leaves them out.
 */
final class ModuleFieldContextBuilderTest extends TestCase
{
    #[Test]
    public function fieldsOfAnInactiveModuleAreIgnoredOnWrite(): void
    {
        $context = $this->builder(active: ['incidents'])->createFromRequest(new Request(), false);

        self::assertContains('ictIncidentClassification', $context[AbstractNormalizer::IGNORED_ATTRIBUTES]);
        self::assertContains('crossBorderImpact', $context[AbstractNormalizer::IGNORED_ATTRIBUTES]);
        self::assertContains('keep', $context[AbstractNormalizer::IGNORED_ATTRIBUTES], 'existing ignored attributes survive');
        self::assertNotContains('title', $context[AbstractNormalizer::IGNORED_ATTRIBUTES]);
    }

    #[Test]
    public function activeModulesAndReadsAreUntouched(): void
    {
        $builder = $this->builder(active: ['incidents', 'nis2_dora', 'controls']);
        self::assertSame(['keep'], $builder->createFromRequest(new Request(), false)[AbstractNormalizer::IGNORED_ATTRIBUTES]);

        $reading = $this->builder(active: [])->createFromRequest(new Request(), true);
        self::assertSame(['keep'], $reading[AbstractNormalizer::IGNORED_ATTRIBUTES]);
    }

    #[Test]
    public function riskQuantificationFieldsFollowTheirModule(): void
    {
        $fields = $this->builder(active: ['risks'])->inactiveModuleFields(Risk::class);

        self::assertContains('singleLossExpectancy', $fields);
        self::assertContains('primaryLossMagnitudeMax', $fields);
        self::assertContains('ictRiskCategory', $fields);
        self::assertNotContains('title', $fields);
    }

    /** @param list<string> $active */
    private function builder(array $active): ModuleFieldContextBuilder
    {
        $inner = $this->createStub(SerializerContextBuilderInterface::class);
        $inner->method('createFromRequest')->willReturn([
            'resource_class' => Incident::class,
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['keep'],
        ]);
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback(static fn (string $key): bool => in_array($key, $active, true));

        return new ModuleFieldContextBuilder($inner, $modules);
    }
}
