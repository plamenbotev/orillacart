import { mountAdminApp } from '../mount';
import { HomeScreen } from './HomeScreen';

// The element id matches what AdminPageRegistrar prints for the "home" page.
mountAdminApp( 'orillacart-admin-home', HomeScreen );
