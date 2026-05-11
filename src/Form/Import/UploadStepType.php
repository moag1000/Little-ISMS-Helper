<?php

declare(strict_types=1);

namespace App\Form\Import;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

/**
 * Step-1 Bulk-Import form: file upload + entity-type picker.
 *
 * Accepts XLSX, XLS, CSV and ODS files (max 10 MB).
 * The `entity_types` form option must be injected by the controller
 * from EntityMapperRegistry::getSupportedEntityTypes() to keep the
 * choice list in sync with available mappers.
 *
 * NOTE: data_class is null because the controller maps fields manually
 * onto a BulkImportBatch after file-persistence.
 *
 * Usage from controller:
 *   $form = $this->createForm(UploadStepType::class, null, [
 *       'entity_types' => $this->registry->getSupportedEntityTypes(),
 *   ]);
 */
class UploadStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $entityTypes */
        $entityTypes = $options['entity_types'];

        $entityChoices = array_combine(
            array_map(
                static fn(string $t): string => 'data_import.entity_type.' . strtolower($t),
                $entityTypes,
            ),
            $entityTypes,
        );

        $builder
            ->add('entityType', ChoiceType::class, [
                'label'        => 'data_import.upload.entity_type_label',
                'choices'      => $entityChoices,
                'required'     => true,
                'placeholder'  => 'data_import.upload.entity_type_placeholder',
            ])
            ->add('mode', ChoiceType::class, [
                'label'    => 'data_import.upload.mode_label',
                'required' => true,
                'choices'  => [
                    'data_import.mode.initial'  => 'initial',
                    'data_import.mode.delta'    => 'delta',
                    'data_import.mode.dry_run'  => 'dry_run',
                ],
                'data' => 'initial',
            ])
            ->add('file', FileType::class, [
                'label'    => 'data_import.upload.file_label',
                'required' => true,
                'constraints' => [
                    new File(
                        maxSize: '10M',
                        mimeTypes: [
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'application/csv',
                            'text/plain',
                            'application/vnd.oasis.opendocument.spreadsheet',
                        ],
                        mimeTypesMessage: 'data_import.upload.invalid_mime_type',
                        maxSizeMessage: 'data_import.upload.file_too_large',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'csrf_protection'    => true,
            'translation_domain' => 'data_import',
            'entity_types'       => [],
        ]);

        $resolver->setRequired('entity_types');
        $resolver->setAllowedTypes('entity_types', 'array');
    }
}
