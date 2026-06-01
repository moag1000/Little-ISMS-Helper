<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\DataTransformer\FrameworkReferencesTransformer;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
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

    /**
     * Framework-appropriate placeholder examples, so the user sees references in
     * the right notation per framework (TISAX VDA-ISA "1.1.1", not ISO "A.5.1").
     * Junior-Implementer feedback: generic "A.5.1" placeholder under every
     * framework is misleading. Used by the form theme to build the placeholder.
     *
     * @var array<string, string>
     */
    public const EXAMPLE_REFERENCES = [
        'iso27001' => 'A.5.1, A.8.16',
        'iso27017' => 'CLD.6.3.1, CLD.12.1.5',
        'iso27018' => 'A.5.1, A.10.1',
        'iso27701' => '6.5, A.7.2.1',
        'iso22301' => '8.4.2, 8.4.4',
        'bsi' => 'ORP.1.A1, OPS.1.1.2.A5',
        'bsi_c5' => 'OPS-01, IDM-09',
        'nist' => 'AC-2, SC-7',
        'nist_csf' => 'PR.AA-01, DE.CM-01',
        'dora' => 'Art. 6, Art. 9',
        'nis2' => 'Art. 21(2)(a), Art. 23',
        'tisax' => '1.1.1, 1.2.1',
        'soc2' => 'CC6.1, CC7.2',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

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

        // E — seed each per-framework TomSelect with the catalogue of known
        // requirement-ids for that framework, so the user can autocomplete
        // instead of typing references blind. Keyed by the framework slug used
        // as the child-form name. Frameworks without a catalogue simply get an
        // empty list (free-entry still works via create=true).
        $catalogue = $this->buildCatalogue();
        $frameworkOptions = [];
        foreach (array_keys($form->all()) as $slug) {
            $frameworkOptions[(string) $slug] = $catalogue[(string) $slug] ?? [];
        }
        $view->vars['framework_options'] = $frameworkOptions;
        $view->vars['framework_examples'] = self::EXAMPLE_REFERENCES;
    }

    /**
     * Build a slug → list<{value,label}> map of catalogue requirement-ids for
     * every framework whose normalised code matches a row slug.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function buildCatalogue(): array
    {
        $catalogue = [];
        foreach ($this->frameworkRepository->findAll() as $framework) {
            $slug = $this->resolveSlug($framework->getCode());
            if ($slug === null) {
                continue;
            }
            $entries = [];
            foreach ($this->requirementRepository->findByFramework($framework) as $requirement) {
                $value = trim((string) $requirement->getRequirementId());
                if ($value === '') {
                    continue;
                }
                $title = trim((string) $requirement->getTitle());
                $entries[$value] = [
                    'value' => $value,
                    'label' => $title !== '' ? ($value . ' — ' . $title) : $value,
                ];
            }
            if ($entries !== []) {
                // array_values to drop the de-dup string keys.
                $catalogue[$slug] = array_merge($catalogue[$slug] ?? [], array_values($entries));
            }
        }

        return $catalogue;
    }

    /**
     * Map a framework code (e.g. "ISO27001", "BSI_GRUNDSCHUTZ", "NIST-CSF") to
     * one of the KNOWN_FRAMEWORKS row slugs via normalised prefix matching.
     */
    private function resolveSlug(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));
        if ($normalised === '') {
            return null;
        }

        // Direct/prefix match against the known slugs (longest first so e.g.
        // "bsic5" beats "bsi", "nistcsf" beats "nist").
        $known = self::KNOWN_FRAMEWORKS;
        usort($known, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($known as $slug) {
            $needle = str_replace('_', '', $slug);
            if (str_starts_with($normalised, $needle)) {
                return $slug;
            }
        }

        return null;
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
                // Render the option-dropdown under <body> so it is not clipped
                // by the rounded form-section card (overflow:hidden ancestor).
                'data-tom-select-dropdown-parent-value' => 'body',
                'data-framework-slug' => $slug,
                'placeholder' => 'control.placeholder.framework_reference',
            ],
        ]);
    }
}
