<?php

declare(strict_types=1);

namespace App\Form\Import;

use App\Validator\Constraint\MustEqualCommit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Step-3 Bulk-Import form: confirm the preview before commit.
 *
 * Requires the user to type "COMMIT" (case-insensitive) to guard against
 * accidental submission of destructive import operations.
 *
 * Fields:
 *   - skipOnError     whether rows with errors should be skipped (vs. abort)
 *   - confirmText     must equal "COMMIT" (MustEqualCommit constraint)
 *   - batchId         hidden — links this form to the pending BulkImportBatch
 */
class PreviewConfirmType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('skipOnError', CheckboxType::class, [
                'label'    => 'data_import.preview.skip_on_error_label',
                'required' => false,
                'data'     => false,
            ])
            ->add('confirmText', TextType::class, [
                'label'       => 'data_import.preview.confirm_text_label',
                'required'    => true,
                'constraints' => [
                    new NotBlank(message: 'data_import.error.must_equal_commit'),
                    new MustEqualCommit(),
                ],
                'attr' => [
                    'placeholder'  => 'COMMIT',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('batchId', IntegerType::class, [
                'label'  => false,
                'mapped' => false,
                'attr'   => ['hidden' => true],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'translation_domain' => 'data_import',
        ]);
    }
}
