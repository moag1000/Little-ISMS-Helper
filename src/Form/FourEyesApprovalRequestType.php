<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form for editing the requested approver Tri-State fields of a FourEyesApprovalRequest.
 *
 * Note: actionType, payload, requestedBy, and tenant are set programmatically
 * by the FourEyesApprovalService and are intentionally excluded from this form.
 * Only the approver slot (User + Person + Deputies) is user-editable.
 */
final class FourEyesApprovalRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Tri-State approver fields
            ->add('requestedApprover', EntityType::class, [
                'label' => 'four_eyes.field.requested_approver_user',
                'class' => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'required' => false,
                'placeholder' => 'four_eyes.placeholder.requested_approver_user',
                'help' => 'four_eyes.help.requested_approver_user',
            ])
            ->add('requestedApproverPerson', EntityType::class, [
                'label' => 'four_eyes.field.requested_approver_person',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'placeholder' => 'four_eyes.placeholder.requested_approver_person',
                'help' => 'four_eyes.help.requested_approver_person',
            ])
            ->add('requestedApproverDeputyPersons', EntityType::class, [
                'label' => 'four_eyes.field.requested_approver_deputy_persons',
                'class' => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'attr' => [
                    'data-controller' => 'tom-select',
                ],
                'help' => 'four_eyes.help.requested_approver_deputy_persons',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FourEyesApprovalRequest::class,
            'translation_domain' => 'four_eyes',
            'constraints' => [
                new Callback([$this, 'validateApproverSlot']),
            ],
        ]);
    }

    public function validateApproverSlot(?FourEyesApprovalRequest $entity, ExecutionContextInterface $context): void
    {
        if ($entity === null) {
            return;
        }
        if ($entity->getRequestedApprover() === null && $entity->getRequestedApproverPerson() === null) {
            $context->buildViolation('four_eyes.error.approver_required_user_or_person')
                ->atPath('requestedApprover')
                ->addViolation();
        }
    }
}
