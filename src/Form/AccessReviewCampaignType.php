<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccessReviewCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form for creating an Access Review Campaign.
 *
 * Intentionally minimal (MVP) — name, scope, due date.
 */
final class AccessReviewCampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'access_review.field.name',
                'constraints' => [new NotBlank()],
                'attr'        => [
                    'placeholder' => 'access_review.field.name_placeholder',
                ],
            ])
            ->add('scope', ChoiceType::class, [
                'label'   => 'access_review.field.scope',
                'choices' => [
                    'access_review.scope.all_users'  => AccessReviewCampaign::SCOPE_ALL_USERS,
                    'access_review.scope.privileged' => AccessReviewCampaign::SCOPE_PRIVILEGED,
                ],
                'expanded' => false,
                'multiple' => false,
            ])
            ->add('dueDate', DateType::class, [
                'label'   => 'access_review.field.due_date',
                'widget'  => 'single_text',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AccessReviewCampaign::class,
            'translation_domain' => 'access_review',
        ]);
    }
}
