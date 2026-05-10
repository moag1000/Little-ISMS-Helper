<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\TenantBranding;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant Policy-Doc Style Configurator.
 *
 * Edits the 12 `policyDoc*` fields on TenantBranding that drive the
 * `_fa_policy_doc.html.twig` macro (font, cover pattern, watermark,
 * signature lines, TOC/history/Annex-A toggles, page margin, cover
 * logo size, custom-CSS override).
 *
 * Custom-CSS field is only rendered for ROLE_ADMIN. Watermark opacity
 * is exposed as 0–100 % via the `policy_style_preview` Stimulus
 * controller and persisted as a 0.0–1.0 float.
 */
final class TenantPolicyStyleType extends AbstractType
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('policyDocFontFamily', ChoiceType::class, [
                'label' => 'admin.policy_style.field.font_family',
                'help' => 'admin.policy_style.help.font_family',
                'choices' => [
                    'Inter' => 'Inter',
                    'Roboto' => 'Roboto',
                    'Source Sans 3' => 'Source Sans 3',
                    'Lato' => 'Lato',
                    'Open Sans' => 'Open Sans',
                    'Merriweather (Serif)' => 'Merriweather',
                    'IBM Plex Sans' => 'IBM Plex Sans',
                    'System UI' => 'system-ui',
                ],
                'required' => true,
                'translation_domain' => 'admin',
            ])
            ->add('policyDocCoverPattern', ChoiceType::class, [
                'label' => 'admin.policy_style.field.cover_pattern',
                'help' => 'admin.policy_style.help.cover_pattern',
                'choices' => [
                    'admin.policy_style.cover.minimal' => 'minimal',
                    'admin.policy_style.cover.branded' => 'branded',
                    'admin.policy_style.cover.auditor_formal' => 'auditor-formal',
                    'admin.policy_style.cover.engineering' => 'engineering',
                ],
                'required' => true,
                'translation_domain' => 'admin',
                'choice_translation_domain' => 'admin',
            ])
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

        // Advanced: full CSS override — gated to ROLE_ADMIN to limit
        // blast-radius (paste-only, rendered |raw inside the doc style).
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $builder->add('policyDocCustomCss', TextareaType::class, [
                'label' => 'admin.policy_style.field.custom_css',
                'help' => 'admin.policy_style.help.custom_css',
                'required' => false,
                'translation_domain' => 'admin',
                'attr' => ['rows' => 8, 'spellcheck' => 'false', 'class' => 'font-monospace'],
                'constraints' => [new Assert\Length(max: 16000)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TenantBranding::class,
            'translation_domain' => 'admin',
            'attr' => ['data-controller' => 'policy-style-preview'],
        ]);
    }
}
