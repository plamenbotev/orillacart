import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { money } from '../orders/labels';
import { useBusy } from '../shared/useBusy';
import {
	fetchImportSchemes,
	saveImportSchemes,
	type ImportRegistration,
	type ImportSchemes,
} from './api';

/**
 * The import schemes the shop sells low-value goods under: with its number there, it charges their VAT or GST at
 * checkout up to the limit; without one, or over it, the goods go as exports and the customer pays at the border.
 * @param props
 * @param props.onSaved
 * @param props.onError
 */
export function ImportSchemesCard( {
	onSaved,
	onError,
}: {
	onSaved: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ schemes, setSchemes ] = useState< ImportSchemes | null >( null );
	const [ draft, setDraft ] = useState<
		Record< string, ImportRegistration >
	>( {} );
	const [ busy, run ] = useBusy();
	// Loaded once: the screen's handler is a new function on each render.
	const failed = useRef( onError );
	failed.current = onError;

	const show = ( loaded: ImportSchemes ) => {
		setSchemes( loaded );
		setDraft(
			Object.fromEntries(
				loaded.schemes.map( ( registration ) => [
					registration.scheme,
					registration,
				] )
			)
		);
	};

	useEffect( () => {
		fetchImportSchemes()
			.then( show )
			.catch( ( error: unknown ) => failed.current( error ) );
	}, [] );

	if ( schemes === null ) {
		return (
			<Card className="orillacart-card">
				<CardBody>
					<Spinner />
				</CardBody>
			</Card>
		);
	}

	const put = ( registration: ImportRegistration ) =>
		setDraft( { ...draft, [ registration.scheme ]: registration } );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ __( 'Import schemes for low-value goods', 'orillacart' ) }
				</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Selling goods into these places from outside them? Registered under a scheme, you charge their VAT or GST at checkout on orders up to its limit, and your number goes on the invoice. Without a number, or over the limit, no tax is charged: the goods go as exports, and checkout tells the customer that import VAT and duties may be due on delivery. Places you do not list are taxed as your settings above say.',
						'orillacart'
					) }
				</p>
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Scheme', 'orillacart' ) }</th>
							<th>{ __( 'Your number there', 'orillacart' ) }</th>
							<th>
								{ sprintf(
									/* translators: %s: currency code */
									__( 'Limit (%s)', 'orillacart' ),
									schemes.shop_currency
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ schemes.known.map( ( known ) => {
							const registration = draft[ known.scheme ];
							const legal = sprintf(
								/* translators: 1: an amount, 2: "per order" or "per item" */
								__( 'The law’s: %1$s %2$s.', 'orillacart' ),
								money( known.legal_limit, known.currency ),
								known.per_item
									? __( 'per item', 'orillacart' )
									: __( 'per order', 'orillacart' )
							);
							return (
								<tr key={ known.scheme }>
									<td>
										<CheckboxControl
											label={ known.label }
											checked={
												registration !== undefined
											}
											onChange={ ( listed ) => {
												if ( listed ) {
													put( {
														scheme: known.scheme,
														number: null,
														limit:
															known.currency ===
															schemes.shop_currency
																? known.legal_limit
																: '',
													} );
												} else {
													const {
														[ known.scheme ]:
															_removed,
														...rest
													} = draft;
													setDraft( rest );
												}
											} }
										/>
									</td>
									<td>
										{ registration !== undefined && (
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Number',
													'orillacart'
												) }
												hideLabelFromVision
												placeholder={ known.example }
												help={ __(
													'Empty: not registered, the goods go as exports.',
													'orillacart'
												) }
												value={
													registration.number ?? ''
												}
												onChange={ ( number ) =>
													put( {
														...registration,
														number:
															number.trim() === ''
																? null
																: number,
													} )
												}
											/>
										) }
									</td>
									<td>
										{ registration !== undefined && (
											<TextControl
												__next40pxDefaultSize
												label={ __(
													'Limit',
													'orillacart'
												) }
												hideLabelFromVision
												help={ legal }
												inputMode="decimal"
												value={ registration.limit }
												onChange={ ( limit ) =>
													put( {
														...registration,
														limit: limit.trim(),
													} )
												}
											/>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () =>
						run( () =>
							saveImportSchemes( Object.values( draft ) )
								.then( show )
								.then( onSaved )
								.catch( onError )
						)
					}
				>
					{ __( 'Save schemes', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
