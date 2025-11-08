<?php

namespace App\Form;

use App\Entity\ComplianceFramework;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ComplianceFrameworkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Framework-Code',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. ISO27001, DORA, TISAX'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Framework-Code ein.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Code darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ],
                'help' => 'Eindeutiger Identifier für das Framework'
            ])
            ->add('name', TextType::class, [
                'label' => 'Framework-Name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. ISO/IEC 27001:2022'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Framework-Namen ein.']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'Der Name darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Beschreibung',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Beschreibung des Compliance-Frameworks und seiner Anforderungen'
                ]
            ])
            ->add('version', TextType::class, [
                'label' => 'Version',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. 2022, 1.0'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine Version ein.']),
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Die Version darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ]
            ])
            ->add('applicableIndustry', ChoiceType::class, [
                'label' => 'Anwendbare Branche',
                'choices' => [
                    'Alle Branchen' => 'all',
                    'Finanzdienstleistungen' => 'financial',
                    'Automobilindustrie' => 'automotive',
                    'Gesundheitswesen' => 'healthcare',
                    'Telekommunikation' => 'telecommunications',
                    'Energie & Versorgung' => 'energy',
                    'Öffentlicher Sektor' => 'public_sector',
                    'Einzelhandel' => 'retail',
                    'Fertigung' => 'manufacturing',
                    'IT & Software' => 'it_software',
                    'Sonstige' => 'other',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie eine Branche aus.'])
                ]
            ])
            ->add('regulatoryBody', TextType::class, [
                'label' => 'Regulierungsbehörde',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. ISO, EU Commission, VDA'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie die Regulierungsbehörde ein.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Die Regulierungsbehörde darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ],
                'help' => 'Organisation, die das Framework herausgibt'
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'Verpflichtend',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'help' => 'Ist die Einhaltung dieses Frameworks gesetzlich vorgeschrieben?',
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ])
            ->add('scopeDescription', TextareaType::class, [
                'label' => 'Geltungsbereich',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Beschreibung des Anwendungsbereichs und der Grenzen'
                ],
                'help' => 'Welche Teile der Organisation fallen unter dieses Framework?'
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'help' => 'Nur aktive Frameworks werden im System verwendet',
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ComplianceFramework::class,
        ]);
    }
}
