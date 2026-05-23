<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class AssetQuickType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'asset.field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'asset.placeholder.name',
                ],
            ])
            ->add('assetType', ChoiceType::class, [
                'label' => 'asset.field.type',
                'choices' => [
                    'asset.type.information' => 'Information',
                    'asset.type.software' => 'Software',
                    'asset.type.hardware' => 'Hardware',
                    'asset.type.service' => 'Service',
                    'asset.type.personnel' => 'Personnel',
                    'asset.type.physical' => 'Physical',
                    'asset.type.ai_agent' => 'ai_agent',
                ],
                'required' => true,
                'choice_translation_domain' => 'asset',
            ])
            ->add('ownerUser', EntityType::class, [
                'label' => 'asset.field.owner',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'asset.placeholder.owner_user',
            ])
            // Junior-ISB-Audit-2026-05-22 S13: Quick-Path Validation-Gap parity with full AssetType.
            // Owner Either-Or constraint requires Person as second slot — otherwise users
            // could only ever satisfy it via ownerUser, which contradicts the dual-state design.
            ->add('ownerPerson', EntityType::class, [
                'label' => 'asset.field.owner_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'asset.placeholder.owner_person',
            ])
            ->add('confidentialityValue', IntegerType::class, [
                'label' => 'asset.field.confidentiality',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
            ->add('integrityValue', IntegerType::class, [
                'label' => 'asset.field.integrity',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
            ->add('availabilityValue', IntegerType::class, [
                'label' => 'asset.field.availability',
                'required' => true,
                'attr' => [
                    'min' => 1,
                    'max' => 5,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Asset::class,
            'translation_domain' => 'asset',
            // Junior-ISB-Audit-2026-05-22 S13: Quick-Path Validation-Gap parity with full AssetType.
            // Mirrors AssetType::validateOwnerSlot so quick-add cannot bypass the
            // "either ownerUser OR ownerPerson is required" rule (audit-classic data-quality drift).
            'constraints' => [
                new Callback([$this, 'validateOwnerSlot']),
            ],
        ]);
    }

    /**
     * Junior-ISB-Audit-2026-05-22 S13: mirrors {@see AssetType::validateOwnerSlot()}.
     *
     * Enforces the dual-state Owner contract from the entity layer at the
     * quick-add Form layer. Without this, Asset rows can be persisted with
     * neither ownerUser nor ownerPerson set, breaking the getEffectiveOwner()
     * resolution down-stream and causing repeat data-cleanup work.
     */
    public function validateOwnerSlot(?Asset $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getOwnerUser() === null && $entity->getOwnerPerson() === null) {
            $context->buildViolation('asset.error.owner_required_user_or_person')
                ->atPath('ownerUser')
                ->addViolation();
        }
    }
}
