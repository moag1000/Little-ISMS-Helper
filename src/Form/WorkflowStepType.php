<?php

namespace App\Form;

use Exception;
use App\Entity\WorkflowStep;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class WorkflowStepType extends AbstractType
{
    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'workflow_step.field.name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'workflow_step.placeholder.name'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_step.validation.name_required'),
                    new Assert\Length(max: 255, maxMessage: 'workflow_step.validation.name_max_length')
                ]
            ])
            ->add('description', TextareaType::class, [
                'label' => 'workflow_step.field.description',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'workflow_step.placeholder.description'
                ]
            ])
            ->add('stepType', ChoiceType::class, [
                'label' => 'workflow_step.field.step_type',
                'choices' => [
                    'workflow_step.type.approval' => 'approval',
                    'workflow_step.type.notification' => 'notification',
                    'workflow_step.type.auto_action' => 'auto_action',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'workflow_step.validation.step_type_required')
                ],
                'help' => 'workflow_step.help.step_type',
                    'choice_translation_domain' => 'workflows',
            ])
            ->add('approverRole', ChoiceType::class, [
                'label' => 'workflow_step.field.approver_role',
                'required' => false,
                'choices' => [
                    'workflow_step.role.user' => 'ROLE_USER',
                    'workflow_step.role.manager' => 'ROLE_MANAGER',
                    'workflow_step.role.auditor' => 'ROLE_AUDITOR',
                    'workflow_step.role.admin' => 'ROLE_ADMIN',
                    'workflow_step.role.iso_officer' => 'ROLE_ISO_OFFICER',
                    'workflow_step.role.risk_manager' => 'ROLE_RISK_MANAGER',
                ],
                'attr' => [
                    'class' => 'form-select'
                ],
                'placeholder' => 'workflow_step.placeholder.select_role',
                'help' => 'workflow_step.help.approver_role',
                    'choice_translation_domain' => 'workflows',
            ])
            ->add('approverUsers', ChoiceType::class, [
                'label' => 'workflow_step.field.approver_users',
                'required' => false,
                'multiple' => true,
                'choices' => $this->getUserChoices(),
                'attr' => [
                    'class' => 'form-select',
                    'size' => 5
                ],
                'help' => 'workflow_step.help.approver_users',
                    'choice_translation_domain' => 'workflows',
            ])
            ->add('isRequired', CheckboxType::class, [
                'label' => 'workflow_step.field.is_required',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input'
                ],
                'label_attr' => [
                    'class' => 'form-check-label'
                ],
                'help' => 'workflow_step.help.is_required'
            ])
            ->add('daysToComplete', IntegerType::class, [
                'label' => 'workflow_step.field.days_to_complete',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 365,
                    'placeholder' => 'workflow_step.placeholder.days_to_complete'
                ],
                'constraints' => [
                    new Assert\GreaterThan(value: 0, message: 'workflow_step.validation.days_positive'),
                    new Assert\LessThanOrEqual(value: 365, message: 'workflow_step.validation.days_max')
                ],
                'help' => 'workflow_step.help.days_to_complete'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WorkflowStep::class,
            'translation_domain' => 'workflows',
        ]);
    }

    private function getUserChoices(): array
    {
        try {
            $users = $this->userRepository->findBy(['isActive' => true], ['lastName' => 'ASC', 'firstName' => 'ASC']);
        } catch (Exception) {
            // Fallback if isActive field doesn't exist
            $users = $this->userRepository->findAll();
        }

        $choices = [];

        foreach ($users as $user) {
            $firstName = $user->getFirstName() ?? 'Unknown';
            $lastName = $user->getLastName() ?? 'User';
            $email = $user->getEmail() ?? 'no-email';
            $label = sprintf('%s %s (%s)', $firstName, $lastName, $email);
            $choices[$label] = $user->getId();
        }

        return $choices;
    }
}
