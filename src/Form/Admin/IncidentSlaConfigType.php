<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\IncidentSlaConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IncidentSlaConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('responseHours', IntegerType::class, [
                'label' => 'incident_sla.field.response_hours',
                'help' => 'incident_sla.help.response_hours',
                'attr' => ['min' => 1, 'max' => 10000, 'step' => 1],
            ])
            ->add('escalationHours', IntegerType::class, [
                'label' => 'incident_sla.field.escalation_hours',
                'help' => 'incident_sla.help.escalation_hours',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 10000, 'step' => 1],
            ])
            ->add('resolutionHours', IntegerType::class, [
                'label' => 'incident_sla.field.resolution_hours',
                'help' => 'incident_sla.help.resolution_hours',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 10000, 'step' => 1],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'incident_sla.field.note',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 2000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IncidentSlaConfig::class,
            'translation_domain' => 'incident_sla',
        ]);
    }
}
