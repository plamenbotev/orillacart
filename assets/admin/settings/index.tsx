import { mountAdminApp } from '../mount';
import { SettingsScreen } from './SettingsScreen';

// The element id matches what AdminPageRegistrar prints for the "settings" page.
mountAdminApp( 'orillacart-admin-settings', SettingsScreen );
