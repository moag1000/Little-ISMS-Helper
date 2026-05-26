<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\FrameworkReferencesTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ControlFrameworkReferencesType — proper FormType for the
 * `Control.frameworkReferences` JSON column.
 *
 * Closes Bucket 5 item 5.5 (DEFERRED). The column shape is
 * `array<framework_slug, list<reference_id>>` — a variable-key associative
 * map that a plain `CollectionType<EntryType>` cannot express naturally.
 *
 * Solution: one sub-form per known framework slug, each backed by a
 * TextType (rendered as a TomSelect "create" input — tag-style multi-value
 * picker that accepts free input for non-catalog refs). The custom widget
 * template (`templates/_form/control_framework_references.html.twig`)
 * shows one chip-row per framework.
 *
 * Data round-trip via `FrameworkReferencesTransformer`:
 *   entity:  {iso27001: ['A.5.1'], bsi: ['ORP.1.A1']}
 *   view  :  {iso27001: 'A.5.1', bsi: 'ORP.1.A1'} (comma-separated per slug)
 *
 * Unknown slugs that already exist on the entity are added dynamically via
 * PRE_SET_DATA + PRE_SUBMIT so legacy framework keys (e.g. a tenant-custom
 * slug) don't get silently dropped.
 *
 * Wire-up (replaces `JsonStructuredType::class` in ControlType):
 *   $builder->add('frameworkReferences', ControlFrameworkReferencesType::class, [
 *       'label' => 'control.field.framework_references',
 *       'help'  => 'control.help.framework_references_chip',
 *   ]);
 */
final class ControlFrameworkReferencesType extends AbstractType
{
    /**
     * Canonical framework slugs we always surface — matches the existing
     * help-text example and ControlType call-sites elsewhere.
     *
     * Labels are translation keys resolved against the `control` domain
     * via `framework.label.<slug>` (see translations/control.{de,en}.yaml).
     *
     * @var list<string>
     */
    public const KNOWN_FRAMEWORKS = [
        'iso27001',
        'iso27017',
        'iso27018',
        'iso27701',
        'iso22301',
        'bsi',
        'bsi_c5',
        'nist',
        'nist_csf',
        'dora',
        'nis2',
        'tisax',
        'soc2',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $known = self::KNOWN_FRAMEWORKS;

        // Always render the known frameworks…
        foreach ($known as $slug) {
            $this->addFrameworkField($builder, $slug);
        }

        // …and dynamically surface any extra slugs already on the entity so
        // they survive a round-trip (e.g. tenant-custom framework keys).
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($known): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            $form = $event->getForm();
            foreach (array_keys($data) as $slug) {
                if (!is_string($slug) || in_array($slug, $known, true)) {
                    continue;
                }
                if (!$form->has($slug)) {
                    $this->addFrameworkField($form, $slug);
                }
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($known): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            $form = $event->getForm();
            foreach (array_keys($data) as $slug) {
                if (!is_string($slug) || in_array($slug, $known, true)) {
                    continue;
                }
                if (!$form->has($slug)) {
                    $this->addFrameworkField($form, $slug);
                }
            }
        });

        $builder->addModelTransformer(new FrameworkReferencesTransformer());
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['known_frameworks'] = self::KNOWN_FRAMEWORKS;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Backing entity property is array<string, list<string>>|null.
            'data_class' => null,
            'compound' => true,
            'translation_domain' => 'control',
            'empty_data' => static fn (): array => [],
            'error_bubbling' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'control_framework_references';
    }

    /**
     * Register a single per-framework TextType. The view template renders
     * each as a TomSelect tag-input.
     */
    private function addFrameworkField(FormBuilderInterface|FormInterface $form, string $slug): void
    {
        $form->add($slug, TextType::class, [
            'label' => 'framework.label.' . $slug,
            'required' => false,
            'attr' => [
                'class' => 'fa-framework-ref-input',
                'data-controller' => 'tom-select',
                'data-tom-select-create-value' => 'true',
                'data-framework-slug' => $slug,
                'placeholder' => 'control.placeholder.framework_reference',
            ],
        ]);
    }
}
