import { mountAdminApp } from '../mount';
import { TaxScreen } from './TaxScreen';

// The element id matches what AdminPageRegistrar prints for the "tax" page.
mountAdminApp( 'orillacart-admin-tax', TaxScreen );
