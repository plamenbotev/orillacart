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

/**
 * What product pages tell search engines (structured data) and social sites (link previews). Each choice saves
 * at once.
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
				<h2>{ __( 'Search engines and sharing', 'orillacart' ) }</h2>
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
					</>
				) }
			</CardBody>
		</Card>
	);
}
