<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for organization information during setup wizard.
 *
 * Collects basic organization data needed for compliance reporting and scope definition.
 */
class OrganisationInfoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'setup.organisation.name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'setup.organisation.name_required',
                    ]),
                    new Assert\Length([
                        'min' => 2,
                        'max' => 255,
                        'minMessage' => 'setup.organisation.name_min',
                        'maxMessage' => 'setup.organisation.name_max',
                    ]),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Beispiel GmbH',
                ],
                'help' => 'setup.organisation.name_help',
            ])
            ->add('industry', ChoiceType::class, [
                'label' => 'setup.organisation.industry',
                'choices' => [
                    'setup.organisation.industry.automotive' => 'automotive',
                    'setup.organisation.industry.financial_services' => 'financial_services',
                    'setup.organisation.industry.healthcare' => 'healthcare',
                    'setup.organisation.industry.manufacturing' => 'manufacturing',
                    'setup.organisation.industry.it_services' => 'it_services',
                    'setup.organisation.industry.energy' => 'energy',
                    'setup.organisation.industry.telecommunications' => 'telecommunications',
                    'setup.organisation.industry.retail' => 'retail',
                    'setup.organisation.industry.public_sector' => 'public_sector',
                    'setup.organisation.industry.education' => 'education',
                    'setup.organisation.industry.other' => 'other',
                ],
                'required' => true,
                'placeholder' => 'setup.organisation.industry.select',
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'setup.organisation.industry_help',
            ])
            ->add('employee_count', ChoiceType::class, [
                'label' => 'setup.organisation.employee_count',
                'choices' => [
                    'setup.organisation.employee_count.1_10' => '1-10',
                    'setup.organisation.employee_count.11_50' => '11-50',
                    'setup.organisation.employee_count.51_250' => '51-250',
                    'setup.organisation.employee_count.251_1000' => '251-1000',
                    'setup.organisation.employee_count.1001_plus' => '1001+',
                ],
                'required' => true,
                'placeholder' => 'setup.organisation.employee_count.select',
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'setup.organisation.employee_count_help',
            ])
            ->add('country', ChoiceType::class, [
                'label' => 'setup.organisation.country',
                'choices' => [
                    'Deutschland' => 'DE',
                    'Österreich' => 'AT',
                    'Schweiz' => 'CH',
                    '---' => null,
                    'Belgien' => 'BE',
                    'Dänemark' => 'DK',
                    'Finnland' => 'FI',
                    'Frankreich' => 'FR',
                    'Italien' => 'IT',
                    'Luxemburg' => 'LU',
                    'Niederlande' => 'NL',
                    'Polen' => 'PL',
                    'Spanien' => 'ES',
                    'Schweden' => 'SE',
                    'Tschechien' => 'CZ',
                    '------' => null,
                    'Andere EU' => 'EU_OTHER',
                    'Außerhalb EU' => 'NON_EU',
                ],
                'required' => true,
                'data' => 'DE',
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'setup.organisation.country_help',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'setup.organisation.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Kurze Beschreibung der Organisation und Geschäftstätigkeit...',
                ],
                'help' => 'setup.organisation.description_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'organisation_info',
        ]);
    }
}
