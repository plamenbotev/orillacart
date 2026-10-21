import { __ } from '@wordpress/i18n';

import { ApiKeysCard } from './ApiKeysCard';
import { WebhooksCard } from './WebhooksCard';

/**
 * Shop → Integrations: how other software works with the shop: API keys to call it, webhooks it calls.
 */
export function IntegrationsScreen() {
	return (
		<>
			<h1>{ __( 'Integrations', 'orillacart' ) }</h1>
			<ApiKeysCard />
			<WebhooksCard />
		</>
	);
}
