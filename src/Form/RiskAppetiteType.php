<?php

namespace App\Form;

use App\Entity\RiskAppetite;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RiskAppetiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', TextType::class, [
                'label' => 'risk_appetite.field.category',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 100,
                    'placeholder' => 'risk_appetite.placeholder.category'
                ],
                'help' => 'risk_appetite.help.category',
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'risk_appetite.validation.category_max_length'
                    ])
                ]
            ])
            ->add('maxAcceptableRisk', IntegerType::class, [
                'label' => 'risk_appetite.field.max_acceptable_risk',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 25,
                    'placeholder' => '1-25'
                ],
                'help' => 'risk_appetite.help.max_acceptable_risk',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'risk_appetite.validation.max_acceptable_risk_required']),
                    new Assert\Range([
                        'min' => 1,
                        'max' => 25,
                        'notInRangeMessage' => 'risk_appetite.validation.max_acceptable_risk_range'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'risk_appetite.field.description',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'risk_appetite.placeholder.description'
                ],
                'help' => 'risk_appetite.help.description',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'risk_appetite.validation.description_required'])
                ]
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'risk_appetite.field.is_active',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'risk_appetite.help.is_active'
            ])
            ->add('approvedBy', EntityType::class, [
                'label' => 'risk_appetite.field.approved_by',
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getFullName() . ' (' . $user->getEmail() . ')';
                },
                'placeholder' => 'risk_appetite.placeholder.approved_by',
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'risk_appetite.help.approved_by'
            ])
            ->add('approvedAt', DateTimeType::class, [
                'label' => 'risk_appetite.field.approved_at',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'risk_appetite.help.approved_at'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RiskAppetite::class,
        ]);
    }
}
