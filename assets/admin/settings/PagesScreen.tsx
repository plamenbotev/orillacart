import { CheckboxControl, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import {
	fetchLegalSettings,
	fetchPageChoices,
	fetchShopPages,
	type LegalSettings,
	type PageChoice,
	saveLegalSettings,
	saveShopPages,
	type ShopPages,
} from './pages';
import { Field, Page, SaveBar, Section, Skeleton, useSave } from '../shared/ui';

const PARTS: { key: keyof ShopPages; label: string; help: string }[] = [
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

type LegalPageKey =
	'terms' | 'withdrawal' | 'withdrawal_form' | 'delivery' | 'legal_notice';

const LEGAL_PAGES: { key: LegalPageKey; label: string; help: string }[] = [
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

const BUTTONS: Record< string, string > = {
	place_order: __( 'Place order', 'orillacart' ),
	buy_now: __( 'Buy now', 'orillacart' ),
	order_and_pay: __( 'Order with obligation to pay', 'orillacart' ),
};

interface Form {
	pages: ShopPages;
	legal: LegalSettings;
}

const FORM_ID = 'orillacart-pages';

/**
 * Which WordPress page each part of the storefront is on, the pages the law asks a shop to show, and what
 * checkout asks before an order. One form: its button saves whichever part changed.
 */
export function PagesScreen() {
	const [ saved, setSaved ] = useState< Form | null >( null );
	const [ form, setForm ] = useState< Form | null >( null );
	/** Published pages for the shop's parts; drafts too for the legal ones, written before they are published. */
	const [ choices, setChoices ] = useState< {
		published: PageChoice[];
		all: PageChoice[];
	} >( { published: [], all: [] } );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, save ] = useSave( ( failure ) =>
		setError( errorMessage( failure ) )
	);
	const dirty = form !== null && saved !== null && differs( form, saved );
	useUnsavedChanges( dirty );

	useEffect( () => {
		Promise.all( [
			fetchShopPages(),
			fetchLegalSettings(),
			fetchPageChoices(),
			fetchPageChoices( true ),
		] )
			.then( ( [ pages, legal, published, all ] ) => {
				const loaded = { pages, legal };
				setSaved( loaded );
				setForm( loaded );
				setChoices( { published, all } );
			} )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const options = ( list: PageChoice[] ) => [
		{ label: __( '— None —', 'orillacart' ), value: '' },
		...list.map( ( page ) => ( {
			label: page.title || `#${ page.id }`,
			value: String( page.id ),
		} ) ),
	];
	const idOf = ( value: string ) => ( value === '' ? null : Number( value ) );
	const valueOf = ( id: number | null ) =>
		id === null ? '' : String( id );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null || saved === null ) {
			return;
		}
		setError( null );
		void save(
			async () => {
				const pages = differs( form.pages, saved.pages )
					? await saveShopPages( form.pages )
					: saved.pages;
				const legal = differs( form.legal, saved.legal )
					? await saveLegalSettings( form.legal )
					: saved.legal;
				const next = { pages, legal };
				setSaved( next );
				setForm( next );
			},
			__( 'Pages saved.', 'orillacart' )
		);
	};

	return (
		<Page
			title={ __( 'Pages and legal', 'orillacart' ) }
			error={ error }
			onDismissError={ () => setError( null ) }
		>
			{ form === null ? (
				error === null && (
					<Skeleton lines={ 3 } widths={ [ '60%', '90%', '40%' ] } />
				)
			) : (
				<form id={ FORM_ID } onSubmit={ submit }>
					<Section
						title={ __( 'Shop pages', 'orillacart' ) }
						description={ __(
							'The WordPress page each part of the shop is on. The setup made them; choose others to move a part.',
							'orillacart'
						) }
					>
						{ PARTS.map( ( part ) => (
							<Field key={ part.key } size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ part.label }
									help={ part.help }
									value={ valueOf( form.pages[ part.key ] ) }
									options={ options( choices.published ) }
									onChange={ ( value ) =>
										setForm( {
											...form,
											pages: {
												...form.pages,
												[ part.key ]: idOf( value ),
											},
										} )
									}
								/>
							</Field>
						) ) }
					</Section>
					<Section
						title={ __( 'Legal pages', 'orillacart' ) }
						description={ __(
							'The pages the law asks a shop to show. Checkout and the site’s foot link them; drafts can be chosen while they are written.',
							'orillacart'
						) }
					>
						{ LEGAL_PAGES.map( ( page ) => (
							<Field key={ page.key } size="m">
								<SelectControl
									__next40pxDefaultSize
									label={ page.label }
									help={ page.help }
									value={ valueOf( form.legal[ page.key ] ) }
									options={ options( choices.all ) }
									onChange={ ( value ) =>
										setForm( {
											...form,
											legal: {
												...form.legal,
												[ page.key ]: idOf( value ),
											},
										} )
									}
								/>
							</Field>
						) ) }
					</Section>
					<Section
						title={ __( 'At checkout', 'orillacart' ) }
						description={ __(
							'What checkout asks of the customer before an order.',
							'orillacart'
						) }
					>
						<CheckboxControl
							label={ __(
								'Checkout asks customers to accept the terms of sale',
								'orillacart'
							) }
							checked={ form.legal.accept_terms }
							onChange={ ( accept ) =>
								setForm( {
									...form,
									legal: {
										...form.legal,
										accept_terms: accept,
									},
								} )
							}
						/>
						<Field size="m">
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
								value={ form.legal.order_button }
								options={ Object.entries( BUTTONS ).map(
									( [ value, label ] ) => ( { value, label } )
								) }
								onChange={ ( button ) =>
									setForm( {
										...form,
										legal: {
											...form.legal,
											order_button: button,
										},
									} )
								}
							/>
						</Field>
					</Section>
				</form>
			) }
			{ form !== null && (
				<SaveBar
					dirty={ dirty }
					busy={ busy }
					form={ FORM_ID }
					label={ __( 'Save pages', 'orillacart' ) }
				/>
			) }
		</Page>
	);
}
