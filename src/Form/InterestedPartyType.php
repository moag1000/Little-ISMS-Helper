<?php

namespace App\Form;

use App\Entity\InterestedParty;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InterestedPartyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Party Name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('partyType', ChoiceType::class, [
                'label' => 'Party Type',
                'choices' => [
                    'Customer' => 'customer',
                    'Shareholder' => 'shareholder',
                    'Employee' => 'employee',
                    'Regulator' => 'regulator',
                    'Supplier' => 'supplier',
                    'Partner' => 'partner',
                    'Public' => 'public',
                    'Media' => 'media',
                    'Government' => 'government',
                    'Competitor' => 'competitor',
                    'Other' => 'other',
                ],
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'Contact Person',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'Phone',
                'required' => false,
                'attr' => ['maxlength' => 50],
            ])
            ->add('importance', ChoiceType::class, [
                'label' => 'Importance',
                'choices' => [
                    'Critical' => 'critical',
                    'High' => 'high',
                    'Medium' => 'medium',
                    'Low' => 'low',
                ],
                'required' => true,
            ])
            ->add('requirements', TextareaType::class, [
                'label' => 'Requirements & Expectations',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('howAddressed', TextareaType::class, [
                'label' => 'How Requirements are Addressed',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('communicationFrequency', ChoiceType::class, [
                'label' => 'Communication Frequency',
                'choices' => [
                    'Daily' => 'daily',
                    'Weekly' => 'weekly',
                    'Monthly' => 'monthly',
                    'Quarterly' => 'quarterly',
                    'Annually' => 'annually',
                    'As Needed' => 'as_needed',
                ],
                'required' => false,
            ])
            ->add('communicationMethod', TextareaType::class, [
                'label' => 'Communication Method',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('lastCommunication', DateType::class, [
                'label' => 'Last Communication',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextCommunication', DateType::class, [
                'label' => 'Next Planned Communication',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'Feedback',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('satisfactionLevel', IntegerType::class, [
                'label' => 'Satisfaction Level (1-5)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('issues', TextareaType::class, [
                'label' => 'Issues/Concerns',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InterestedParty::class,
        ]);
    }
}
