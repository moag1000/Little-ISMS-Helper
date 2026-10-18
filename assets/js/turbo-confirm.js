/**
 * Turbo confirm method backed by the Aurora dialog (window.faConfirm).
 *
 * Turbo calls it for every link/form carrying `data-turbo-confirm` and expects
 * a Promise<boolean>. Destructive intents (DELETE method or a danger-styled
 * trigger) get the danger tone.
 */
import { faConfirm } from './fa-alerts.js';

export function auroraTurboConfirm(message, element, submitter) {
    const trigger = submitter || element;
    const method = (trigger?.getAttribute?.('data-turbo-method')
        || element?.getAttribute?.('method')
        || '').toLowerCase();
    const danger = method === 'delete'
        || trigger?.classList?.contains('text-danger')
        || trigger?.classList?.contains('fa-cyber-btn--danger');

    return faConfirm(message, { tone: danger ? 'danger' : 'warn' });
}
