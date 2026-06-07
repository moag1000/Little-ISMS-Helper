<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Person;
use App\Entity\Team;
use App\Entity\User;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TeamType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'overview'  => ['name', 'type', 'description', 'isActive'],
            'lead'      => ['teamLead', 'teamLeadPerson'],
            'members'   => ['members'],
            'validity'  => ['validFrom', 'validUntil'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'planning.team.field.name',
                'required' => true,
                'attr'     => ['maxlength' => 255],
            ])
            ->add('type', ChoiceType::class, [
                'label'                     => 'planning.team.field.type',
                'required'                  => false,
                'placeholder'               => 'planning.team.type.placeholder',
                'choices'                   => [
                    'planning.team.type.operational'   => 'operational',
                    'planning.team.type.strategic'     => 'strategic',
                    'planning.team.type.technical'     => 'technical',
                    'planning.team.type.communication' => 'communication',
                    'planning.team.type.other'         => 'other',
                ],
                'choice_translation_domain' => 'planning',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'planning.team.field.description',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'planning.team.field.is_active',
                'required' => false,
            ])
            ->add('teamLead', EntityType::class, [
                'label'        => 'planning.team.field.team_lead',
                'class'        => User::class,
                'choice_label' => 'email',
                'required'     => false,
                'placeholder'  => 'planning.team.field.team_lead_placeholder',
            ])
            ->add('teamLeadPerson', EntityType::class, [
                'label'        => 'planning.team.field.team_lead_person',
                'class'        => Person::class,
                'choice_label' => 'fullName',
                'required'     => false,
                'placeholder'  => 'planning.team.field.team_lead_person_placeholder',
            ])
            ->add('members', EntityType::class, [
                'label'        => 'planning.team.field.members',
                'class'        => Person::class,
                'choice_label' => 'fullName',
                'multiple'     => true,
                'required'     => false,
                'attr'         => ['data-controller' => 'tom-select'],
            ])
            ->add('validFrom', DateType::class, [
                'label'  => 'planning.team.field.valid_from',
                'widget' => 'single_text',
                'input'  => 'datetime_immutable',
                'required' => false,
            ])
            ->add('validUntil', DateType::class, [
                'label'    => 'planning.team.field.valid_until',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => Team::class,
            'translation_domain' => 'planning',
        ]);
    }
}
