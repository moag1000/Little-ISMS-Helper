<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\SectionMapInterface;
use App\Entity\TenantBranding;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant Policy-Doc Style Configurator.
 *
 * Edits the `policyDoc*` fields on TenantBranding that drive the
 * `_fa_policy_doc.html.twig` macro (watermark, signature lines,
 * TOC/history/Annex-A toggles, page margin, cover logo size, footer text).
 *
 * Watermark opacity is exposed as 0–100 % via the `policy_style_preview` Stimulus
 * controller and persisted as a 0.0–1.0 float.
 */
// SectionMap replaced by fa-tabs in edit.html.twig (live-preview compat).
// Fields are grouped into General/Branding/Content tabs.
// Preview panel is a sibling col alongside the fa-tabs container.
final class TenantPolicyStyleType extends AbstractType implements SectionMapInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('policyDocCoverLogoSize', ChoiceType::class, [
                'label' => 'admin.policy_style.field.cover_logo_size',
                'choices' => [
                    'admin.policy_style.size.small' => 'small',
                    'admin.policy_style.size.medium' => 'medium',
                    'admin.policy_style.size.large' => 'large',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
            ->add('policyDocPageMargin', ChoiceType::class, [
                'label' => 'admin.policy_style.field.page_margin',
                'help' => 'admin.policy_style.help.page_margin',
                'choices' => [
                    'admin.policy_style.margin.compact' => 'compact',
                    'admin.policy_style.margin.standard' => 'standard',
                    'admin.policy_style.margin.wide' => 'wide',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
            ->add('policyDocWatermarkEnabled', CheckboxType::class, [
                'label' => 'admin.policy_style.field.watermark_enabled',
                'help' => 'admin.policy_style.help.watermark_enabled',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('policyDocWatermarkOpacity', NumberType::class, [
                'label' => 'admin.policy_style.field.watermark_opacity',
                'help' => 'admin.policy_style.help.watermark_opacity',
                'required' => true,
                'scale' => 2,
                'translation_domain' => 'admin',
                'attr' => [
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.01,
                    'data-policy-style-preview-target' => 'opacity',
                ],
                'constraints' => [
                    new Assert\Range(min: 0.0, max: 1.0),
                ],
            ])
            ->add('policyDocSignatureLines', IntegerType::class, [
                'label' => 'admin.policy_style.field.signature_lines',
                'help' => 'admin.policy_style.help.signature_lines',
                'required' => true,
                'translation_domain' => 'admin',
                'attr' => ['min' => 1, 'max' => 6],
                'constraints' => [
                    new Assert\Range(min: 1, max: 6),
                ],
            ])
            ->add('policyDocShowToc', CheckboxType::class, [
                'label' => 'admin.policy_style.field.show_toc',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('policyDocShowHistory', CheckboxType::class, [
                'label' => 'admin.policy_style.field.show_history',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('policyDocShowAnnexARefs', CheckboxType::class, [
                'label' => 'admin.policy_style.field.show_annex_a_refs',
                'required' => false,
                'translation_domain' => 'admin',
            ])
            ->add('policyDocFooterText', TextType::class, [
                'label' => 'admin.policy_style.field.footer_text',
                'help' => 'admin.policy_style.help.footer_text',
                'required' => false,
                'translation_domain' => 'admin',
                'constraints' => [new Assert\Length(max: 500)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TenantBranding::class,
            'translation_domain' => 'admin',
            'attr' => ['data-controller' => 'policy-style-preview'],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function getSectionMap(): array
    {
        return [
            'layout'     => ['policyDocCoverLogoSize', 'policyDocPageMargin'],
            'branding'   => ['policyDocWatermarkEnabled', 'policyDocWatermarkOpacity'],
            'content'    => ['policyDocSignatureLines', 'policyDocShowToc', 'policyDocShowHistory', 'policyDocShowAnnexARefs', 'policyDocFooterText'],
        ];
    }
}
