import { startStimulusApp } from '@symfony/stimulus-bundle';
import AsyncJobController from './controllers/async_job_controller.js';
import WizardBusyController from './controllers/wizard_busy_controller.js';
import AlvaDockController from './controllers/alva_dock_controller.js';
import SelectAllController from './controllers/select_all_controller.js';

const app = startStimulusApp();

// Disable debug mode to reduce console logs
app.debug = false;

// Explicit registration — stimulus-bundle's auto-discovery sometimes
// misses newly-added controllers until asset-map:compile re-generates
// the manifest in prod. Explicit registration guarantees activation
// on every deploy without manifest churn.
app.register('async-job', AsyncJobController);
app.register('wizard-busy', WizardBusyController);
app.register('alva-dock', AlvaDockController);
app.register('select-all', SelectAllController);
