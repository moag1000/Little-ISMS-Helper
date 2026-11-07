<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ActionItem;
use App\Entity\User;
use App\Form\Type\OwnerUserType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One action decided in a management review (ISO 27001 9.3.3): what, who,
 * by when. Stored as an ActionItem that refers to the review, so it shows up
 * in My Day and as input a of the next review (9.3.2 a).
 */
final class ManagementReviewTaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'management_review.task.field.title',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
            ])
            ->add('responsibleUser', OwnerUserType::class, [
                'label' => 'management_review.task.field.responsible',
                'choice_label' => static fn (User $user): string => (string) $user->getFullName(),
                'placeholder' => 'management_review.task.placeholder.responsible',
                'required' => false,
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'management_review.task.field.due',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActionItem::class,
            'translation_domain' => 'management_review',
        ]);
    }
}
