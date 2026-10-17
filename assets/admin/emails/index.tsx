import { mountAdminApp } from '../mount';
import { EmailsScreen } from './EmailsScreen';

// The element id matches what AdminPageRegistrar prints for the "emails" page.
mountAdminApp( 'orillacart-admin-emails', EmailsScreen );
