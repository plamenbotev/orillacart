import { mountAdminApp } from '../mount';
import { CouponsScreen } from './CouponsScreen';

// The element id matches what AdminPageRegistrar prints for the "coupons" page.
mountAdminApp( 'orillacart-admin-coupons', CouponsScreen );
