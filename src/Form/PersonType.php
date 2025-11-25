<?php

namespace App\Form;

use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'person.field.full_name',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'person.placeholder.full_name',
                ],
            ])
            ->add('personType', ChoiceType::class, [
                'label' => 'person.field.person_type',
                'choices' => [
                    'person.type.employee' => 'employee',
                    'person.type.contractor' => 'contractor',
                    'person.type.visitor' => 'visitor',
                    'person.type.vendor' => 'vendor',
                    'person.type.auditor' => 'auditor',
                    'person.type.consultant' => 'consultant',
                    'person.type.other' => 'other',
                ],
                'required' => true,
            ])
            ->add('badgeId', TextType::class, [
                'label' => 'person.field.badge_id',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'person.placeholder.badge_id',
                ],
                'help' => 'person.help.badge_id',
            ])
            ->add('company', TextType::class, [
                'label' => 'person.field.company',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'person.placeholder.company',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'person.field.email',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'person.placeholder.email',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'person.field.phone',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                    'placeholder' => 'person.placeholder.phone',
                ],
            ])
            ->add('department', TextType::class, [
                'label' => 'person.field.department',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'person.placeholder.department',
                ],
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'person.field.job_title',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                    'placeholder' => 'person.placeholder.job_title',
                ],
            ])
            ->add('linkedUser', EntityType::class, [
                'label' => 'person.field.linked_user',
                'class' => User::class,
                'choice_label' => 'email',
                'required' => false,
                'placeholder' => 'person.placeholder.linked_user',
                'help' => 'person.help.linked_user',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'person.field.active',
                'required' => false,
            ])
            ->add('accessValidFrom', DateType::class, [
                'label' => 'person.field.access_valid_from',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'person.help.access_valid_from',
            ])
            ->add('accessValidUntil', DateType::class, [
                'label' => 'person.field.access_valid_until',
                'required' => false,
                'widget' => 'single_text',
                'help' => 'person.help.access_valid_until',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'person.field.notes',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'person.placeholder.notes',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Person::class,
            'translation_domain' => 'people',
        ]);
    }
}
