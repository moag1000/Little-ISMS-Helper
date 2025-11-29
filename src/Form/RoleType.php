<?php

namespace App\Form;

use App\Entity\Permission;
use App\Entity\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'role_management.field.name',
                'attr' => [
                    'placeholder' => 'role_management.placeholder.name',
                    'maxlength' => 100,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'role_management.field.description',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'role_management.placeholder.description',
                ],
            ])
            ->add('isSystemRole', CheckboxType::class, [
                'label' => 'role_management.field.system_role',
                'help' => 'role_management.help.system_role',
                'required' => false,
            ])
            ->add('permissions', EntityType::class, [
                'class' => Permission::class,
                'label' => 'role_management.field.permissions',
                'help' => 'role_management.help.permissions',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choice_label' => 'name',
                'group_by' => 'category',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Role::class,
            'translation_domain' => 'role_management',
        ]);
    }
}
