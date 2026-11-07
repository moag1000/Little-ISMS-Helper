<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Document;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** "Erledigt" for a recurring duty: when, with which evidence, a note. */
final class RecurringDutyDoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('doneAt', DateType::class, [
                'label' => 'recurring_duty.field.done_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => new DateTimeImmutable('today'),
                'constraints' => [new Assert\NotNull(), new Assert\LessThanOrEqual('today')],
            ])
            ->add('evidence', EntityType::class, [
                'label' => 'recurring_duty.field.evidence',
                'class' => Document::class,
                'choice_label' => 'originalFilename',
                'required' => false,
                'placeholder' => 'recurring_duty.field.evidence_placeholder',
                'help' => 'recurring_duty.help.evidence',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'recurring_duty.field.note',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 2000],
                'constraints' => [new Assert\Length(max: 2000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'recurring_duty']);
    }
}
