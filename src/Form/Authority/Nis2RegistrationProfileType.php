<?php

declare(strict_types=1);

namespace App\Form\Authority;

use App\Entity\Authority\Nis2RegistrationProfile;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * F29 — Form for NIS-2 BSI-Portal registration profile.
 *
 * All mandatory BSI-Portal fields are collected here.
 * Translation domain: eu_authorities
 * Contact fields use EntityType for User selection.
 */
final class Nis2RegistrationProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sectorChoices = [];
        foreach (Nis2RegistrationProfile::VALID_SECTORS as $sector) {
            $sectorChoices['eu_authorities.nis2_registration.sector.' . $sector] = $sector;
        }

        $categoryChoices = [];
        foreach (Nis2RegistrationProfile::VALID_CATEGORIES as $category) {
            $categoryChoices['eu_authorities.nis2_registration.category.' . $category] = $category;
        }

        $builder
            ->add('organizationLegalName', TextType::class, [
                'label' => 'eu_authorities.nis2_registration.field.legal_name',
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['maxlength' => 255],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('organizationLegalForm', TextType::class, [
                'label' => 'eu_authorities.nis2_registration.field.legal_form',
                'required' => true,
                'constraints' => [new NotBlank()],
                'help' => 'eu_authorities.nis2_registration.help.legal_form',
                'attr' => ['maxlength' => 100, 'placeholder' => 'eu_authorities.nis2_registration.placeholder.legal_form'],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('commercialRegisterCity', TextType::class, [
                'label' => 'eu_authorities.nis2_registration.field.register_city',
                'required' => true,
                'constraints' => [new NotBlank()],
                'attr' => ['maxlength' => 255],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('commercialRegisterNumber', TextType::class, [
                'label' => 'eu_authorities.nis2_registration.field.register_number',
                'required' => true,
                'constraints' => [new NotBlank()],
                'help' => 'eu_authorities.nis2_registration.help.register_number',
                'attr' => ['maxlength' => 100],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('vatId', TextType::class, [
                'label' => 'eu_authorities.nis2_registration.field.vat_id',
                'required' => false,
                'attr' => ['maxlength' => 50],
                'translation_domain' => 'eu_authorities',
            ])
            // @no-module-gate-required: whole Nis2RegistrationProfileType form is NIS-2-scoped
            //   (only rendered behind nis2_dora module). Per-field gating would be redundant.
            ->add('nis2Sector', ChoiceType::class, [
                'label' => 'eu_authorities.nis2_registration.field.sector',
                'choices' => $sectorChoices,
                'required' => true,
                'constraints' => [new NotBlank()],
                'placeholder' => 'eu_authorities.nis2_registration.placeholder.sector',
                'choice_translation_domain' => 'eu_authorities',
                'translation_domain' => 'eu_authorities',
            ])
            // @no-module-gate-required: see above — form is NIS-2-scoped.
            ->add('nis2EntityCategory', ChoiceType::class, [
                'label' => 'eu_authorities.nis2_registration.field.category',
                'choices' => $categoryChoices,
                'required' => true,
                'constraints' => [new NotBlank()],
                'choice_translation_domain' => 'eu_authorities',
                'translation_domain' => 'eu_authorities',
            ])
            ->add('affectedHeadcount', IntegerType::class, [
                'label' => 'eu_authorities.nis2_registration.field.headcount',
                'required' => true,
                'constraints' => [new NotBlank(), new Positive()],
                'help' => 'eu_authorities.nis2_registration.help.headcount',
                'translation_domain' => 'eu_authorities',
            ])
            ->add('affectedAnnualTurnoverEur', NumberType::class, [
                'label' => 'eu_authorities.nis2_registration.field.turnover',
                'required' => false,
                'scale' => 2,
                'help' => 'eu_authorities.nis2_registration.help.turnover',
                'translation_domain' => 'eu_authorities',
            ])
            ->add('ictDependencyDescription', TextareaType::class, [
                'label' => 'eu_authorities.nis2_registration.field.ict_dependency',
                'required' => true,
                'constraints' => [new NotBlank()],
                'help' => 'eu_authorities.nis2_registration.help.ict_dependency',
                'attr' => ['rows' => 5],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('incidentReportingContact', EntityType::class, [
                'class' => User::class,
                'label' => 'eu_authorities.nis2_registration.field.incident_contact',
                'required' => true,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'constraints' => [new NotBlank()],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('securityResponsibleContact', EntityType::class, [
                'class' => User::class,
                'label' => 'eu_authorities.nis2_registration.field.security_contact',
                'required' => true,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'constraints' => [new NotBlank()],
                'translation_domain' => 'eu_authorities',
            ])
            ->add('backupSecurityContact', EntityType::class, [
                'class' => User::class,
                'label' => 'eu_authorities.nis2_registration.field.backup_contact',
                'required' => false,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder' => 'eu_authorities.nis2_registration.placeholder.backup_contact',
                'translation_domain' => 'eu_authorities',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Nis2RegistrationProfile::class,
            'translation_domain' => 'eu_authorities',
        ]);
    }
}
