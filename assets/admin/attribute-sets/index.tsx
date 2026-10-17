import { mountAdminApp } from '../mount';
import { AttributeSetsScreen } from './AttributeSetsScreen';

// The element id matches what AdminPageRegistrar prints for the "attribute-sets" page.
mountAdminApp( 'orillacart-admin-attribute-sets', AttributeSetsScreen );
