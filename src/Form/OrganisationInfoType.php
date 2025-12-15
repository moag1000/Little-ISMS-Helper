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
        // Only add name field during setup wizard (not when editing tenant context)
        if ($options['include_name']) {
            $builder->add('name', TextType::class, [
                'label' => 'setup.organisation.name',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.organisation.name_required'),
                    new Assert\Length(min: 2, max: 255, minMessage: 'setup.organisation.name_min', maxMessage: 'setup.organisation.name_max'),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'setup.organisation.name_placeholder',
                ],
                'help' => 'setup.organisation.name_help',
            ]);
        }

        $builder->add('industries', ChoiceType::class, [
                'label' => 'setup.organisation.industries',
                'choices' => [
                    'setup.organisation.industry.automotive' => 'automotive',
                    'setup.organisation.industry.financial_services' => 'financial_services',
                    'setup.organisation.industry.healthcare' => 'healthcare',
                    'setup.organisation.industry.pharmaceutical' => 'pharmaceutical',
                    'setup.organisation.industry.digital_health' => 'digital_health',
                    'setup.organisation.industry.manufacturing' => 'manufacturing',
                    'setup.organisation.industry.it_services' => 'it_services',
                    'setup.organisation.industry.cloud_services' => 'cloud_services',
                    'setup.organisation.industry.energy' => 'energy',
                    'setup.organisation.industry.telecommunications' => 'telecommunications',
                    'setup.organisation.industry.critical_infrastructure' => 'critical_infrastructure',
                    'setup.organisation.industry.retail' => 'retail',
                    'setup.organisation.industry.public_sector' => 'public_sector',
                    'setup.organisation.industry.education' => 'education',
                    'setup.organisation.industry.other' => 'other',
                ],
                'required' => true,
                'multiple' => true,
                'expanded' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.organisation.industries_required'),
                    new Assert\Count(min: 1, minMessage: 'setup.organisation.industries_min'),
                ],
                'attr' => [
                    'class' => 'form-select',
                    'size' => 6,
                ],
                'help' => 'setup.organisation.industries_help',
                    'choice_translation_domain' => 'admin',
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
                'constraints' => [
                    new Assert\NotBlank(message: 'setup.organisation.employee_count_required'),
                ],
                'attr' => [
                    'class' => 'form-select',
                ],
                'help' => 'setup.organisation.employee_count_help',
                    'choice_translation_domain' => 'admin',
            ])
            ->add('country', ChoiceType::class, [
                'label' => 'setup.organisation.country',
                'choices' => [
                    'setup.organisation.country.de' => 'DE',
                    'setup.organisation.country.at' => 'AT',
                    'setup.organisation.country.ch' => 'CH',
                    '---' => null,
                    'setup.organisation.country.be' => 'BE',
                    'setup.organisation.country.dk' => 'DK',
                    'setup.organisation.country.fi' => 'FI',
                    'setup.organisation.country.fr' => 'FR',
                    'setup.organisation.country.it' => 'IT',
                    'setup.organisation.country.lu' => 'LU',
                    'setup.organisation.country.nl' => 'NL',
                    'setup.organisation.country.pl' => 'PL',
                    'setup.organisation.country.es' => 'ES',
                    'setup.organisation.country.se' => 'SE',
                    'setup.organisation.country.cz' => 'CZ',
                    '------' => null,
                    'setup.organisation.country.eu_other' => 'EU_OTHER',
                    'setup.organisation.country.non_eu' => 'NON_EU',
                ],
                'required' => true,
                'data' => 'DE',
                'attr' => [
                    'class' => 'form-select',
                ],
                'choice_translation_domain' => 'admin',
                'help' => 'setup.organisation.country_help',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'setup.organisation.description_label',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'setup.organisation.description_placeholder',
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
            'include_name' => true, // Set to false when editing tenant context (name comes from Tenant entity)
            'translation_domain' => 'setup',
        ]);
    }
}
