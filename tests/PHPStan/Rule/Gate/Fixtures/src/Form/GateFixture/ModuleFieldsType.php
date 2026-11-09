<?php

declare(strict_types=1);

namespace App\Form\GateFixture;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/** Gate 11 fixture (form pass): every `expect:` line is a mutation the rule must report. */
final class ModuleFieldsType extends AbstractType
{
    private const DORA_FIELD = 'doraCriticality';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class);
        $builder->add('doraUngated', TextType::class); // expect: gate11.formField

        if ($this->isModuleActive('nis2_dora')) {
            $builder->add('nis2Category', TextType::class);
            $builder
                ->add('name2', TextType::class)
                ->add('doraChained', TextType::class);
        }
        if ($this->isModuleActive('core')) {
            $builder->add('nis2Wrong', TextType::class); // expect: gate11.formField
        }
        if ($this->isModuleActive('privacy')) {
            $builder->add('tisaxLabel', TextType::class); // expect: gate11.formField
        }
        if ($this->isModuleActive('nis2_dora') || true) {
            $builder->add('doraOr', TextType::class); // expect: gate11.formField
        }
        if ($this->isModuleActive('nis2_dora') && $options['x']) {
            $builder->add('doraAnd', TextType::class);
        }
        if ($this->isModuleActive('privacy')) {
            $builder->add('gdprIn', TextType::class);
        } else {
            $builder->add('gdprElse', TextType::class); // expect: gate11.formField
        }
        $builder->add( // expect: gate11.formField
            'doraMultiLine',
            TextType::class,
        );
        $builder->add(self::DORA_FIELD, TextType::class); // expect: gate11.formField
        if ($this->isAnyModuleActive('nis2_dora', 'marisk')) {
            $builder->add(self::DORA_FIELD . 'X', TextType::class);
        }
        $this->addModuleGatedField($builder, 'nis2_dora', 'doraHelper', TextType::class);
        $this->addModuleGatedField($builder, 'privacy', 'doraWrongHelper', TextType::class); // expect: gate11.formField
        if ($this->isModuleActive('lksg')) {
            $this->addLksgFields($builder);
        }
        $this->addTisaxFields($builder);
        // @no-module-gate-required: the whole form is TISAX-scoped (only rendered behind the tisax module)
        $builder->add('tisaxReviewed', TextType::class);
        // #[RequiresModule('tisax')] — a comment, no gate
        $builder->add('tisaxCommented', TextType::class); // expect: gate11.formField
    }

    private function isModuleActive(string $module): bool
    {
        return $module !== '';
    }

    private function isAnyModuleActive(string ...$modules): bool
    {
        return $modules !== [];
    }

    private function addModuleGatedField(FormBuilderInterface $builder, string $moduleKey, string $name, string $type): void
    {
        if ($this->isModuleActive($moduleKey)) {
            $builder->add($name, $type);
        }
    }

    private function addLksgFields(FormBuilderInterface $builder): void
    {
        $builder->add('lksgRisk', TextType::class);
    }

    private function addTisaxFields(FormBuilderInterface $builder): void
    {
        $builder->add('tisaxUngatedHelper', TextType::class); // expect: gate11.formField
    }
}
