<?php

namespace App\Form;

use App\Entity\ISMSContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ISMSContextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organizationName', TextType::class, [
                'label' => 'context.field.organization_name',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'context.placeholder.organization_name',
                ],
                'help' => 'context.help.organization_name',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'context.validation.organization_name_required']),
                    new Assert\Length([
                        'max' => 255,
                        'maxMessage' => 'context.validation.name_max_length'
                    ])
                ]
            ])
            ->add('ismsScope', TextareaType::class, [
                'label' => 'context.field.isms_scope',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'context.placeholder.isms_scope'
                ],
                'help' => 'context.help.isms_scope'
            ])
            ->add('scopeExclusions', TextareaType::class, [
                'label' => 'context.field.scope_exclusions',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'context.placeholder.scope_exclusions'
                ],
                'help' => 'context.help.scope_exclusions'
            ])
            ->add('externalIssues', TextareaType::class, [
                'label' => 'context.field.external_issues',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'context.placeholder.external_issues'
                ],
                'help' => 'context.help.external_issues'
            ])
            ->add('internalIssues', TextareaType::class, [
                'label' => 'context.field.internal_issues',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'context.placeholder.internal_issues'
                ],
                'help' => 'context.help.internal_issues'
            ])
            ->add('interestedParties', TextareaType::class, [
                'label' => 'context.field.interested_parties',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'context.placeholder.interested_parties'
                ],
                'help' => 'context.help.interested_parties'
            ])
            ->add('interestedPartiesRequirements', TextareaType::class, [
                'label' => 'context.field.interested_parties_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'context.placeholder.interested_parties_requirements'
                ],
                'help' => 'context.help.interested_parties_requirements'
            ])
            ->add('legalRequirements', TextareaType::class, [
                'label' => 'context.field.legal_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'context.placeholder.legal_requirements'
                ],
                'help' => 'context.help.legal_requirements'
            ])
            ->add('regulatoryRequirements', TextareaType::class, [
                'label' => 'context.field.regulatory_requirements',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'context.placeholder.regulatory_requirements'
                ],
                'help' => 'context.help.regulatory_requirements'
            ])
            ->add('contractualObligations', TextareaType::class, [
                'label' => 'context.field.contractual_obligations',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'context.placeholder.contractual_obligations'
                ],
                'help' => 'context.help.contractual_obligations'
            ])
            ->add('ismsPolicy', TextareaType::class, [
                'label' => 'context.field.isms_policy',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 6,
                    'placeholder' => 'context.placeholder.isms_policy'
                ],
                'help' => 'context.help.isms_policy'
            ])
            ->add('rolesAndResponsibilities', TextareaType::class, [
                'label' => 'context.field.roles_and_responsibilities',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'context.placeholder.roles_and_responsibilities'
                ],
                'help' => 'context.help.roles_and_responsibilities'
            ])
            ->add('lastReviewDate', DateType::class, [
                'label' => 'context.field.last_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'context.help.last_review_date'
            ])
            ->add('nextReviewDate', DateType::class, [
                'label' => 'context.field.next_review_date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'class' => 'form-control'
                ],
                'help' => 'context.help.next_review_date'
            ])
        ;

        // Dynamically set organizationName to readonly if tenant is assigned
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $formEvent): void {
            $context = $formEvent->getData();
            $form = $formEvent->getForm();

            if ($context && $context->getTenant() !== null) {
                // Re-add organizationName field with readonly attribute
                $form->add('organizationName', TextType::class, [
                    'label' => 'context.field.organization_name',
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => 'context.placeholder.organization_name',
                        'readonly' => true,
                    ],
                    'help' => 'context.help.organization_name',
                    'constraints' => [
                        new Assert\NotBlank(['message' => 'context.validation.organization_name_required']),
                        new Assert\Length([
                            'max' => 255,
                            'maxMessage' => 'context.validation.name_max_length'
                        ])
                    ]
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ISMSContext::class,
            'translation_domain' => 'context',
        ]);
    }
}
