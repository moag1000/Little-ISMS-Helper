<?php

namespace App\Form;

use App\Entity\Document;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class DocumentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('originalFilename', TextType::class, [
                'label' => 'document.field.name',
                'required' => false,
                'mapped' => false, // Will be set from uploaded file
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'document.placeholder.name',
                ],
                'help' => 'document.help.name_optional',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'document.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'document.placeholder.description',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'document.field.document_type',
                'choices' => [
                    'document.type.asset' => 'asset',
                    'document.type.risk' => 'risk',
                    'document.type.incident' => 'incident',
                    'document.type.control' => 'control',
                    'document.type.audit' => 'audit',
                    'document.type.compliance' => 'compliance',
                    'document.type.training' => 'training',
                    'document.type.general' => 'general',
                ],
                'required' => true,
                    'choice_translation_domain' => 'document',
            ])
            ->add('file', FileType::class, [
                'label' => 'document.field.file',
                'mapped' => false, // File upload is handled separately
                'required' => $options['is_new'] ?? true,
                'constraints' => [
                    new File([
                        'maxSize' => '10M',
                        'mimeTypes' => [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'text/plain',
                        ],
                        'mimeTypesMessage' => 'document.validation.mime_types',
                    ]),
                ],
                'attr' => [
                    'accept' => '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt',
                ],
                'help' => 'document.help.file',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Document::class,
            'translation_domain' => 'documents',
            'is_new' => true,
        ]);
    }
}
