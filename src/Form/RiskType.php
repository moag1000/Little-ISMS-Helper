<?php

namespace App\Form;

use App\Entity\Asset;
use App\Entity\Risk;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
            ->add('description', TextareaType::class, [
                'label' => 'risk.field.description',
                'required' => false,
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
            ->add('asset', EntityType::class, [
                'label' => 'risk.field.asset',
                'class' => Asset::class,
                'choice_label' => 'name',
                'placeholder' => 'risk.placeholder.asset',
                'required' => true,
                'help' => 'risk.help.asset',
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
            ->add('riskOwner', TextType::class, [
                'label' => 'risk.field.risk_owner',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'risk.placeholder.risk_owner',
                ],
                'help' => 'risk.help.risk_owner',
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
