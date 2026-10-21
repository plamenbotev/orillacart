import { mountAdminApp } from '../mount';
import { SetupScreen } from './SetupScreen';

// The element id matches what AdminPageRegistrar prints for the "setup" page.
mountAdminApp( 'orillacart-admin-setup', SetupScreen );
