import { mountAdminApp } from '../mount';
import { AutomaticDiscountsScreen, PriceRulesScreen } from './PriceRulesScreen';

// The element ids match what AdminPageRegistrar prints for the "price-rules" and "automatic-discounts" pages,
// both served by this script.
mountAdminApp( 'orillacart-admin-price-rules', PriceRulesScreen );
mountAdminApp(
	'orillacart-admin-automatic-discounts',
	AutomaticDiscountsScreen
);
