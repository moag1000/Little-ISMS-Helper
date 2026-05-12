<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IdentityProviderRoleMapping;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IdentityProviderRoleMappingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('claimKey', TextType::class, [
                'label'              => 'sso.role_mapping.field.claim_key',
                'translation_domain' => 'sso',
                'attr'               => ['placeholder' => 'groups', 'class' => 'form-control form-control-sm'],
            ])
            ->add('claimValueExpression', TextType::class, [
                'label'              => 'sso.role_mapping.field.claim_value_expression',
                'translation_domain' => 'sso',
                'attr'               => ['placeholder' => 'isms-admin', 'class' => 'form-control form-control-sm'],
            ])
            ->add('assignedRole', TextType::class, [
                'label'              => 'sso.role_mapping.field.assigned_role',
                'translation_domain' => 'sso',
                'attr'               => ['placeholder' => 'ROLE_ADMIN', 'class' => 'form-control form-control-sm'],
            ])
            ->add('priority', IntegerType::class, [
                'label'              => 'sso.role_mapping.field.priority',
                'translation_domain' => 'sso',
                'attr'               => ['class' => 'form-control form-control-sm', 'min' => 0],
            ])
            ->add('isActive', CheckboxType::class, [
                'label'              => 'sso.role_mapping.field.is_active',
                'translation_domain' => 'sso',
                'required'           => false,
            ])
            ->add('auditDescription', TextType::class, [
                'label'              => 'sso.role_mapping.field.audit_description',
                'translation_domain' => 'sso',
                'required'           => false,
                'attr'               => ['class' => 'form-control form-control-sm'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => IdentityProviderRoleMapping::class,
            'translation_domain' => 'sso',
        ]);
    }
}
