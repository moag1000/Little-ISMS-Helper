<?php

declare(strict_types=1);

namespace App\Form\Step\Sso;

use App\Entity\IdentityProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Step 1 of the SSO wizard: choose a preset or start from scratch (generic).
 */
final class SsoPresetStepType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $presetChoices = [];
        foreach (IdentityProvider::VALID_PRESETS as $preset) {
            $presetChoices['sso.preset.' . $preset] = $preset;
        }

        $builder->add('presetType', ChoiceType::class, [
            'label' => 'sso.wizard.step1.preset_label',
            'choices' => $presetChoices,
            'expanded' => true,
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'sso',
        ]);
    }
}
