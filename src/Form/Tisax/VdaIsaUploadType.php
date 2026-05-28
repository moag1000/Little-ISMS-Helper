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
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
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
