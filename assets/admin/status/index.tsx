import { mountAdminApp } from '../mount';
import { StatusScreen } from './StatusScreen';

// The element id matches what AdminPageRegistrar prints for the "status" page.
mountAdminApp( 'orillacart-admin-status', StatusScreen );
