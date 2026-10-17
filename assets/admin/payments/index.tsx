import { mountAdminApp } from '../mount';
import { PaymentsScreen } from './PaymentsScreen';

// The element id matches what AdminPageRegistrar prints for the "payments" page.
mountAdminApp( 'orillacart-admin-payments', PaymentsScreen );
