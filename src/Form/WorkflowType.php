<?php

namespace App\Form;

use App\Entity\Workflow;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkflowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Workflow-Name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. Risiko-Genehmigungsprozess'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie einen Workflow-Namen ein.']),
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
                    'placeholder' => 'Beschreibung des Workflow-Zwecks und -Ablaufs'
                ]
            ])
            ->add('entityType', ChoiceType::class, [
                'label' => 'Entitätstyp',
                'choices' => [
                    'Risiko' => 'Risk',
                    'Control' => 'Control',
                    'Incident' => 'Incident',
                    'Änderungsantrag' => 'ChangeRequest',
                    'Audit' => 'InternalAudit',
                    'Management Review' => 'ManagementReview',
                    'Asset' => 'Asset',
                    'Dokument' => 'Document',
                    'Training' => 'Training',
                    'Lieferant' => 'Supplier',
                    'ISMS-Ziel' => 'ISMSObjective',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie einen Entitätstyp aus.']),
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'Der Entitätstyp darf maximal {{ limit }} Zeichen lang sein.'
                    ])
                ],
                'help' => 'Wählen Sie den Typ der Entität, für die dieser Workflow gilt'
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Aktiv',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'help' => 'Nur aktive Workflows werden für neue Instanzen verwendet',
                'label_attr' => [
                    'class' => 'form-check-label'
                ]
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Workflow::class,
        ]);
    }
}
