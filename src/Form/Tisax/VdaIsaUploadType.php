<?php

declare(strict_types=1);

namespace App\Form\Tisax;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Step 1 — VDA-ISA workbook upload form.
 *
 * Accepts XLSX files up to 10 MB.
 * The actual security validation (magic bytes, MIME sniff) is handled
 * by FileUploadSecurityService in the controller after form submission.
 */
final class VdaIsaUploadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('workbook', FileType::class, [
            'label'       => 'tisax.import.upload.field_label',
            'required'    => true,
            'constraints' => [
                new NotNull(['message' => 'tisax.import.upload.required']),
                new File([
                    'maxSize'          => '10M',
                    'mimeTypes'        => [
                        // Standard XLSX (Office 2007+ Open XML)
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        // Legacy XLS (Office 97-2003 binary)
                        'application/vnd.ms-excel',
                        // ENX workbooks sometimes report as generic ZIP (xlsx IS a zip container)
                        // or octet-stream depending on browser + OS file-association
                        'application/zip',
                        'application/x-zip-compressed',
                        'application/octet-stream',
                    ],
                    'mimeTypesMessage' => 'tisax.import.upload.xlsx_only',
                    'maxSizeMessage'   => 'tisax.import.upload.too_large',
                ]),
            ],
            'attr' => [
                'accept'       => '.xlsx',
                'data-testid'  => 'tisax-workbook-upload',
            ],
            'help'   => 'tisax.import.upload.help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'tisax_isa',
        ]);
    }
}
