import {
	CheckboxControl,
	RadioControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { errorMessage } from '../shared/api';
import { TranslationFields, useOtherLanguages } from '../shared/Translations';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import {
	fetchReviewSettings,
	type ReviewSettings,
	saveReviewSettings,
} from './reviews';
import {
	fetchStorefrontSettings,
	saveStorefrontSettings,
	type StorefrontSettings,
} from './storefront';
import { Field, Page, SaveBar, Section, Skeleton, useSave } from '../shared/ui';

const POLICIES = [
	{
		value: 'anyone',
		label: __(
			'Anyone may review; reviews from buyers are marked',
			'orillacart'
		),
	},
	{
		value: 'buyers',
		label: __( 'Only customers who bought the product', 'orillacart' ),
	},
	{ value: 'off', label: __( 'No reviews', 'orillacart' ) },
];

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

interface Form {
	reviews: ReviewSettings;
	storefront: StorefrontSettings;
}

const FORM_ID = 'orillacart-storefront';

/**
 * PHP sends an empty list for no translations; the form holds a map.
 *
 * @param settings The settings as read.
 */
const loadedStorefront = (
	settings: StorefrontSettings
): StorefrontSettings => ( {
	...settings,
	notice_translations: Array.isArray( settings.notice_translations )
		? {}
		: settings.notice_translations,
} );

/**
 * Who may review products, what product pages tell search engines and social sites, what the shop keeps for
 * shoppers, and the notice over the shop's pages. One form, saved with its button: whichever part changed.
 */
export function StorefrontScreen() {
	const others = useOtherLanguages();
	const [ saved, setSaved ] = useState< Form | null >( null );
	const [ form, setForm ] = useState< Form | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, save ] = useSave( ( failure ) =>
		setError( errorMessage( failure ) )
	);
	const dirty = form !== null && saved !== null && differs( form, saved );
	useUnsavedChanges( dirty );

	useEffect( () => {
		Promise.all( [ fetchReviewSettings(), fetchStorefrontSettings() ] )
			.then( ( [ reviews, storefront ] ) => {
				const loaded = {
					reviews,
					storefront: loadedStorefront( storefront ),
				};
				setSaved( loaded );
				setForm( loaded );
			} )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const storefront = ( changed: Partial< StorefrontSettings > ) =>
		setForm(
			( current ) =>
				current && {
					...current,
					storefront: { ...current.storefront, ...changed },
				}
		);
	const translate = ( field: string, values: Record< string, string > ) =>
		form &&
		storefront( {
			notice_translations: {
				...form.storefront.notice_translations,
				[ field ]: values,
			},
		} );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		if ( form === null || saved === null ) {
			return;
		}
		setError( null );
		void save(
			async () => {
				const reviews = differs( form.reviews, saved.reviews )
					? await saveReviewSettings( form.reviews )
					: saved.reviews;
				const shop = differs( form.storefront, saved.storefront )
					? loadedStorefront(
							await saveStorefrontSettings( form.storefront )
						)
					: saved.storefront;
				const next = { reviews, storefront: shop };
				setSaved( next );
				setForm( next );
			},
			__( 'Storefront settings saved.', 'orillacart' )
		);
	};

	return (
		<Page
			title={ __( 'Storefront', 'orillacart' ) }
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
						title={ __( 'Reviews', 'orillacart' ) }
						description={ __(
							'Reviews are comments: Settings → Discussion decides whether they wait for approval, and the Comments screen is where you approve and answer them. A product’s “Allow comments” box turns its reviews on or off.',
							'orillacart'
						) }
					>
						<RadioControl
							label={ __(
								'Who may review products',
								'orillacart'
							) }
							help={ __(
								'A customer who bought the product, by their account or from the link in their order’s emails, is a verified buyer.',
								'orillacart'
							) }
							selected={ form.reviews.policy }
							options={ POLICIES }
							onChange={ ( policy ) =>
								setForm( {
									...form,
									reviews: {
										policy: policy as ReviewSettings[ 'policy' ],
									},
								} )
							}
						/>
					</Section>
					<Section
						title={ __(
							'Search engines and sharing',
							'orillacart'
						) }
						description={ __(
							'What product pages tell search engines, social sites and analytics.',
							'orillacart'
						) }
					>
						<CheckboxControl
							label={ __(
								'Describe products to search engines',
								'orillacart'
							) }
							help={ __(
								'Structured data (schema.org) with the price, stock, barcode, brand, stars and reviews, for rich results in Google and others.',
								'orillacart'
							) }
							checked={ form.storefront.structured_data }
							onChange={ ( checked ) =>
								storefront( { structured_data: checked } )
							}
						/>
						<RadioControl
							label={ __(
								'Link previews on social sites and in chat apps (Open Graph)',
								'orillacart'
							) }
							selected={ form.storefront.sharing_tags }
							options={ SHARING }
							onChange={ ( sharing ) =>
								storefront( {
									sharing_tags:
										sharing as StorefrontSettings[ 'sharing_tags' ],
								} )
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
							checked={ form.storefront.data_layer }
							onChange={ ( checked ) =>
								storefront( { data_layer: checked } )
							}
						/>
					</Section>
					<Section
						title={ __( 'Shoppers', 'orillacart' ) }
						description={ __(
							'What the shop keeps for shoppers and how its lists behave.',
							'orillacart'
						) }
					>
						<RadioControl
							label={ __(
								'Who may keep a wishlist',
								'orillacart'
							) }
							selected={ form.storefront.wishlist }
							options={ WISHLISTS }
							onChange={ ( wishlist ) =>
								storefront( {
									wishlist:
										wishlist as StorefrontSettings[ 'wishlist' ],
								} )
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
							checked={ form.storefront.recently_viewed }
							onChange={ ( checked ) =>
								storefront( { recently_viewed: checked } )
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
							checked={
								form.storefront.hide_single_value_filters
							}
							onChange={ ( checked ) =>
								storefront( {
									hide_single_value_filters: checked,
								} )
							}
						/>
						{ others.length > 0 && (
							<CheckboxControl
								label={ __(
									'List products not translated into the language shown',
									'orillacart'
								) }
								help={ __(
									'In their own words, in the shop’s lists and searches. Without it, a product is listed in the languages it is translated into, and its own.',
									'orillacart'
								) }
								checked={ form.storefront.list_untranslated }
								onChange={ ( checked ) =>
									storefront( { list_untranslated: checked } )
								}
							/>
						) }
					</Section>
					<Section
						title={ __( 'Store notice', 'orillacart' ) }
						description={ __(
							'A line over the shop’s pages, wherever the Store notice block is: add it to the site’s header in the Site Editor.',
							'orillacart'
						) }
					>
						<CheckboxControl
							label={ __(
								'Show a notice over the shop’s pages',
								'orillacart'
							) }
							checked={ form.storefront.notice }
							onChange={ ( notice ) => storefront( { notice } ) }
						/>
						<Field size="l">
							<TextareaControl
								label={ __( 'Notice', 'orillacart' ) }
								help={ __(
									'Such as “Free delivery this week”. Up to 300 characters, without formatting.',
									'orillacart'
								) }
								rows={ 2 }
								value={ form.storefront.notice_text }
								maxLength={ 300 }
								onChange={ ( value ) =>
									storefront( { notice_text: value } )
								}
							/>
						</Field>
						<Field size="l">
							<TranslationFields
								label={ __( 'Notice', 'orillacart' ) }
								languages={ others }
								multiline
								value={
									form.storefront.notice_translations.text
								}
								onChange={ ( values ) =>
									translate( 'text', values )
								}
							/>
						</Field>
						<Field size="l">
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Link', 'orillacart' ) }
								help={ __(
									'An address starting with https://, or with / for a page of this site, such as /sale. Leave it empty for none.',
									'orillacart'
								) }
								value={ form.storefront.notice_link }
								onChange={ ( value ) =>
									storefront( { notice_link: value } )
								}
							/>
						</Field>
						<Field size="m">
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Link text', 'orillacart' ) }
								help={ __(
									'Such as “See the offers”. Leave it empty to make the notice itself the link.',
									'orillacart'
								) }
								value={ form.storefront.notice_link_label }
								maxLength={ 60 }
								onChange={ ( value ) =>
									storefront( { notice_link_label: value } )
								}
							/>
						</Field>
						<Field size="m">
							<TranslationFields
								label={ __( 'Link text', 'orillacart' ) }
								languages={ others }
								value={
									form.storefront.notice_translations
										.link_label
								}
								onChange={ ( values ) =>
									translate( 'link_label', values )
								}
							/>
						</Field>
						<CheckboxControl
							label={ __(
								'Shoppers may close it',
								'orillacart'
							) }
							help={ __(
								'Their browser remembers, until the notice changes.',
								'orillacart'
							) }
							checked={ form.storefront.notice_dismissible }
							onChange={ ( value ) =>
								storefront( { notice_dismissible: value } )
							}
						/>
					</Section>
				</form>
			) }
			{ form !== null && (
				<SaveBar
					dirty={ dirty }
					busy={ busy }
					form={ FORM_ID }
					label={ __( 'Save storefront settings', 'orillacart' ) }
				/>
			) }
		</Page>
	);
}
