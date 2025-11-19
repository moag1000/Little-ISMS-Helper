<?php

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RiskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'risk.field.title',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'risk.placeholder.title',
                ],
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'risk.field.category',
                'choices' => [
                    'risk.category.financial' => 'financial',
                    'risk.category.operational' => 'operational',
                    'risk.category.compliance' => 'compliance',
                    'risk.category.strategic' => 'strategic',
                    'risk.category.reputational' => 'reputational',
                    'risk.category.security' => 'security',
                ],
                'placeholder' => 'risk.placeholder.category',
                'required' => true,
                'help' => 'risk.help.category',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            // DSGVO Risk Assessment Extension (Priority 2.2)
            ->add('involvesPersonalData', CheckboxType::class, [
                'label' => 'risk.field.involves_personal_data',
                'required' => false,
                'help' => 'risk.help.involves_personal_data',
            ])
            ->add('involvesSpecialCategoryData', CheckboxType::class, [
                'label' => 'risk.field.involves_special_category_data',
                'required' => false,
                'help' => 'risk.help.involves_special_category_data',
            ])
            ->add('legalBasis', ChoiceType::class, [
                'label' => 'risk.field.legal_basis',
                'choices' => [
                    'risk.legal_basis.consent' => 'consent',
                    'risk.legal_basis.contract' => 'contract',
                    'risk.legal_basis.legal_obligation' => 'legal_obligation',
                    'risk.legal_basis.vital_interests' => 'vital_interests',
                    'risk.legal_basis.public_task' => 'public_task',
                    'risk.legal_basis.legitimate_interests' => 'legitimate_interests',
                ],
                'placeholder' => 'risk.placeholder.legal_basis',
                'required' => false,
                'help' => 'risk.help.legal_basis',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('processingScale', ChoiceType::class, [
                'label' => 'risk.field.processing_scale',
                'choices' => [
                    'risk.processing_scale.small' => 'small',
                    'risk.processing_scale.medium' => 'medium',
                    'risk.processing_scale.large_scale' => 'large_scale',
                ],
                'placeholder' => 'risk.placeholder.processing_scale',
                'required' => false,
                'help' => 'risk.help.processing_scale',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('requiresDPIA', CheckboxType::class, [
                'label' => 'risk.field.requires_dpia',
                'required' => false,
                'help' => 'risk.help.requires_dpia',
            ])
            ->add('dataSubjectImpact', TextareaType::class, [
                'label' => 'risk.field.data_subject_impact',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.data_subject_impact',
                ],
                'help' => 'risk.help.data_subject_impact',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'risk.field.description',
                'required' => true,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.description',
                ],
                'help' => 'risk.help.description',
            ])
            ->add('threat', TextareaType::class, [
                'label' => 'risk.field.threat',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.threat',
                ],
                'help' => 'risk.help.threat',
            ])
            ->add('vulnerability', TextareaType::class, [
                'label' => 'risk.field.vulnerability',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.vulnerability',
                ],
                'help' => 'risk.help.vulnerability',
            ])
            // Risk Subject - At least one must be selected (Asset, Person, Location, or Supplier)
            ->add('asset', EntityType::class, [
                'label' => 'risk.field.asset',
                'class' => Asset::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.asset',
                'required' => false,
                'help' => 'risk.help.asset',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('person', EntityType::class, [
                'label' => 'risk.field.person',
                'class' => Person::class,
                'choice_label' => function(Person $person) {
                    return $person->getFullName();
                },
                'placeholder' => 'risk.placeholder.person',
                'required' => false,
                'help' => 'risk.help.person',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('location', EntityType::class, [
                'label' => 'risk.field.location',
                'class' => Location::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.location',
                'required' => false,
                'help' => 'risk.help.location',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('supplier', EntityType::class, [
                'label' => 'risk.field.supplier',
                'class' => Supplier::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.supplier',
                'required' => false,
                'help' => 'risk.help.supplier',
                'attr' => [
                    'class' => 'risk-subject-field',
                ],
            ])
            ->add('probability', IntegerType::class, [
                'label' => 'risk.field.probability',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.probability',
            ])
            ->add('impact', IntegerType::class, [
                'label' => 'risk.field.impact',
                'required' => true,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
            ])
            ->add('residualProbability', IntegerType::class, [
                'label' => 'risk.field.residual_probability',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.probability',
            ])
            ->add('residualImpact', IntegerType::class, [
                'label' => 'risk.field.residual_impact',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
                'help' => 'risk.help.impact',
            ])
            ->add('riskOwner', EntityType::class, [
                'label' => 'risk.field.risk_owner',
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getFullName() . ' (' . $user->getEmail() . ')';
                },
                'placeholder' => 'risk.placeholder.risk_owner',
                'required' => true,
                'help' => 'risk.help.risk_owner',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('treatmentStrategy', ChoiceType::class, [
                'label' => 'risk.field.treatment_strategy',
                'choices' => [
                    'risk.treatment.mitigate' => 'mitigate',
                    'risk.treatment.transfer' => 'transfer',
                    'risk.treatment.accept' => 'accept',
                    'risk.treatment.avoid' => 'avoid',
                ],
                'required' => true,
                'help' => 'risk.help.treatment',
            ])
            ->add('treatmentDescription', TextareaType::class, [
                'label' => 'risk.field.treatment_description',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'risk.placeholder.treatment_description',
                ],
                'help' => 'risk.help.treatment_description',
            ])
            ->add('acceptanceApprovedBy', TextType::class, [
                'label' => 'risk.field.acceptance_approved_by',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'risk.placeholder.acceptance_approved_by',
                ],
                'help' => 'risk.help.acceptance_approved_by',
            ])
            ->add('acceptanceApprovedAt', DateType::class, [
                'label' => 'risk.field.acceptance_approved_at',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.acceptance_approved_at',
            ])
            ->add('acceptanceJustification', TextareaType::class, [
                'label' => 'risk.field.acceptance_justification',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'risk.placeholder.acceptance_justification',
                ],
                'help' => 'risk.help.acceptance_justification',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'risk.field.status',
                'choices' => [
                    'risk.status.identified' => 'identified',
                    'risk.status.assessed' => 'assessed',
                    'risk.status.treated' => 'treated',
                    'risk.status.monitored' => 'monitored',
                    'risk.status.closed' => 'closed',
                    'risk.status.accepted' => 'accepted',
                ],
                'required' => true,
            ])
            ->add('reviewDate', DateType::class, [
                'label' => 'risk.field.review_date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'risk.help.review_date',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Risk::class,
        ]);
    }
}
