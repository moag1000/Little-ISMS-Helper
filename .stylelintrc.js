module.exports = {
    extends: ['stylelint-config-standard'],
    ignoreFiles: [
        'assets/styles/fairy-aurora.css',        // Token SoT — defines hex
        'assets/styles/alva.css',                // SVG brand fills legitimate
        'assets/styles/bootstrap*.css',          // Vendor
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
        'selector-pseudo-class-no-unknown': null
    }
};
