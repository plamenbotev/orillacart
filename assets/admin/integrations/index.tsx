import { mountAdminApp } from '../mount';
import { IntegrationsScreen } from './IntegrationsScreen';

// The element id matches what AdminPageRegistrar prints for the "integrations" page.
mountAdminApp( 'orillacart-admin-integrations', IntegrationsScreen );
