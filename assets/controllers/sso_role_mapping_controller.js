import { Controller } from '@hotwired/stimulus';

/**
 * Stimulus controller for the role-mapping collection editor on the IdP show page.
 *
 * Usage:
 *   <div data-controller="sso-role-mapping">
 *     <div data-sso-role-mapping-target="collection">
 *       {# existing rows #}
 *     </div>
 *     <button type="button" data-action="sso-role-mapping#addRow">Add mapping</button>
 *   </div>
 *
 * Each row must have class "role-mapping-row" and contain a
 * data-action="sso-role-mapping#removeRow" remove button.
 * Row inputs follow the naming convention: role_mappings[N][fieldName].
 */
export default class extends Controller {
    static targets = ['collection'];

    connect() {
        this._index = this.collectionTarget.querySelectorAll('.role-mapping-row').length;
    }

    addRow(event) {
        event.preventDefault();
        const idx = this._index++;
        const row = this._buildRow(idx);
        this.collectionTarget.appendChild(row);
    }

    removeRow(event) {
        event.preventDefault();
        const row = event.currentTarget.closest('.role-mapping-row');
        if (row) {
            row.remove();
        }
    }

    _buildRow(idx) {
        const div = document.createElement('div');
        div.className = 'role-mapping-row border rounded p-3 mb-2 bg-body-secondary';
        div.innerHTML = `
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <input type="text" name="role_mappings[${idx}][claimKey]"
                           class="form-control form-control-sm" placeholder="groups"
                           aria-label="Claim key">
                </div>
                <div class="col-md-3">
                    <input type="text" name="role_mappings[${idx}][claimValueExpression]"
                           class="form-control form-control-sm" placeholder="isms-admin"
                           aria-label="Claim value expression">
                </div>
                <div class="col-md-2">
                    <input type="text" name="role_mappings[${idx}][assignedRole]"
                           class="form-control form-control-sm" placeholder="ROLE_ADMIN"
                           aria-label="Assigned role">
                </div>
                <div class="col-md-1">
                    <input type="number" name="role_mappings[${idx}][priority]"
                           class="form-control form-control-sm" value="0" min="0"
                           aria-label="Priority">
                </div>
                <div class="col-md-1 d-flex align-items-center">
                    <input type="checkbox" name="role_mappings[${idx}][isActive]"
                           value="1" checked class="form-check-input"
                           aria-label="Active">
                </div>
                <div class="col-md-2">
                    <input type="text" name="role_mappings[${idx}][auditDescription]"
                           class="form-control form-control-sm" placeholder="Description"
                           aria-label="Audit description">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            data-action="sso-role-mapping#removeRow"
                            aria-label="Remove mapping">
                        <i class="fa-icon fa-icon--ui-trash" aria-hidden="true"></i>
                    </button>
                </div>
            </div>`;
        return div;
    }
}
