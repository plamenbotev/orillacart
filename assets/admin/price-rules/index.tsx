import { mountAdminApp } from '../mount';
import { PriceRulesScreen } from './PriceRulesScreen';

// The element id matches what AdminPageRegistrar prints for the "price-rules" page.
mountAdminApp( 'orillacart-admin-price-rules', PriceRulesScreen );
