<?php

declare(strict_types=1);

namespace App\Form\Notification;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ConditionBuilderType — collection of field+op+value rows.
 *
 * Each row is a simple associative array with keys:
 *   field:    string — entity field name (e.g. "severity", "status")
 *   operator: string — comparison operator (e.g. "=", ">=", "!=")
 *   value:    string — comparison value
 *
 * The collection is managed via Stimulus notification_condition_builder_controller.js.
 */
final class ConditionBuilderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('conditions', CollectionType::class, [
            'label'         => false,
            'entry_type'    => ConditionRowType::class,
            'allow_add'     => true,
            'allow_delete'  => true,
            'by_reference'  => false,
            'prototype'     => true,
            'prototype_name' => '__condition_index__',
            'attr'          => [
                'data-controller'                             => 'notification-condition-builder',
                'data-notification-condition-builder-prototype-value' => '__condition_index__',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'notification',
            'label'              => false,
        ]);
    }
}
