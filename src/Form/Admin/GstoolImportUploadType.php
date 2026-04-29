<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Upload form for the GSTOOL XML import (Phase 1 + Phase 2).
 *
 * The XML file must conform to the gstool_xml_v1 schema (see
 * docs/features/GSTOOL_IMPORT.md).
 */
final class GstoolImportUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $maxSizeMb = (int) ($options['max_size_mb'] ?? 10);
        $builder
            ->add('file', FileType::class, [
                'label' => 'gstool_import.upload.file_label',
                'translation_domain' => 'gstool_import',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'accept' => '.xml,application/xml,text/xml',
                    'id' => 'gstool_import_file',
                    'aria-describedby' => 'gstool_import_file_help',
                ],
                'constraints' => [
                    new NotBlank(),
                    new FileConstraint(
                        maxSize: $maxSizeMb . 'M',
                        mimeTypes: [
                            'application/xml',
                            'text/xml',
                            'text/plain',
                        ],
                        mimeTypesMessage: 'gstool_import.upload.file_type_error',
                    ),
                ],
            ])
            ->add('dryRun', CheckboxType::class, [
                'label' => 'gstool_import.upload.dry_run_label',
                'translation_domain' => 'gstool_import',
                'mapped' => false,
                'required' => false,
                'data' => true,
                'help' => 'gstool_import.upload.dry_run_help',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'gstool_import.upload.submit',
                'translation_domain' => 'gstool_import',
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
            'csrf_token_id' => 'gstool_import_upload',
            'translation_domain' => 'gstool_import',
            'max_size_mb' => 10,
        ]);
        $resolver->setAllowedTypes('max_size_mb', 'int');
    }
}
