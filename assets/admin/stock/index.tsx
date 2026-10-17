import { mountAdminApp } from '../mount';
import { StockScreen } from './StockScreen';

// The element id matches what AdminPageRegistrar prints for the "stock" page.
mountAdminApp( 'orillacart-admin-stock', StockScreen );
