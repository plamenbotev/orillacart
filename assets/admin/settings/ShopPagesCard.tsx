import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import {
	fetchPageChoices,
	fetchShopPages,
	saveShopPages,
	type PageChoice,
	type ShopPages,
} from './pages';

type Status =
	| { kind: 'idle' }
	| { kind: 'saving' }
	| { kind: 'saved' }
	| { kind: 'error'; message: string };

const parts: { key: keyof ShopPages; label: string; help: string }[] = [
	{
		key: 'shop',
		label: __( 'Shop', 'orillacart' ),
		help: __(
			'Lists the products. Holds the Products block.',
			'orillacart'
		),
	},
	{
		key: 'cart',
		label: __( 'Cart', 'orillacart' ),
		help: __(
			'Holds the Cart block. Without it, products cannot be bought.',
			'orillacart'
		),
	},
	{
		key: 'checkout',
		label: __( 'Checkout', 'orillacart' ),
		help: __(
			'Holds the Checkout block; receipts are shown there too.',
			'orillacart'
		),
	},
	{
		key: 'account',
		label: __( 'Account', 'orillacart' ),
		help: __( 'Holds the Customer account block.', 'orillacart' ),
	},
];

/**
 * Which WordPress page each part of the storefront is on.
 */
export function ShopPagesCard() {
	const [ form, setForm ] = useState< ShopPages | null >( null );
	const [ choices, setChoices ] = useState< PageChoice[] >( [] );
	const [ status, setStatus ] = useState< Status >( { kind: 'idle' } );

	useEffect( () => {
		Promise.all( [ fetchShopPages(), fetchPageChoices() ] )
			.then( ( [ pages, list ] ) => {
				setForm( pages );
				setChoices( list );
			} )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: errorMessage( error ),
				} )
			);
	}, [] );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null ) {
			return;
		}
		setStatus( { kind: 'saving' } );
		saveShopPages( form )
			.then( ( saved ) => {
				setForm( saved );
				setStatus( { kind: 'saved' } );
			} )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: errorMessage( error ),
				} )
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
				<h2>{ __( 'Shop pages', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ status.kind === 'error' && (
					<Notice status="error" isDismissible={ false }>
						{ status.message }
					</Notice>
				) }
				{ status.kind === 'saved' && (
					<Notice status="success" isDismissible={ false }>
						{ __( 'Shop pages saved.', 'orillacart' ) }
					</Notice>
				) }
				{ form === null ? (
					status.kind !== 'error' && <Spinner />
				) : (
					<form onSubmit={ submit }>
						{ parts.map( ( part ) => (
							<SelectControl
								key={ part.key }
								__next40pxDefaultSize
								label={ part.label }
								help={ part.help }
								value={
									form[ part.key ] === null
										? ''
										: String( form[ part.key ] )
								}
								options={ options }
								onChange={ ( value ) =>
									setForm(
										( current ) =>
											current && {
												...current,
												[ part.key ]:
													value === ''
														? null
														: Number( value ),
											}
									)
								}
							/>
						) ) }
						<Button
							variant="primary"
							type="submit"
							isBusy={ status.kind === 'saving' }
							disabled={ status.kind === 'saving' }
						>
							{ __( 'Save shop pages', 'orillacart' ) }
						</Button>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
