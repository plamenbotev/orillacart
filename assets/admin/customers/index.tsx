import { mountAdminApp } from '../mount';
import { CustomerSettingsScreen } from './CustomerSettingsScreen';
import { CustomersScreen } from './CustomersScreen';

// The element ids match what AdminPageRegistrar prints for the "customers" and "customer-settings" pages, both
// served by this script.
mountAdminApp( 'orillacart-admin-customers', CustomersScreen );
mountAdminApp( 'orillacart-admin-customer-settings', CustomerSettingsScreen );
