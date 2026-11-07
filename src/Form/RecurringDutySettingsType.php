<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RecurringDuty;
use App\Form\Type\OwnerUserType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The organisation's settings for one recurring duty: interval, owner, next due date. */
final class RecurringDutySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('intervalMonths', IntegerType::class, [
                'label' => 'recurring_duty.field.interval_months',
                'attr' => ['min' => 1, 'max' => 60],
                'help' => 'recurring_duty.help.interval_months',
            ])
            ->add('owner', OwnerUserType::class, [
                'label' => 'recurring_duty.field.owner',
                'required' => false,
                'placeholder' => 'recurring_duty.field.owner_placeholder',
                'help' => 'recurring_duty.help.owner',
            ])
            ->add('nextDueAt', DateType::class, [
                'label' => 'recurring_duty.field.next_due_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecurringDuty::class,
            'translation_domain' => 'recurring_duty',
        ]);
    }
}
