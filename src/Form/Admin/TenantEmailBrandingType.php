<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TenantEmailBrandingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailFromName', TextType::class, [
                'label' => 'tenant.email.field.from_name',
                'help' => 'tenant.email.help.from_name',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ])
            ->add('emailFromAddress', EmailType::class, [
                'label' => 'tenant.email.field.from_address',
                'help' => 'tenant.email.help.from_address',
                'required' => false,
                'attr' => ['maxlength' => 180],
            ])
            ->add('emailLogoUrl', UrlType::class, [
                'label' => 'tenant.email.field.logo_url',
                'help' => 'tenant.email.help.logo_url',
                'required' => false,
                'attr' => ['maxlength' => 500, 'placeholder' => 'https://...'],
                'default_protocol' => 'https',
            ])
            ->add('emailFooterText', TextareaType::class, [
                'label' => 'tenant.email.field.footer_text',
                'help' => 'tenant.email.help.footer_text',
                'required' => false,
                'attr' => ['rows' => 4, 'maxlength' => 2000],
            ])
            ->add('emailSupportAddress', EmailType::class, [
                'label' => 'tenant.email.field.support_address',
                'help' => 'tenant.email.help.support_address',
                'required' => false,
                'attr' => ['maxlength' => 180],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
            'translation_domain' => 'tenant',
        ]);
    }
}
