<?php

declare(strict_types=1);

namespace App\Form;

use App\Lifecycle\LifecycleRegistry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A constrained status select that discovers valid places from the
 * LifecycleRegistry (lifecycle X.3).
 *
 * Usage:
 *   $builder->add('status', LifecycleChoiceType::class, [
 *       'workflow_name'  => 'document_lifecycle',
 *       'entity_class'   => Document::class,
 *   ]);
 *
 * Options:
 *   workflow_name  string       Required. Used for translation prefix.
 *   entity_class   class-string Entity FQCN for LifecycleRegistry stage lookup.
 *   placeholder    string|null  Optional placeholder (default: null → no empty choice).
 */
final class LifecycleChoiceType extends AbstractType
{
    public function __construct(
        private readonly LifecycleRegistry $registry,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entityClass = $options['entity_class'];
        $stages = $entityClass !== null
            ? $this->registry->getStages($entityClass)
            : array_keys(LifecycleRegistry::STANDARD_5_STAGE);

        $choices = [];
        foreach ($stages as $place) {
            $choices['lifecycle.' . $place] = $place;
        }

        $builder->add('status', ChoiceType::class, [
            'label'                    => 'lifecycle.field.status',
            'translation_domain'       => 'lifecycle',
            'choices'                  => $choices,
            'choice_translation_domain' => 'lifecycle',
            'placeholder'              => $options['placeholder'],
            'required'                 => $options['placeholder'] === null,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('workflow_name');
        $resolver->setDefaults([
            'entity_class' => null,
            'placeholder'  => null,
        ]);
        $resolver->setAllowedTypes('workflow_name', 'string');
        $resolver->setAllowedTypes('entity_class', ['null', 'string']);
        $resolver->setAllowedTypes('placeholder', ['null', 'string']);
    }
}
