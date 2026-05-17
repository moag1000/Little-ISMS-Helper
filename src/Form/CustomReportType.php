<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CustomReport;
use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class CustomReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'field.name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'placeholder.name',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'field.description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'placeholder.description',
                ],
                'help' => 'help.description',
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'field.category',
                'choices' => [
                    'category.general' => CustomReport::CATEGORY_GENERAL,
                    'category.executive' => CustomReport::CATEGORY_EXECUTIVE,
                    'category.risk' => CustomReport::CATEGORY_RISK,
                    'category.compliance' => CustomReport::CATEGORY_COMPLIANCE,
                    'category.bcm' => CustomReport::CATEGORY_BCM,
                    'category.asset' => CustomReport::CATEGORY_ASSET,
                    'category.audit' => CustomReport::CATEGORY_AUDIT,
                    'category.incident' => CustomReport::CATEGORY_INCIDENT,
                ],
                'required' => true,
                'choice_translation_domain' => 'report_builder',
            ])
            ->add('layout', ChoiceType::class, [
                'label' => 'field.layout',
                'choices' => [
                    'layout.single' => CustomReport::LAYOUT_SINGLE,
                    'layout.two_column' => CustomReport::LAYOUT_TWO_COLUMN,
                    'layout.dashboard' => CustomReport::LAYOUT_DASHBOARD,
                    'layout.wide_narrow' => CustomReport::LAYOUT_WIDE_NARROW,
                    'layout.narrow_wide' => CustomReport::LAYOUT_NARROW_WIDE,
                ],
                'required' => true,
                'choice_translation_domain' => 'report_builder',
            ])
            // Tri-State owner fields
            ->add('owner', EntityType::class, [
                'label' => 'field.owner_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'placeholder.owner_user',
                'help' => 'help.owner_user',
            ])
            ->add('ownerPerson', EntityType::class, [
                'label' => 'field.owner_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'placeholder.owner_person',
                'help' => 'help.owner_person',
            ])
            ->add('ownerDeputyPersons', EntityType::class, [
                'label' => 'field.owner_deputies',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'help.owner_deputies',
            ])
            ->add('isShared', ChoiceType::class, [
                'label' => 'field.is_shared',
                'required' => true,
                'choices' => [
                    'choice.yes' => true,
                    'choice.no' => false,
                ],
                'choice_translation_domain' => 'report_builder',
                'expanded' => true,
                'attr' => ['class' => 'form-check-inline'],
            ])
            ->add('isTemplate', ChoiceType::class, [
                'label' => 'field.is_template',
                'required' => true,
                'choices' => [
                    'choice.yes' => true,
                    'choice.no' => false,
                ],
                'choice_translation_domain' => 'report_builder',
                'expanded' => true,
                'attr' => ['class' => 'form-check-inline'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomReport::class,
            'translation_domain' => 'report_builder',
            'constraints' => [
                new Callback([$this, 'validateOwnerSlot']),
            ],
        ]);
    }

    public function validateOwnerSlot(?CustomReport $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getOwner() === null && $entity->getOwnerPerson() === null) {
            $context->buildViolation('error.owner_required_user_or_person')
                ->atPath('owner')
                ->addViolation();
        }
    }
}
