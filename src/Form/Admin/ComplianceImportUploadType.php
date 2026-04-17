<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Upload form for the compliance mapping import wizard (WS-2, Step 1).
 *
 * Accepts a CSV file and the desired format. In v1 only `csv_generic_v1`
 * is supported; further formats (BSI-Profile, Verinice, NIST-CSF) will be
 * added through the same wizard entry point.
 */
final class ComplianceImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxSizeMb = (int) ($options['max_size_mb'] ?? 5);
        $builder
            ->add('format', ChoiceType::class, [
                'label' => 'compliance_import.upload.format_label',
                'translation_domain' => 'compliance_import',
                'choices' => [
                    'compliance_import.upload.format.csv_generic_v1' => 'csv_generic_v1',
                ],
                'choice_translation_domain' => 'compliance_import',
                'help' => 'compliance_import.upload.format_help',
                'attr' => [
                    'id' => 'compliance_import_format',
                    'aria-describedby' => 'compliance_import_format_help',
                ],
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('file', FileType::class, [
                'label' => 'compliance_import.upload.file_label',
                'translation_domain' => 'compliance_import',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'accept' => '.csv,text/csv',
                    'id' => 'compliance_import_file',
                    'aria-describedby' => 'compliance_import_file_help',
                ],
                'constraints' => [
                    new NotBlank(),
                    new FileConstraint(
                        maxSize: $maxSizeMb . 'M',
                        mimeTypes: [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ],
                        mimeTypesMessage: 'compliance_import.upload.file_type_error',
                    ),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'compliance_import.upload.submit',
                'translation_domain' => 'compliance_import',
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'compliance_import_upload',
            'translation_domain' => 'compliance_import',
            'max_size_mb' => 5,
        ]);
        $resolver->setAllowedTypes('max_size_mb', 'int');
    }
}
