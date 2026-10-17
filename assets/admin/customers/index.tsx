import { mountAdminApp } from '../mount';
import { CustomersScreen } from './CustomersScreen';

// The element id matches what AdminPageRegistrar prints for the "customers" page.
mountAdminApp( 'orillacart-admin-customers', CustomersScreen );
