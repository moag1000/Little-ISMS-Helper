/**
 * Turbo confirm method backed by the Aurora dialog (window.faConfirm).
 *
 * Turbo calls it for every link/form carrying `data-turbo-confirm` and expects
 * a Promise<boolean>. Destructive intents (DELETE method or a danger-styled
 * trigger) get the danger tone. The native dialog is only a fallback for the
 * moment before fa-alerts.js has registered window.faConfirm.
 */
export function auroraTurboConfirm(message, element, submitter) {
    const trigger = submitter || element;
    const method = (trigger?.getAttribute?.('data-turbo-method')
        || element?.getAttribute?.('method')
        || '').toLowerCase();
    const danger = method === 'delete'
        || trigger?.classList?.contains('text-danger')
        || trigger?.classList?.contains('fa-cyber-btn--danger');

    if (typeof window.faConfirm === 'function') {
        return window.faConfirm(message, { tone: danger ? 'danger' : 'warn' });
    }
    return Promise.resolve(window.confirm(message)); // fallback: helper not mounted yet
}
