import { mountAdminApp } from '../mount';
import { StockPage } from './StockPage';

// The element id matches what AdminPageRegistrar prints for the "stock" page.
mountAdminApp( 'orillacart-admin-stock', StockPage );
