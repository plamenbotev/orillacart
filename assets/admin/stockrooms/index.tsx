import { mountAdminApp } from '../mount';
import { StockroomsScreen } from './StockroomsScreen';

// The element id matches what AdminPageRegistrar prints for the "stockrooms" page.
mountAdminApp( 'orillacart-admin-stockrooms', StockroomsScreen );
