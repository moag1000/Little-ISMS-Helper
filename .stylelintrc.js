module.exports = {
    extends: ['stylelint-config-standard'],
    ignoreFiles: [
        'assets/styles/fairy-aurora.css',        // Token SoT — defines hex
        'assets/styles/alva.css',                // SVG brand fills legitimate
        'assets/styles/bootstrap*.css',          // Vendor
        'assets/Little ISMS Helper Design System/**', // Spec/design assets
        'docs/design_system/**',                 // Design docs
        'vendor/**',                             // PHP/JS vendor
        'node_modules/**',                       // Node modules
        'public/build/**',                       // Webpack/Encore output
        'public/**/*.css',                       // Compiled output
        'var/**/*.css'
    ],
    rules: {
        // Allow CSS custom-property definitions and var() usage; only ban raw hex in color-valued properties
        'declaration-property-value-disallowed-list': {
            '/^(color|background|background-color|border|border-color|border-top|border-right|border-bottom|border-left|box-shadow|outline|outline-color|text-shadow|fill|stroke|caret-color)$/': [
                '/#[0-9a-fA-F]{3,8}\\b/'
            ]
        },
        // Suppress noisy standard rules that would cause churn without value
        'no-descending-specificity': null,
        'selector-class-pattern': null,
        'custom-property-pattern': null,
        'alpha-value-notation': null,
        'color-function-notation': null,
        'value-keyword-case': null,
        'selector-type-case': null,
        'selector-type-no-unknown': null,
        'font-family-name-quotes': null,
        'import-notation': null,
        'declaration-block-no-redundant-longhand-properties': null,
        'comment-empty-line-before': null,
        'rule-empty-line-before': null,
        'at-rule-no-unknown': null,
        'media-feature-range-notation': null,
        'selector-pseudo-class-no-unknown': null,

        // Pre-existing rules — to be addressed in dedicated cleanup-sprint, not in
        // Aurora-v4-zindex-bigbang refactor. Suppressed so refactor lint-gate is meaningful.
        'declaration-block-single-line-max-declarations': null, // 194 pre-existing single-line styles
        'no-duplicate-selectors': null,                          // 49 pre-existing intentional Aurora-Pattern overlaps
        'keyframes-name-pattern': null,                          // 18 pre-existing kebab-case violations
        'declaration-block-no-duplicate-properties': null,       // 11 pre-existing cross-browser fallback patterns
        'declaration-property-value-no-unknown': null,           // 3 pre-existing modern-CSS values not in stylelint vocab
        'property-no-deprecated': null,                          // 2 pre-existing deprecated props as cross-browser fallback
        'media-feature-name-value-no-unknown': null,             // 1 pre-existing modern-media-query
        'declaration-property-value-keyword-no-deprecated': null // 1 pre-existing deprecated keyword as fallback
    }
};
