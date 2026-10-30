import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { useSavedCopy } from '../shared/useUnsavedChanges';
import {
	fetchLegalSettings,
	fetchPageChoices,
	saveLegalSettings,
	type LegalSettings,
	type PageChoice,
} from './pages';

type Status =
	| { kind: 'idle' }
	| { kind: 'saving' }
	| { kind: 'saved' }
	| { kind: 'error'; message: string };

type PageKey =
	'terms' | 'withdrawal' | 'withdrawal_form' | 'delivery' | 'legal_notice';

const pages: { key: PageKey; label: string; help: string }[] = [
	{
		key: 'terms',
		label: __( 'Terms of sale', 'orillacart' ),
		help: __(
			'Checkout links them, and asks for them to be accepted when you choose so below.',
			'orillacart'
		),
	},
	{
		key: 'withdrawal',
		label: __( 'Right of withdrawal', 'orillacart' ),
		help: __(
			'Checkout links it below the order button. In the EU and the UK it is required.',
			'orillacart'
		),
	},
	{
		key: 'withdrawal_form',
		label: __( 'Withdraw from contract', 'orillacart' ),
		help: __(
			'The page with the Withdraw from contract block. Every page links it at its foot. In the EU it is required.',
			'orillacart'
		),
	},
	{
		key: 'delivery',
		label: __( 'Shipping and payment', 'orillacart' ),
		help: __(
			'Where the shop ships, what it costs and how customers pay.',
			'orillacart'
		),
	},
	{
		key: 'legal_notice',
		label: __( 'Legal notice', 'orillacart' ),
		help: __(
			'Who sells: name, address, registration and VAT number (Impressum).',
			'orillacart'
		),
	},
];

const buttons: Record< string, string > = {
	place_order: __( 'Place order', 'orillacart' ),
	buy_now: __( 'Buy now', 'orillacart' ),
	order_and_pay: __( 'Order with obligation to pay', 'orillacart' ),
};

/**
 * The pages the law asks a shop to show, and what checkout asks before an order.
 */
export function LegalCard() {
	const [ form, setForm ] = useState< LegalSettings | null >( null );
	const [ choices, setChoices ] = useState< PageChoice[] >( [] );
	const [ status, setStatus ] = useState< Status >( { kind: 'idle' } );
	const loaded = useSavedCopy( form );

	useEffect( () => {
		Promise.all( [ fetchLegalSettings(), fetchPageChoices( true ) ] )
			.then( ( [ settings, list ] ) => {
				setForm( settings );
				loaded( settings );
				setChoices( list );
			} )
			.catch( ( error: unknown ) =>
				setStatus( { kind: 'error', message: errorMessage( error ) } )
			);
	}, [ loaded ] );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null ) {
			return;
		}
		setStatus( { kind: 'saving' } );
		saveLegalSettings( form )
			.then( ( saved ) => {
				setForm( saved );
				loaded( saved );
				setStatus( { kind: 'saved' } );
			} )
			.catch( ( error: unknown ) =>
				setStatus( { kind: 'error', message: errorMessage( error ) } )
			);
	};

	const options = [
		{ label: __( '— None —', 'orillacart' ), value: '' },
		...choices.map( ( page ) => ( {
			label: page.title || `#${ page.id }`,
			value: String( page.id ),
		} ) ),
	];

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Legal', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ status.kind === 'error' && (
					<Notice status="error" isDismissible={ false }>
						{ status.message }
					</Notice>
				) }
				{ status.kind === 'saved' && (
					<Notice status="success" isDismissible={ false }>
						{ __( 'Legal settings saved.', 'orillacart' ) }
					</Notice>
				) }
				{ form === null ? (
					status.kind !== 'error' && <Spinner />
				) : (
					<form onSubmit={ submit }>
						{ pages.map( ( page ) => (
							<SelectControl
								key={ page.key }
								__next40pxDefaultSize
								label={ page.label }
								help={ page.help }
								value={
									form[ page.key ] === null
										? ''
										: String( form[ page.key ] )
								}
								options={ options }
								onChange={ ( value ) =>
									setForm(
										( current ) =>
											current && {
												...current,
												[ page.key ]:
													value === ''
														? null
														: Number( value ),
											}
									)
								}
							/>
						) ) }
						<CheckboxControl
							label={ __(
								'Checkout asks customers to accept the terms of sale',
								'orillacart'
							) }
							checked={ form.accept_terms }
							onChange={ ( accept ) =>
								setForm(
									( current ) =>
										current && {
											...current,
											accept_terms: accept,
										}
								)
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							label={ __(
								'The order button says',
								'orillacart'
							) }
							help={ __(
								'In the EU it must say that ordering means paying: "Buy now" or "Order with obligation to pay".',
								'orillacart'
							) }
							value={ form.order_button }
							options={ Object.entries( buttons ).map(
								( [ value, label ] ) => ( { value, label } )
							) }
							onChange={ ( button ) =>
								setForm(
									( current ) =>
										current && {
											...current,
											order_button: button,
										}
								)
							}
						/>
						<Button
							variant="primary"
							type="submit"
							isBusy={ status.kind === 'saving' }
							disabled={ status.kind === 'saving' }
						>
							{ __( 'Save legal settings', 'orillacart' ) }
						</Button>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
