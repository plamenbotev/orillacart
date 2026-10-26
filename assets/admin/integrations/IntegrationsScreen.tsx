import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { ApiKeysCard } from './ApiKeysCard';
import { AssistantCard } from './AssistantCard';
import { type AssistantCatalogue, fetchAssistantCatalogue } from './api';
import { WebhooksCard } from './WebhooksCard';

/**
 * Shop → Integrations: how other software works with the shop: an AI assistant and API keys to call it, webhooks
 * it calls.
 */
export function IntegrationsScreen() {
	const [ catalogue, setCatalogue ] = useState< AssistantCatalogue | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		fetchAssistantCatalogue()
			.then( setCatalogue )
			.catch( ( failure ) => setError( errorMessage( failure ) ) );
	}, [] );

	return (
		<>
			<h1>{ __( 'Integrations', 'orillacart' ) }</h1>
			<AssistantCard catalogue={ catalogue } error={ error } />
			<ApiKeysCard catalogue={ catalogue } />
			<WebhooksCard />
		</>
	);
}
