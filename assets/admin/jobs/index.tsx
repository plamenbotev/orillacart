import { mountAdminApp } from '../mount';
import { JobsScreen } from './JobsScreen';

// The element id matches what AdminPageRegistrar prints for the "jobs" page.
mountAdminApp( 'orillacart-admin-jobs', JobsScreen );
