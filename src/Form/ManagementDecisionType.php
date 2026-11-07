<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ManagementDecision;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A proposal put before top management. Prefilled from the records by
 * DecisionProposalBuilder; the person preparing it checks and adds.
 */
final class ManagementDecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'management_decision.field.title',
            ])
            ->add('proposal', TextareaType::class, [
                'label' => 'management_decision.field.proposal',
                'help' => 'management_decision.help.proposal',
                'required' => false,
                'attr' => ['rows' => 12],
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'management_decision.field.due_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ManagementDecision::class,
            'translation_domain' => 'management_decision',
        ]);
    }
}
