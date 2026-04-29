<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RiskQuickType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'risk.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'risk.placeholder.title',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'risk.field.category',
                'choices' => [
                    'risk.category.financial' => 'financial',
                    'risk.category.operational' => 'operational',
                    'risk.category.compliance' => 'compliance',
                    'risk.category.strategic' => 'strategic',
                    'risk.category.reputational' => 'reputational',
                    'risk.category.security' => 'security',
                ],
                'placeholder' => 'risk.placeholder.category',
                'required' => true,
                'choice_translation_domain' => 'risk',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'risk.field.description',
                'required' => true,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.description',
                ],
            ])
            ->add('asset', EntityType::class, [
                'label' => 'risk.field.asset',
                'class' => Asset::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.asset',
                'required' => false,
            ])
            ->add('probability', IntegerType::class, [
                'label' => 'risk.field.probability',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('impact', IntegerType::class, [
                'label' => 'risk.field.impact',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('riskOwner', EntityType::class, [
                'label' => 'risk.field.risk_owner',
                'class' => User::class,
                'choice_label' => fn(User $user): string => $user->getFullName() . ' (' . $user->getEmail() . ')',
                'placeholder' => 'risk.placeholder.risk_owner',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Risk::class,
            'translation_domain' => 'risk',
        ]);
    }
}
