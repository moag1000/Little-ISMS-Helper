<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\RiskApprovalConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RiskApprovalConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('thresholdAutomatic', IntegerType::class, [
                'label' => 'risk_approval_config.field.automatic',
                'help' => 'risk_approval_config.help.automatic',
                'attr' => ['min' => 1, 'max' => 25, 'step' => 1],
            ])
            ->add('thresholdManager', IntegerType::class, [
                'label' => 'risk_approval_config.field.manager',
                'help' => 'risk_approval_config.help.manager',
                'attr' => ['min' => 1, 'max' => 25, 'step' => 1],
            ])
            ->add('thresholdExecutive', IntegerType::class, [
                'label' => 'risk_approval_config.field.executive',
                'help' => 'risk_approval_config.help.executive',
                'attr' => ['min' => 1, 'max' => 25, 'step' => 1],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'risk_approval_config.field.note',
                'help' => 'risk_approval_config.help.note',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RiskApprovalConfig::class,
            'translation_domain' => 'risk_approval_config',
        ]);
    }
}
