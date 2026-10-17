import { mountAdminApp } from '../mount';
import { ShippingScreen } from './ShippingScreen';

// The element id matches what AdminPageRegistrar prints for the "shipping" page.
mountAdminApp( 'orillacart-admin-shipping', ShippingScreen );
