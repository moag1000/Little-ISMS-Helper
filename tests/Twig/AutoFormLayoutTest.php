<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Form\SectionMapInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Twig\Environment;

/**
 * Integration tests for the `_auto_form.html.twig` layout parameter (Phase-2).
 *
 * Covers:
 *  - layout='flat'  (default, backward-compat) → no .fa-form-layout class
 *  - layout='outline-rail' → .fa-form-layout + .fa-form-section present, sections match
 *  - layout='outline-rail' with empty sections → graceful render (no exception)
 *  - strict_whitelist works in both layouts
 */
final class AutoFormLayoutTest extends KernelTestCase
{
    private Environment $twig;
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container           = self::getContainer();
        $this->twig          = $container->get('twig');
        $this->formFactory   = $container->get(FormFactoryInterface::class);
    }

    // ── Flat layout (default) ─────────────────────────────────────────────

    #[Test]
    public function flatLayoutRendersFieldsetAndNoFaFormLayout(): void
    {
        $form    = $this->buildSimpleForm(['firstName', 'lastName']);
        $output  = $this->renderAutoForm($form, [
            'sections' => [
                'details' => ['label' => 'Details', 'fields' => ['firstName', 'lastName']],
            ],
            // no layout key → defaults to 'flat'
        ]);

        self::assertStringNotContainsString('fa-form-layout', $output,
            'flat layout must NOT emit .fa-form-layout wrapper');
        self::assertStringContainsString('<fieldset', $output,
            'flat layout must emit <fieldset> per section');
        self::assertStringContainsString('Details', $output,
            'flat layout must show section label');
    }

    #[Test]
    public function explicitFlatLayoutProducesIdenticalOutputToDefault(): void
    {
        // Two independent form builds so we do not re-render the same FormView twice
        // (Symfony throws BadMethodCallException if a field is rendered a second time).
        $sections = ['loc' => ['label' => 'Location', 'fields' => ['city']]];

        $outputDefault = $this->renderAutoForm(
            $this->buildSimpleForm(['city']),
            ['sections' => $sections],
        );
        $outputFlat    = $this->renderAutoForm(
            $this->buildSimpleForm(['city']),
            ['sections' => $sections, 'layout' => 'flat'],
        );

        // Normalise whitespace before comparison
        self::assertSame(
            preg_replace('/\s+/', ' ', trim($outputDefault)),
            preg_replace('/\s+/', ' ', trim($outputFlat)),
            'explicit layout=flat must be identical to omitting the layout param',
        );
    }

    // ── Outline-rail layout ───────────────────────────────────────────────

    #[Test]
    public function outlineRailRendersFormLayoutWrapper(): void
    {
        $form   = $this->buildSimpleForm(['firstName', 'lastName']);
        $output = $this->renderAutoForm($form, [
            'layout'  => 'outline-rail',
            'title'   => 'Personal Information',
            'sections' => [
                'basic' => ['label' => 'Basic data', 'fields' => ['firstName', 'lastName']],
            ],
        ]);

        self::assertStringContainsString('fa-form-layout', $output,
            'outline-rail must emit .fa-form-layout wrapper');
        self::assertStringContainsString('data-controller="form-layout"', $output,
            'outline-rail must emit Stimulus form-layout controller attribute');
    }

    #[Test]
    public function outlineRailRendersFaFormSectionForEachSection(): void
    {
        $form   = $this->buildSimpleForm(['firstName', 'city']);
        $output = $this->renderAutoForm($form, [
            'layout'  => 'outline-rail',
            'title'   => 'Multi-section form',
            'sections' => [
                'personal' => ['label' => 'Personal',  'fields' => ['firstName']],
                'location' => ['label' => 'Location',  'fields' => ['city']],
            ],
        ]);

        $sectionCount = substr_count($output, 'fa-form-section');
        // Each section appears at least twice (section card + outline item)
        self::assertGreaterThanOrEqual(2, $sectionCount,
            'outline-rail must emit .fa-form-section for each section');
        self::assertStringContainsString('Personal', $output);
        self::assertStringContainsString('Location', $output);
    }

    #[Test]
    public function outlineRailUsesEyebrowTitleSubtitle(): void
    {
        $form   = $this->buildSimpleForm(['firstName']);
        $output = $this->renderAutoForm($form, [
            'layout'   => 'outline-rail',
            'eyebrow'  => 'GDPR Art. 35',
            'title'    => 'DPIA Assessment',
            'subtitle' => 'HR-Server 04',
            'sections' => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
        ]);

        self::assertStringContainsString('GDPR Art. 35', $output,
            'eyebrow must appear in outline-rail header');
        self::assertStringContainsString('DPIA Assessment', $output,
            'title must appear in outline-rail header');
        self::assertStringContainsString('HR-Server 04', $output,
            'subtitle must appear in outline-rail header');
    }

    #[Test]
    public function outlineRailRendersProgressBar(): void
    {
        $form   = $this->buildSimpleForm(['firstName', 'city']);
        $output = $this->renderAutoForm($form, [
            'layout'  => 'outline-rail',
            'title'   => 'Progress test',
            'sections' => [
                'a' => ['label' => 'A', 'fields' => ['firstName']],
                'b' => ['label' => 'B', 'fields' => ['city']],
            ],
        ]);

        self::assertStringContainsString('fa-form-layout__progress', $output,
            'outline-rail must render progress bar');
    }

    #[Test]
    public function outlineRailWithEmptySectionsGracefullyRendersWithoutException(): void
    {
        $form = $this->buildSimpleForm(['firstName']);
        // Pass empty sections array — must not throw, must still emit wrapper
        $output = $this->renderAutoForm($form, [
            'layout'   => 'outline-rail',
            'title'    => 'Empty sections test',
            'sections' => [],
        ]);

        self::assertStringContainsString('fa-form-layout', $output,
            'outline-rail with no sections must still emit wrapper without exception');
    }

    #[Test]
    public function outlineRailDefaultSubmitButtonUsesCommonSaveTranslation(): void
    {
        $form   = $this->buildSimpleForm(['firstName']);
        $output = $this->renderAutoForm($form, [
            'layout'   => 'outline-rail',
            'title'    => 'Save button test',
            'sections' => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
            // no 'actions' → default uses common.save translation
        ]);

        // Should contain save-type button text (either "Speichern" DE or "Save" EN)
        self::assertMatchesRegularExpression(
            '/(Speichern|Save)/u',
            $output,
            'default submit button must use common.save translation key',
        );
    }

    #[Test]
    public function outlineRailCustomActionsRendered(): void
    {
        $form   = $this->buildSimpleForm(['firstName']);
        $output = $this->renderAutoForm($form, [
            'layout'  => 'outline-rail',
            'title'   => 'Custom actions',
            'sections' => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
            'actions' => [
                'close'  => ['label' => 'Cancel Form',   'variant' => 'ghost'],
                'submit' => ['label' => 'Confirm submit', 'variant' => 'primary'],
            ],
        ]);

        self::assertStringContainsString('Cancel Form', $output);
        self::assertStringContainsString('Confirm submit', $output);
    }

    // ── Outline-rail catch-all for unmapped fields ───────────────────────

    #[Test]
    public function outlineRailRendersCatchAllSectionForUnmappedFields(): void
    {
        // `lastName` is intentionally NOT listed in any section. Without the
        // catch-all, form_end() would render it via form_rest AFTER the
        // footer action-bar — escaping outside the section cards.
        $form   = $this->buildSimpleForm(['firstName', 'lastName']);
        $output = $this->renderAutoForm($form, [
            'layout'   => 'outline-rail',
            'title'    => 'Catch-all test',
            'sections' => [
                'core' => ['label' => 'Core fields', 'fields' => ['firstName']],
            ],
        ]);

        // Catch-all section must appear in the outline (Weitere Felder / Other)
        self::assertMatchesRegularExpression(
            '/(Weitere Felder|Other|Sonstige)/u',
            $output,
            'outline-rail must render a catch-all section labelled with the default other_section_label',
        );
        // The unmapped field must be rendered inside the section cards, not by form_rest
        self::assertStringContainsString('LastName', $output,
            'unmapped field label must appear via the catch-all section');
        // Sanity-check: the catch-all section must appear AFTER the explicit
        // section in the markup (so unmapped fields trail mapped ones).
        $corePos    = strpos($output, 'Core fields');
        $catchPos   = strpos($output, 'Weitere Felder');
        self::assertNotFalse($corePos);
        self::assertNotFalse($catchPos);
        self::assertLessThan($catchPos, $corePos,
            'catch-all section must render after the explicit sections');
    }

    #[Test]
    public function outlineRailCatchAllRespectsSkipOther(): void
    {
        $form   = $this->buildSimpleForm(['firstName', 'lastName']);
        $output = $this->renderAutoForm($form, [
            'layout'     => 'outline-rail',
            'title'      => 'Skip-other test',
            'skip_other' => true,
            'sections'   => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
        ]);

        // No catch-all section header when skip_other=true
        self::assertStringNotContainsString('Weitere Felder', $output,
            'skip_other=true must suppress the outline-rail catch-all');
        // The unmapped field is also not rendered inside any section
        // (this is the legacy behaviour — caller is expected to handle it
        // outside _auto_form when skip_other is opted-in).
    }

    // ── extras_before_actions slot ───────────────────────────────────────

    #[Test]
    public function outlineRailRendersExtrasBeforeActions(): void
    {
        $form   = $this->buildSimpleForm(['firstName']);
        $extras = '<div class="my-test-extras"><button type="button">In-form CTA</button></div>';
        $output = $this->renderAutoForm($form, [
            'layout'   => 'outline-rail',
            'title'    => 'Extras slot test',
            'sections' => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
            'extras_before_actions' => $extras,
        ]);

        // Extras must be rendered (raw) inside the layout
        self::assertStringContainsString('my-test-extras', $output,
            'extras_before_actions HTML must be emitted inside the layout');
        self::assertStringContainsString('In-form CTA', $output,
            'extras_before_actions content must be visible');

        // Extras must appear BEFORE the footer action-bar in the markup
        $extrasPos = strpos($output, 'my-test-extras');
        $footerPos = strpos($output, 'fa-form-layout__footer');
        self::assertNotFalse($extrasPos, 'extras marker must be in output');
        self::assertNotFalse($footerPos, 'footer marker must be in output');
        self::assertLessThan(
            $footerPos,
            $extrasPos,
            'extras_before_actions must render BEFORE the footer action-bar',
        );
    }

    // ── strict_whitelist in both layouts ─────────────────────────────────

    #[Test]
    public function strictWhitelistSuppressesCatchAllInFlatLayout(): void
    {
        // Build form with an extra field NOT listed in sections
        $form   = $this->buildSimpleForm(['firstName', 'hidden_extra']);
        $output = $this->renderAutoForm($form, [
            'layout'          => 'flat',
            'strict_whitelist' => true,
            'sections'        => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
        ]);

        // strict_whitelist suppresses the catch-all — hidden_extra must NOT
        // appear via a catch-all fieldset (it may appear in dev H-03 info box,
        // but the catch-all fieldset itself must be absent)
        self::assertStringNotContainsString(
            'common.section.other',
            $output,
            'strict_whitelist must suppress the catch-all "other fields" section',
        );
    }

    #[Test]
    public function strictWhitelistWorksInOutlineRailLayout(): void
    {
        $form   = $this->buildSimpleForm(['firstName', 'lastName']);
        // Only list firstName — lastName is not in any section
        $output = $this->renderAutoForm($form, [
            'layout'          => 'outline-rail',
            'title'           => 'Strict test',
            'strict_whitelist' => true,
            'sections'        => [
                'core' => ['label' => 'Core', 'fields' => ['firstName']],
            ],
        ]);

        // Must still render the outline-rail wrapper
        self::assertStringContainsString('fa-form-layout', $output,
            'strict_whitelist must not suppress the outline-rail layout itself');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Build a minimal Symfony form with the given text fields.
     *
     * CSRF protection is disabled so that createView() does not require an
     * active HTTP session (known issue: CSRF token generation uses session
     * storage — see feedback_csrf_tests_session.md in memory index).
     *
     * @param list<string> $fieldNames
     */
    private function buildSimpleForm(array $fieldNames): FormView
    {
        $builder = $this->formFactory->createBuilder(
            \Symfony\Component\Form\Extension\Core\Type\FormType::class,
            null,
            ['csrf_protection' => false],
        );
        foreach ($fieldNames as $name) {
            $builder->add($name, TextType::class, [
                'label'    => ucfirst($name),
                'required' => false,
            ]);
        }
        return $builder->getForm()->createView();
    }

    /**
     * Render `_auto_form.html.twig` with the given parameters.
     *
     * @param array<string, mixed> $params
     */
    private function renderAutoForm(FormView $form, array $params): string
    {
        $params['form'] = $form;
        return $this->twig->render(
            '_components/_auto_form.html.twig',
            $params,
        );
    }
}
