<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AnswerLibraryEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * F44 — Form type for AnswerLibraryEntry.
 *
 * SectionPolicy (S4 Foundation P-2): fields are grouped into two sections
 * so the auto-form renderer places them in named, semantically correct blocks.
 */
final class AnswerLibraryEntryType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'overview' => ['question', 'category', 'tags'],
            'details'  => ['answer'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('question', TextareaType::class, [
                'label'       => 'answer_library.field.question',
                'attr'        => [
                    'rows'        => 4,
                    'placeholder' => 'answer_library.placeholder.question',
                ],
                'constraints' => [
                    new NotBlank(message: 'answer_library.validation.question_required'),
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label'   => 'answer_library.field.category',
                'choices' => [
                    'answer_library.category.access_control'   => AnswerLibraryEntry::CATEGORY_ACCESS_CONTROL,
                    'answer_library.category.encryption'       => AnswerLibraryEntry::CATEGORY_ENCRYPTION,
                    'answer_library.category.bcm'              => AnswerLibraryEntry::CATEGORY_BCM,
                    'answer_library.category.privacy'          => AnswerLibraryEntry::CATEGORY_PRIVACY,
                    'answer_library.category.incident'         => AnswerLibraryEntry::CATEGORY_INCIDENT,
                    'answer_library.category.physical'         => AnswerLibraryEntry::CATEGORY_PHYSICAL,
                    'answer_library.category.supplier'         => AnswerLibraryEntry::CATEGORY_SUPPLIER,
                    'answer_library.category.risk'             => AnswerLibraryEntry::CATEGORY_RISK,
                    'answer_library.category.general'          => AnswerLibraryEntry::CATEGORY_GENERAL,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('tags', TextType::class, [
                'label'    => 'answer_library.field.tags',
                'required' => false,
                'mapped'   => false,
                'attr'     => [
                    'placeholder' => 'answer_library.placeholder.tags',
                    'class'       => 'form-control',
                    'data-controller' => 'tag-input',
                ],
                'help'     => 'answer_library.help.tags',
            ])
            ->add('answer', TextareaType::class, [
                'label' => 'answer_library.field.answer',
                'attr'  => [
                    'rows'        => 8,
                    'placeholder' => 'answer_library.placeholder.answer',
                ],
                'constraints' => [
                    new NotBlank(message: 'answer_library.validation.answer_required'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AnswerLibraryEntry::class,
            'translation_domain' => 'answer_library',
        ]);
    }
}
