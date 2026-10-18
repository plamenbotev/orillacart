import { mountAdminApp } from '../mount';
import { ReportsScreen } from './ReportsScreen';

// The element id matches what AdminPageRegistrar prints for the "reports" page.
mountAdminApp( 'orillacart-admin-reports', ReportsScreen );
