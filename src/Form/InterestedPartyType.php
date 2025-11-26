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
                'label' => 'interested_party.field.name',
                'required' => true,
                'attr' => ['maxlength' => 255],
            ])
            ->add('partyType', ChoiceType::class, [
                'label' => 'interested_party.field.party_type',
                'choices' => [
                    'interested_party.party_type.customer' => 'customer',
                    'interested_party.party_type.shareholder' => 'shareholder',
                    'interested_party.party_type.employee' => 'employee',
                    'interested_party.party_type.regulator' => 'regulator',
                    'interested_party.party_type.supplier' => 'supplier',
                    'interested_party.party_type.partner' => 'partner',
                    'interested_party.party_type.public' => 'public',
                    'interested_party.party_type.media' => 'media',
                    'interested_party.party_type.government' => 'government',
                    'interested_party.party_type.competitor' => 'competitor',
                    'interested_party.party_type.other' => 'other',
                ],
                'required' => true,
                'choice_translation_domain' => 'interested_parties',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'interested_party.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('contactPerson', TextType::class, [
                'label' => 'interested_party.field.contact_person',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('email', EmailType::class, [
                'label' => 'interested_party.field.email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'interested_party.field.phone',
                'required' => false,
                'attr' => ['maxlength' => 50],
            ])
            ->add('importance', ChoiceType::class, [
                'label' => 'interested_party.field.importance',
                'choices' => [
                    'interested_party.importance.critical' => 'critical',
                    'interested_party.importance.high' => 'high',
                    'interested_party.importance.medium' => 'medium',
                    'interested_party.importance.low' => 'low',
                ],
                'required' => true,
                'choice_translation_domain' => 'interested_parties',
            ])
            ->add('requirements', TextareaType::class, [
                'label' => 'interested_party.field.requirements',
                'required' => true,
                'attr' => ['rows' => 4],
            ])
            ->add('howAddressed', TextareaType::class, [
                'label' => 'interested_party.field.how_addressed',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('communicationFrequency', ChoiceType::class, [
                'label' => 'interested_party.field.communication_frequency',
                'choices' => [
                    'interested_party.frequency.daily' => 'daily',
                    'interested_party.frequency.weekly' => 'weekly',
                    'interested_party.frequency.monthly' => 'monthly',
                    'interested_party.frequency.quarterly' => 'quarterly',
                    'interested_party.frequency.annually' => 'annually',
                    'interested_party.frequency.as_needed' => 'as_needed',
                ],
                'required' => false,
                'choice_translation_domain' => 'interested_parties',
            ])
            ->add('communicationMethod', TextareaType::class, [
                'label' => 'interested_party.field.communication_method',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('lastCommunication', DateType::class, [
                'label' => 'interested_party.field.last_communication',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('nextCommunication', DateType::class, [
                'label' => 'interested_party.field.next_communication',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('feedback', TextareaType::class, [
                'label' => 'interested_party.field.feedback',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('satisfactionLevel', IntegerType::class, [
                'label' => 'interested_party.field.satisfaction_level',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 5],
            ])
            ->add('issues', TextareaType::class, [
                'label' => 'interested_party.field.issues',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InterestedParty::class,
            'translation_domain' => 'interested_parties',
        ]);
    }
}
