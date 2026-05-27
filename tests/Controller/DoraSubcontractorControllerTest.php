<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DoraSubcontractorController;
use App\Entity\DoraSubcontractor;
use App\Entity\Supplier;
use App\Repository\DoraSubcontractorRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tree-rendering smoke test for DoraSubcontractorController.
 *
 * The `buildTree()` private method is the load-bearing tree-shape transformer
 * fed to the index template. We probe it via reflection to keep the test
 * lean (no kernel boot / no DB).
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraSubcontractorControllerTest extends TestCase
{
    /**
     * Reflection-accessor for the controller's private buildTree() method.
     *
     * @param DoraSubcontractor[] $subs
     */
    private function invokeBuildTree(array $subs): array
    {
        $controller = new DoraSubcontractorController(
            $this->createMock(DoraSubcontractorRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(Security::class),
            $this->createMock(ModuleConfigurationService::class),
        );

        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('buildTree');
        return $method->invoke($controller, $subs);
    }

    /**
     * Reflection-trick to assign an `id` to an entity that normally only gets
     * one from Doctrine. Needed so `getParentSubcontractor()?->getId()` keys
     * the byParentId bucket correctly.
     */
    private function assignId(object $entity, int $id): void
    {
        $ref = new ReflectionClass($entity);
        $prop = $ref->getProperty('id');
        $prop->setValue($entity, $id);
    }

    #[Test]
    public function emptyListYieldsEmptyForest(): void
    {
        self::assertSame([], $this->invokeBuildTree([]));
    }

    #[Test]
    public function twoTier2RootsUnderOneSupplierBucketTogether(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Prime ICT');
        $this->assignId($supplier, 100);

        $r1 = new DoraSubcontractor();
        $r1->setName('Root A');
        $r1->setParentSupplier($supplier);
        $this->assignId($r1, 1);

        $r2 = new DoraSubcontractor();
        $r2->setName('Root B');
        $r2->setParentSupplier($supplier);
        $this->assignId($r2, 2);

        $forest = $this->invokeBuildTree([$r1, $r2]);

        self::assertCount(1, $forest);
        self::assertSame($supplier, $forest[0]['supplier']);
        self::assertCount(2, $forest[0]['roots']);
        self::assertSame('Root A', $forest[0]['roots'][0]['node']->getName());
        self::assertSame('Root B', $forest[0]['roots'][1]['node']->getName());
    }

    #[Test]
    public function nestedChildAttachesToParentSubtree(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Prime ICT');
        $this->assignId($supplier, 100);

        $root = new DoraSubcontractor();
        $root->setName('Root');
        $root->setParentSupplier($supplier);
        $this->assignId($root, 1);

        $child = new DoraSubcontractor();
        $child->setName('Child');
        $child->setParentSupplier($supplier);
        $child->setParentSubcontractor($root);
        $this->assignId($child, 2);

        $forest = $this->invokeBuildTree([$root, $child]);

        self::assertCount(1, $forest);
        self::assertCount(1, $forest[0]['roots']);
        self::assertSame('Root', $forest[0]['roots'][0]['node']->getName());
        self::assertCount(1, $forest[0]['roots'][0]['children']);
        self::assertSame('Child', $forest[0]['roots'][0]['children'][0]['node']->getName());
    }

    #[Test]
    public function subsForDifferentSuppliersBucketSeparately(): void
    {
        $s1 = new Supplier();
        $s1->setName('Prime A');
        $this->assignId($s1, 100);
        $s2 = new Supplier();
        $s2->setName('Prime B');
        $this->assignId($s2, 200);

        $a = new DoraSubcontractor();
        $a->setName('Sub of A');
        $a->setParentSupplier($s1);
        $this->assignId($a, 1);

        $b = new DoraSubcontractor();
        $b->setName('Sub of B');
        $b->setParentSupplier($s2);
        $this->assignId($b, 2);

        $forest = $this->invokeBuildTree([$a, $b]);

        self::assertCount(2, $forest);
    }
}
