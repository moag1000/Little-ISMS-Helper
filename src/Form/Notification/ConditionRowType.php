<?php

declare(strict_types=1);

namespace App\Form\Notification;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A single condition row: field + operator + value.
 */
class ConditionRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('field', TextType::class, [
                'label'    => 'notification.condition.field',
                'required' => false,
                'attr'     => [
                    'placeholder' => 'notification.condition.field_placeholder',
                    'class'       => 'form-control form-control-sm',
                ],
            ])
            ->add('operator', TextType::class, [
                'label'    => 'notification.condition.operator',
                'required' => false,
                'attr'     => [
                    'placeholder' => '=',
                    'class'       => 'form-control form-control-sm',
                ],
            ])
            ->add('value', TextType::class, [
                'label'    => 'notification.condition.value',
                'required' => false,
                'attr'     => [
                    'placeholder' => 'notification.condition.value_placeholder',
                    'class'       => 'form-control form-control-sm',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'translation_domain' => 'notification',
        ]);
    }
}
