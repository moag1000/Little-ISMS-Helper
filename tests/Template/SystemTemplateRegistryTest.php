<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Entity\Risk;
use App\Service\ModuleConfigurationService;
use App\Template\SystemTemplate;
use App\Template\SystemTemplateRegistry;
use App\Template\TemplateProviderInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SystemTemplateRegistryTest extends TestCase
{
    #[Test]
    public function getReturnsRegisteredTemplate(): void
    {
        $registry = new SystemTemplateRegistry();
        $tpl = $this->tpl('test.foo', Risk::class, 'risks');

        $registry->register($tpl->key, $tpl);

        $this->assertSame($tpl, $registry->get('test.foo'));
    }

    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $registry = new SystemTemplateRegistry();
        $this->assertNull($registry->get('not.there'));
    }

    #[Test]
    public function providersAreHarvestedOnFirstAccess(): void
    {
        $tpl = $this->tpl('p.one', Risk::class, 'risks');
        $provider = new class ($tpl) implements TemplateProviderInterface {
            public function __construct(private readonly SystemTemplate $t) {}
            public function provide(): iterable { yield $this->t; }
        };

        $registry = new SystemTemplateRegistry([$provider]);

        $this->assertCount(1, $registry->all());
        $this->assertSame($tpl, $registry->get('p.one'));
    }

    #[Test]
    public function findByEntityFiltersByEntityClassAndLanguage(): void
    {
        $registry = new SystemTemplateRegistry();
        $registry->register('a', $this->tpl('a', Risk::class, 'risks', 'de'));
        $registry->register('b', $this->tpl('b', Risk::class, 'risks', 'en'));
        $registry->register('c', $this->tpl('c', \stdClass::class, 'risks', 'de'));

        $de = $registry->findByEntity(Risk::class, language: 'de');
        $this->assertCount(1, $de);
        $this->assertSame('a', $de[0]->key);

        $en = $registry->findByEntity(Risk::class, language: 'en');
        $this->assertCount(1, $en);
        $this->assertSame('b', $en[0]->key);
    }

    #[Test]
    public function findActiveExcludesInactiveModules(): void
    {
        $config = $this->createMock(ModuleConfigurationService::class);
        $config->method('isModuleActive')->willReturnMap([
            ['risks', true],
            ['ai_governance', false],
        ]);

        $registry = new SystemTemplateRegistry([], $config);
        $registry->register('keep', $this->tpl('keep', Risk::class, 'risks', 'de'));
        $registry->register('drop', $this->tpl('drop', Risk::class, 'ai_governance', 'de'));
        $registry->register('always', $this->tpl('always', Risk::class, null, 'de'));

        $active = $registry->findActive(Risk::class, 'de');
        $keys = array_map(fn (SystemTemplate $t) => $t->key, $active);

        $this->assertContains('keep', $keys);
        $this->assertContains('always', $keys);
        $this->assertNotContains('drop', $keys);
    }

    #[Test]
    public function bulkTemplateRecordsMergePrefillAndItems(): void
    {
        $tpl = new SystemTemplate(
            key: 'risk.bulk',
            entityClass: Risk::class,
            module: 'risks',
            language: 'de',
            name: 'Bulk',
            description: 'Bulk test',
            prefill: ['probability' => 2, 'impact' => 3],
            items: [
                ['title' => 'A'],
                ['title' => 'B', 'impact' => 5],  // overrides prefill
            ],
        );

        $records = $tpl->records();
        $this->assertCount(2, $records);
        $this->assertSame(2, $records[0]['probability']);
        $this->assertSame('A', $records[0]['title']);
        $this->assertSame(5, $records[1]['impact']);  // override wins
    }

    private function tpl(
        string $key,
        string $entityClass,
        ?string $module = null,
        string $language = 'de',
    ): SystemTemplate {
        return new SystemTemplate(
            key: $key,
            entityClass: $entityClass,
            module: $module,
            language: $language,
            name: $key,
            description: $key . ' description',
            prefill: [],
        );
    }
}
