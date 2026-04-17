<?php

declare(strict_types=1);

namespace App\Form\Setup;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Was hast du schon?" Setup Step Form (WS-8).
 *
 * Erfasst, welche Frameworks bereits zertifiziert sind und welche neu hinzugefügt werden sollen.
 * Die Auswahl wird vom ReuseEstimationService genutzt, um Mapping-basierte Ableitungs-
 * vorschläge (WS-1) zu maximieren.
 *
 * Das Formular nutzt zwei unabhängige Multi-Selects:
 *  - alreadyCertified: Bereits vorhandene Zertifizierungen (Portfolio-Baseline)
 *  - newlyAdded: Neu hinzuzufügende Frameworks für das Onboarding
 *  - certificationDates: Optionales Mapping code => ISO-Date (YYYY-MM-DD) pro zertifiziertem Framework
 *
 * CSRF-Schutz ist aktiviert (Standard).
 */
final class ExistingFrameworksType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $availableFrameworks = $options['available_frameworks'] ?? [];

        $choices = [];
        foreach ($availableFrameworks as $framework) {
            $label = sprintf(
                '%s %s',
                $framework['icon'] ?? '',
                $framework['name'] ?? ($framework['code'] ?? '')
            );
            $choices[trim($label)] = $framework['code'];
        }

        $builder
            ->add('alreadyCertified', ChoiceType::class, [
                'label' => 'existing_frameworks.already_certified.label',
                'choices' => $choices,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'existing-frameworks-already-certified',
                    'data-existing-frameworks-target' => 'alreadyCertified',
                ],
                'help' => 'existing_frameworks.already_certified.help',
                'translation_domain' => 'setup_wizard',
            ])
            ->add('newlyAdded', ChoiceType::class, [
                'label' => 'existing_frameworks.newly_added.label',
                'choices' => $choices,
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'attr' => [
                    'class' => 'existing-frameworks-newly-added',
                    'data-existing-frameworks-target' => 'newlyAdded',
                ],
                'help' => 'existing_frameworks.newly_added.help',
                'translation_domain' => 'setup_wizard',
            ]);

        // Certification dates: associative array code => 'YYYY-MM-DD' (optional per framework).
        // Collection-Ansatz via DateType pro Eintrag würde ein dynamisches Kind-Form je
        // Auswahl erfordern — für den Setup-Dialog reicht ein HiddenType-ähnliches Map-Feld,
        // das clientseitig als <input type="date"> gerendert wird. Wir nutzen ChoiceType nicht,
        // sondern erlauben das Feld als "unmapped" array.
        $builder->add('certificationDates', ChoiceType::class, [
            'label' => 'existing_frameworks.certification_dates.label',
            'choices' => $choices,
            'expanded' => false,
            'multiple' => true,
            'required' => false,
            'mapped' => false,
            'attr' => [
                'class' => 'd-none existing-frameworks-certification-dates',
            ],
            'help' => 'existing_frameworks.certification_dates.help',
            'translation_domain' => 'setup_wizard',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'setup_existing_frameworks',
            'available_frameworks' => [],
            'translation_domain' => 'setup_wizard',
        ]);

        $resolver->setAllowedTypes('available_frameworks', 'array');
    }
}
