import { mountAdminApp } from '../mount';
import { OrdersScreen } from './OrdersScreen';

// The element id matches what AdminPageRegistrar prints for the "orders" page.
mountAdminApp( 'orillacart-admin-orders', OrdersScreen );
