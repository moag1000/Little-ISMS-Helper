<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tier-1 Compliance Settings on the Tenant entity (locale, timezone,
 * financial year, TLP, DPO contact). Supervisory-authorities and
 * retention-policies are JSON blobs and edited in dedicated sub-forms
 * (or as JSON for now).
 */
final class TenantComplianceSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', ChoiceType::class, [
                'label' => 'admin.tenant_settings.locale',
                'choices' => [
                    'Deutsch (Deutschland)' => 'de_DE',
                    'Deutsch (Österreich)' => 'de_AT',
                    'Deutsch (Schweiz)' => 'de_CH',
                    'English (United States)' => 'en_US',
                    'English (United Kingdom)' => 'en_GB',
                    'Français (France)' => 'fr_FR',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'admin.tenant_settings.timezone',
                'choices' => array_combine(
                    \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE),
                    \DateTimeZone::listIdentifiers(\DateTimeZone::EUROPE),
                ),
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('financialYearStartMonth', ChoiceType::class, [
                'label' => 'admin.tenant_settings.financial_year_start',
                'choices' => [
                    'Januar' => 1, 'Februar' => 2, 'März' => 3, 'April' => 4,
                    'Mai' => 5, 'Juni' => 6, 'Juli' => 7, 'August' => 8,
                    'September' => 9, 'Oktober' => 10, 'November' => 11, 'Dezember' => 12,
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('tlpDefault', ChoiceType::class, [
                'label' => 'admin.tenant_settings.tlp_default',
                'help' => 'admin.tenant_settings.tlp_default_help',
                'choices' => [
                    'TLP:CLEAR (öffentlich teilbar)' => 'clear',
                    'TLP:GREEN (Community)' => 'green',
                    'TLP:AMBER (Need-to-Know, Empfohlen)' => 'amber',
                    'TLP:AMBER+STRICT' => 'amber+strict',
                    'TLP:RED (nur Empfänger)' => 'red',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('dpoContactName', TextType::class, [
                'label' => 'admin.tenant_settings.dpo_name',
                'help' => 'admin.tenant_settings.dpo_help',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(['max' => 255])],
            ])
            ->add('dpoContactEmail', EmailType::class, [
                'label' => 'admin.tenant_settings.dpo_email',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Email(), new Assert\Length(['max' => 255])],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
            'translation_domain' => 'admin',
        ]);
    }
}
