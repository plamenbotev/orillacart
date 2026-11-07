import { mountAdminApp } from '../mount';
import { ExtensionsScreen } from './ExtensionsScreen';

// The element id matches what AdminPageRegistrar prints for the "extensions" page.
mountAdminApp( 'orillacart-admin-extensions', ExtensionsScreen );
