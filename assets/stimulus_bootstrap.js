import { startStimulusApp } from '@symfony/stimulus-bundle';
import AsyncJobController from './controllers/async_job_controller.js';
import WizardBusyController from './controllers/wizard_busy_controller.js';
import AlvaDockController from './controllers/alva_dock_controller.js';
import AlvaHintController from './controllers/alva_hint_controller.js';
import SelectAllController from './controllers/select_all_controller.js';
import AdminHubSearchController from './controllers/admin_hub_search_controller.js';
import ApiKeyRevealController from './controllers/api_key_reveal_controller.js';
import FaConfirmController from './controllers/fa_confirm_controller.js';
import FaModalController from './controllers/fa_modal_controller.js';
import FaDrawerController from './controllers/fa_drawer_controller.js';
import FaModalDispatcherController from './controllers/fa_modal_dispatcher_controller.js';
import MegaMenuController from './controllers/mega_menu_controller.js';
import AuroraDropdownController from './controllers/aurora_dropdown_controller.js';
import PreferencesController from './controllers/preferences_controller.js';
import BestandsaufnahmeBulkController from './controllers/bestandsaufnahme_bulk_controller.js';
import BestandsaufnahmeDrawerController from './controllers/bestandsaufnahme_drawer_controller.js';
import BestandsaufnahmeRowController from './controllers/bestandsaufnahme_row_controller.js';
import FormLayoutController from './controllers/form_layout_controller.js';
import ModalWizardController from './controllers/modal_wizard_controller.js';
import TabsController from './controllers/tabs_controller.js';
import TsFindingPickerController from './controllers/ts_finding_picker_controller.js';
import BulkApprovalMobileController from './controllers/bulk_approval_mobile_controller.js';
import PolicyWizardPresetPickerController from './controllers/policy_wizard_preset_picker_controller.js';
import PolicyWizardTargetedPickController from './controllers/policy_wizard_targeted_pick_controller.js';
import AnnexAFilterController from './controllers/annex_a_filter_controller.js';
import FaToastController from './controllers/fa_toast_controller.js';
import FaBulkSelectController from './controllers/fa_bulk_select_controller.js';
import FaTableSortController from './controllers/fa_table_sort_controller.js';
import ReauthModalController from './controllers/reauth_modal_controller.js';
import QuickCreateModalController from './controllers/quick_create_modal_controller.js';
import PersonaSwitcherController from './controllers/persona_switcher_controller.js';

const app = startStimulusApp();

// Disable debug mode to reduce console logs
app.debug = false;

// Explicit registration — stimulus-bundle's auto-discovery sometimes
// misses newly-added controllers until asset-map:compile re-generates
// the manifest in prod. Explicit registration guarantees activation
// on every deploy without manifest churn.
app.register('async-job', AsyncJobController);
app.register('fa-drawer', FaDrawerController);
app.register('fa-form-modal', FaDrawerController); // same generic frame-overlay controller
app.register('wizard-busy', WizardBusyController);
app.register('alva-dock', AlvaDockController);
app.register('alva-hint', AlvaHintController);
app.register('select-all', SelectAllController);
app.register('admin-hub-search', AdminHubSearchController);
app.register('api-key-reveal', ApiKeyRevealController);
app.register('fa-confirm', FaConfirmController);
app.register('fa-modal', FaModalController);
app.register('fa-modal-dispatcher', FaModalDispatcherController);
app.register('mega-menu', MegaMenuController);
app.register('aurora-dropdown', AuroraDropdownController);
app.register('preferences', PreferencesController);
app.register('bestandsaufnahme-bulk', BestandsaufnahmeBulkController);
app.register('bestandsaufnahme-drawer', BestandsaufnahmeDrawerController);
app.register('bestandsaufnahme-row', BestandsaufnahmeRowController);
app.register('form-layout', FormLayoutController);
app.register('modal-wizard', ModalWizardController);
app.register('tabs', TabsController);
app.register('ts-finding-picker', TsFindingPickerController);
app.register('bulk-approval-mobile', BulkApprovalMobileController);
app.register('policy-wizard-preset-picker', PolicyWizardPresetPickerController);
app.register('policy-wizard-targeted-pick', PolicyWizardTargetedPickController);
app.register('annex-a-filter', AnnexAFilterController);
app.register('fa-toast', FaToastController);
app.register('fa-bulk-select', FaBulkSelectController);
app.register('fa-table-sort', FaTableSortController);
app.register('reauth-modal', ReauthModalController);
app.register('quick-create-modal', QuickCreateModalController);
app.register('persona-switcher', PersonaSwitcherController);
