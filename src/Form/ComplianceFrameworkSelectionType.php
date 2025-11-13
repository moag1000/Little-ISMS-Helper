<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for selecting compliance frameworks during setup wizard.
 *
 * ISO 27001 is pre-selected and mandatory (cannot be deselected).
 */
class ComplianceFrameworkSelectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $availableFrameworks = $options['available_frameworks'] ?? [];

        // Build choices from available frameworks
        $choices = [];
        foreach ($availableFrameworks as $framework) {
            $label = sprintf(
                '%s %s - %s',
                $framework['icon'],
                $framework['name'],
                $framework['description']
            );
            $choices[$label] = $framework['code'];
        }

        $builder
            ->add('frameworks', ChoiceType::class, [
                'label' => 'setup.compliance.frameworks',
                'choices' => $choices,
                'expanded' => true,
                'multiple' => true,
                'data' => ['ISO27001'], // ISO 27001 is pre-selected
                'attr' => [
                    'class' => 'compliance-framework-selection',
                ],
                'help' => 'setup.compliance.frameworks_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'compliance_frameworks',
            'available_frameworks' => [],
        ]);

        $resolver->setAllowedTypes('available_frameworks', 'array');
    }
}
