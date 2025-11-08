<?php

namespace App\Form;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkflowInstanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('workflow', EntityType::class, [
                'label' => 'Workflow',
                'class' => Workflow::class,
                'choice_label' => 'name',
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie einen Workflow aus.'])
                ],
                'help' => 'Wählen Sie den Workflow, der ausgeführt werden soll'
            ])
            ->add('entityType', ChoiceType::class, [
                'label' => 'Entitätstyp',
                'choices' => [
                    'Risiko' => 'App\Entity\Risk',
                    'Control' => 'App\Entity\Control',
                    'Incident' => 'App\Entity\Incident',
                    'Änderungsantrag' => 'App\Entity\ChangeRequest',
                    'Audit' => 'App\Entity\InternalAudit',
                    'Management Review' => 'App\Entity\ManagementReview',
                    'Asset' => 'App\Entity\Asset',
                    'Dokument' => 'App\Entity\Document',
                    'Training' => 'App\Entity\Training',
                    'Lieferant' => 'App\Entity\Supplier',
                    'ISMS-Ziel' => 'App\Entity\ISMSObjective',
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
                'help' => 'Typ der Entität, für die diese Workflow-Instanz gilt'
            ])
            ->add('entityId', IntegerType::class, [
                'label' => 'Entitäts-ID',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'z.B. 123',
                    'min' => 1
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte geben Sie eine Entitäts-ID ein.']),
                    new Assert\Positive(['message' => 'Die ID muss eine positive Zahl sein.'])
                ],
                'help' => 'ID der Entität, für die dieser Workflow ausgeführt wird'
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => [
                    'Ausstehend' => 'pending',
                    'In Bearbeitung' => 'in_progress',
                    'Genehmigt' => 'approved',
                    'Abgelehnt' => 'rejected',
                    'Abgebrochen' => 'cancelled',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Bitte wählen Sie einen Status aus.'])
                ]
            ])
            ->add('initiatedBy', EntityType::class, [
                'label' => 'Initiiert von',
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return $user->getFirstName() . ' ' . $user->getLastName() . ' (' . $user->getEmail() . ')';
                },
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Benutzer, der diesen Workflow gestartet hat'
            ])
            ->add('currentStep', EntityType::class, [
                'label' => 'Aktueller Schritt',
                'class' => WorkflowStep::class,
                'choice_label' => 'name',
                'required' => false,
                'attr' => [
                    'class' => 'form-select'
                ],
                'help' => 'Der aktuelle Schritt im Workflow'
            ])
            ->add('comments', TextareaType::class, [
                'label' => 'Kommentare',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Kommentare und Notizen zu dieser Workflow-Instanz'
                ]
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Fälligkeitsdatum',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
                'help' => 'Datum, bis zu dem dieser Workflow abgeschlossen sein sollte'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkflowInstance::class,
        ]);
    }
}
