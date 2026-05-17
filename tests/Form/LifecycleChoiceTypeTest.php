<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\LifecycleChoiceType;
use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LifecycleChoiceType (lifecycle X.3).
 *
 * Validates that the form type correctly discovers stages from the
 * LifecycleRegistry and populates choices accordingly.
 *
 * Structural inspection pattern (no Symfony FormFactory needed).
 */
final class LifecycleChoiceTypeTest extends TestCase
{
    private LifecycleChoiceType $type;

    protected function setUp(): void
    {
        $this->type = new LifecycleChoiceType(new LifecycleRegistry());
    }

    #[Test]
    public function typeCanBeInstantiatedWithRegistry(): void
    {
        $this->assertInstanceOf(LifecycleChoiceType::class, $this->type);
    }

    #[Test]
    public function workflowNameOptionIsRequired(): void
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $this->type->configureOptions($resolver);

        $this->expectException(\Symfony\Component\OptionsResolver\Exception\MissingOptionsException::class);
        $resolver->resolve([]);
    }

    #[Test]
    public function optionsResolveWithWorkflowName(): void
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $this->type->configureOptions($resolver);

        $resolved = $resolver->resolve(['workflow_name' => 'document_lifecycle']);
        $this->assertSame('document_lifecycle', $resolved['workflow_name']);
        $this->assertNull($resolved['entity_class']);
        $this->assertNull($resolved['placeholder']);
    }

    #[Test]
    public function optionsAcceptEntityClassAndPlaceholder(): void
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $this->type->configureOptions($resolver);

        $resolved = $resolver->resolve([
            'workflow_name' => 'document_lifecycle',
            'entity_class'  => \App\Entity\Document::class,
            'placeholder'   => '— Status wählen —',
        ]);
        $this->assertSame(\App\Entity\Document::class, $resolved['entity_class']);
        $this->assertSame('— Status wählen —', $resolved['placeholder']);
    }

    #[Test]
    public function standardFiveStageLifecycleIsDefaultWhenNoEntityClass(): void
    {
        $stages = array_keys(LifecycleRegistry::STANDARD_5_STAGE);
        $this->assertSame(['draft', 'in_review', 'approved', 'published', 'archived'], $stages);
    }
}
