import {
	Card,
	CardBody,
	CardHeader,
	Notice,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import {
	type AgentCommerce,
	fetchAgentCommerce,
	saveAgentCommerce,
} from './api';

/**
 * Shoppers' AI agents (design 75): agents that shop for people find the shop's products and prepare a checkout
 * through the Universal Commerce Protocol; the buyer pays at the shop's own checkout, through the link the agent
 * hands them.
 */
export function AgentsCard() {
	const [ state, setState ] = useState< AgentCommerce | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		fetchAgentCommerce()
			.then( setState )
			.catch( ( failure ) => setError( errorMessage( failure ) ) );
	}, [] );

	const toggle = ( on: boolean ) => {
		setSaving( true );
		setError( null );
		saveAgentCommerce( on )
			.then( setState )
			.catch( ( failure ) => setError( errorMessage( failure ) ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Shoppers’ AI agents (UCP)', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'AI agents that shop for people can find your products and prepare an order through the Universal Commerce Protocol. The buyer always pays at your own checkout, through a link the agent gives them: your terms, payment methods and emails, as for any order.',
						'orillacart'
					) }
				</p>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ state !== null && (
					<>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Let shoppers’ agents buy here',
								'orillacart'
							) }
							checked={ state.on }
							disabled={ saving }
							onChange={ toggle }
						/>
						{ state.on && (
							<p>
								{ __(
									'Agents find the shop at',
									'orillacart'
								) }{ ' ' }
								<code>{ state.profile }</code>
								<br />
								{ __(
									'Protocol version:',
									'orillacart'
								) }{ ' ' }
								<code>{ state.version }</code>
							</p>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}
