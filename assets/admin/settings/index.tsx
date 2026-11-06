import { mountAdminApp } from '../mount';
import { AdvancedScreen } from './AdvancedScreen';
import { FiltersScreen } from './FiltersScreen';
import { InvoicesScreen } from './InvoicesScreen';
import { PagesScreen } from './PagesScreen';
import { SettingsScreen } from './SettingsScreen';
import { StorefrontScreen } from './StorefrontScreen';

// One script serves the Settings tabs and Catalog's "Filters and search": the element ids match what
// AdminPageRegistrar prints for each page, and only the page's own element is on its screen.
mountAdminApp( 'orillacart-admin-settings', SettingsScreen );
mountAdminApp( 'orillacart-admin-pages', PagesScreen );
mountAdminApp( 'orillacart-admin-invoices', InvoicesScreen );
mountAdminApp( 'orillacart-admin-storefront', StorefrontScreen );
mountAdminApp( 'orillacart-admin-advanced', AdvancedScreen );
mountAdminApp( 'orillacart-admin-filters', FiltersScreen );
