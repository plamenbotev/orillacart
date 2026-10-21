import { __ } from '@wordpress/i18n';

import { ApiKeysCard } from './ApiKeysCard';

/**
 * Shop → Integrations: how other software works with the shop.
 */
export function IntegrationsScreen() {
	return (
		<>
			<h1>{ __( 'Integrations', 'orillacart' ) }</h1>
			<ApiKeysCard />
		</>
	);
}
