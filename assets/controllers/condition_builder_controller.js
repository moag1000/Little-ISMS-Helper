import { Controller } from '@hotwired/stimulus';

/**
 * condition-builder — Aurora visual rule-builder for notification rules etc.
 *
 * Used by the `_fa_condition_builder.html.twig` macro. Reads field + operator
 * catalogues from JSON-encoded `data-*-value` attributes and lets the user
 * build a list of `{field, operator, value}` chips that serialise back into
 * hidden `<input name="{name}[]">` fields for form-submit.
 *
 * @todo H-12 — chip-add UX is currently not implemented (the macro isn't
 *              wired to any production form yet). When the first form adopts
 *              the macro, flesh this out with real add/remove/serialise.
 *              For now this stub keeps the controller registration alive so
 *              Stimulus does not warn about a missing controller.
 */
export default class extends Controller {
    static values = {
        fields: { type: Array, default: [] },
        operators: { type: Array, default: [] },
        name: { type: String, default: 'conditions' },
    };

    static targets = ['chips'];

    add(event) {
        event?.preventDefault?.();
        // No-op stub — see @todo above.
    }
}
