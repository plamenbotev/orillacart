import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	fetchStorefrontSettings,
	saveStorefrontSettings,
	type StorefrontSettings,
} from './storefront';

const SHARING = [
	{
		value: 'auto',
		label: __( 'Unless an SEO plugin adds them', 'orillacart' ),
	},
	{ value: 'always', label: __( 'Always', 'orillacart' ) },
	{ value: 'never', label: __( 'Never', 'orillacart' ) },
];

const WISHLISTS = [
	{
		value: 'guests',
		label: __(
			'Everyone: visitors’ wishlists stay in their browser and join their account when they log in',
			'orillacart'
		),
	},
	{
		value: 'accounts',
		label: __( 'Customers with an account', 'orillacart' ),
	},
	{ value: 'off', label: __( 'No wishlists', 'orillacart' ) },
];

/**
 * What product pages tell search engines (structured data) and social sites (link previews), and what the shop
 * keeps for shoppers: wishlists and the products they viewed. Each choice saves at once.
 */
export function StorefrontCard() {
	const [ settings, setSettings ] = useState< StorefrontSettings | null >(
		null
	);
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: toApiError( failure ).message } );

	useEffect( () => {
		fetchStorefrontSettings().then( setSettings ).catch( fail );
	}, [] );

	const save = ( changed: Partial< StorefrontSettings > ) =>
		settings &&
		saveStorefrontSettings( { ...settings, ...changed } )
			.then( ( saved ) => {
				setSettings( saved );
				setMessage( {
					status: 'success',
					text: __( 'Saved.', 'orillacart' ),
				} );
			} )
			.catch( fail );

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Storefront', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				{ settings === null ? (
					message === null && <Spinner />
				) : (
					<>
						<CheckboxControl
							label={ __(
								'Describe products to search engines',
								'orillacart'
							) }
							help={ __(
								'Structured data (schema.org) with the price, stock, barcode, brand, stars and reviews, for rich results in Google and others.',
								'orillacart'
							) }
							checked={ settings.structured_data }
							onChange={ ( checked ) =>
								save( { structured_data: checked } )
							}
						/>
						<RadioControl
							label={ __(
								'Link previews on social sites and in chat apps (Open Graph)',
								'orillacart'
							) }
							selected={ settings.sharing_tags }
							options={ SHARING }
							onChange={ ( sharing ) =>
								save( {
									sharing_tags:
										sharing as StorefrontSettings[ 'sharing_tags' ],
								} )
							}
						/>
						<RadioControl
							label={ __(
								'Who may keep a wishlist',
								'orillacart'
							) }
							selected={ settings.wishlist }
							options={ WISHLISTS }
							onChange={ ( wishlist ) =>
								save( {
									wishlist:
										wishlist as StorefrontSettings[ 'wishlist' ],
								} )
							}
						/>
						<CheckboxControl
							label={ __(
								'Leave out a shop filter with a single value',
								'orillacart'
							) }
							help={ __(
								'When every product in view has the same value for a filter, such as all lamps in Glass, the filter narrows nothing and is not shown.',
								'orillacart'
							) }
							checked={ settings.hide_single_value_filters }
							onChange={ ( checked ) =>
								save( { hide_single_value_filters: checked } )
							}
						/>
						<CheckboxControl
							label={ __(
								'Show the products a shopper viewed lately',
								'orillacart'
							) }
							help={ __(
								'Under each product, and wherever the Recently viewed products block is. Their browser keeps the list.',
								'orillacart'
							) }
							checked={ settings.recently_viewed }
							onChange={ ( checked ) =>
								save( { recently_viewed: checked } )
							}
						/>
						<CheckboxControl
							label={ __(
								'Send the shop’s events to Google Tag Manager',
								'orillacart'
							) }
							help={ __(
								'Products viewed, added to the cart, checkouts begun and orders placed go to the dataLayer as Google Analytics 4 ecommerce events. Add the Tag Manager container with its own plugin.',
								'orillacart'
							) }
							checked={ settings.data_layer }
							onChange={ ( checked ) =>
								save( { data_layer: checked } )
							}
						/>
					</>
				) }
			</CardBody>
		</Card>
	);
}
