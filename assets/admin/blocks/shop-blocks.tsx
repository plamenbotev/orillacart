/**
 * The shop-wide blocks in the editor: the free shipping and promotion progress, the store notice, the brands and
 * the wishlist. The site renders them on the server (BlockDefinition); the progress and the wishlist are one
 * shopper's, so the editor shows what they are for, and the notice the shop's words where the editor may read them.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { fetchTerms, type Named, TermTokens } from '../shared/CatalogTokens';
import {
	fetchStorefrontSettings,
	type StorefrontSettings,
} from '../settings/storefront';

type ProgressAttributes = { showBar: boolean };

type PromotionsAttributes = {
	showApplied: boolean;
	showBars: boolean;
	count: number;
};

type BrandsAttributes = {
	source: 'all' | 'chosen';
	include: number[];
	layout: 'grid' | 'list';
	columns: number;
	showImage: boolean;
	showCount: boolean;
	showEmpty: boolean;
};

type WishlistAttributes = { columns: number; showTitle: boolean };

type EditProps< T > = {
	attributes: T;
	setAttributes: ( next: Partial< T > ) => void;
};

export function FreeShippingEdit( {
	attributes,
	setAttributes,
}: EditProps< ProgressAttributes > ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Free shipping progress', 'orillacart' ) }
				>
					<ToggleControl
						label={ __( 'Show a bar', 'orillacart' ) }
						help={ __(
							'How far the cart is from shipping free, under the message.',
							'orillacart'
						) }
						checked={ attributes.showBar }
						onChange={ ( showBar ) => setAttributes( { showBar } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Free shipping progress', 'orillacart' ) }
				instructions={ __(
					'Shoppers see how much more their cart needs to ship free, such as “Spend €12.40 more for free shipping”, and “Your order ships free” once it does. It follows the shop’s free shipping rates for the shopper’s address, or the shop’s country, and shows nothing while no free rate would take the cart.',
					'orillacart'
				) }
			/>
		</div>
	);
}

export function PromotionProgressEdit( {
	attributes,
	setAttributes,
}: EditProps< PromotionsAttributes > ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Promotion progress', 'orillacart' ) }>
					<ToggleControl
						label={ __(
							'Show the promotions it gets',
							'orillacart'
						) }
						help={ __(
							'Those the cart has earned already, with what they save.',
							'orillacart'
						) }
						checked={ attributes.showApplied }
						onChange={ ( showApplied ) =>
							setAttributes( { showApplied } )
						}
					/>
					<ToggleControl
						label={ __( 'Show bars', 'orillacart' ) }
						help={ __(
							'How far the cart is from each promotion, under its message.',
							'orillacart'
						) }
						checked={ attributes.showBars }
						onChange={ ( showBars ) =>
							setAttributes( { showBars } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'How many to show', 'orillacart' ) }
						help={ __(
							'Of the promotions still to get, the nearest first.',
							'orillacart'
						) }
						value={ attributes.count }
						min={ 1 }
						max={ 10 }
						onChange={ ( count ) =>
							setAttributes( { count: count ?? 3 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Promotion progress', 'orillacart' ) }
				instructions={ __(
					'Shoppers see what their cart still needs for the shop’s promotions, such as “Add 1 more Lamp to get 1 free” or “Spend €18.00 more to get 10% off”, and the promotions it gets. It tells of automatic discounts, and of codes the shop chose to show to shoppers, and shows nothing while there is nothing to tell.',
					'orillacart'
				) }
			/>
		</div>
	);
}

/**
 * The notice as the shop has it, for those who may read the storefront's settings; what it is for otherwise.
 */
export function StoreNoticeEdit() {
	const [ settings, setSettings ] = useState< StorefrontSettings | null >(
		null
	);
	useEffect( () => {
		fetchStorefrontSettings()
			.then( setSettings )
			.catch( () => setSettings( null ) );
	}, [] );
	const shown =
		settings !== null && settings.notice && settings.notice_text !== '';

	return (
		<div { ...useBlockProps() }>
			{ shown ? (
				<div className="orillacart-store-notice">
					<p className="orillacart-store-notice__text">
						{ settings.notice_text }
						{ settings.notice_link !== '' &&
							settings.notice_link_label !== '' && (
								<>
									{ ' ' }
									<span className="orillacart-store-notice__link">
										{ settings.notice_link_label }
									</span>
								</>
							) }
					</p>
				</div>
			) : (
				<Placeholder
					label={ __( 'Store notice', 'orillacart' ) }
					instructions={ __(
						'Shows the shop’s notice, such as “Free delivery this week”, written in Settings → Storefront. It shows nothing while the notice is off or has no text.',
						'orillacart'
					) }
				/>
			) }
		</div>
	);
}

export function BrandsEdit( {
	attributes,
	setAttributes,
}: EditProps< BrandsAttributes > ) {
	const [ brands, setBrands ] = useState< Named[] >( [] );
	useEffect( () => {
		fetchTerms( 'orillacart_brand' )
			.then( setBrands )
			.catch( () => setBrands( [] ) );
	}, [] );

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Product brands', 'orillacart' ) }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Show', 'orillacart' ) }
						value={ attributes.source }
						options={ [
							{
								label: __( 'Every brand', 'orillacart' ),
								value: 'all',
							},
							{
								label: __(
									'The brands I choose',
									'orillacart'
								),
								value: 'chosen',
							},
						] }
						onChange={ ( source ) =>
							setAttributes( {
								source: source as BrandsAttributes[ 'source' ],
							} )
						}
					/>
					{ attributes.source === 'chosen' && (
						<TermTokens
							label={ __( 'Brands', 'orillacart' ) }
							help={ __(
								'Shown in the order chosen.',
								'orillacart'
							) }
							terms={ brands }
							ids={ attributes.include }
							onChange={ ( include ) =>
								setAttributes( { include } )
							}
						/>
					) }
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Layout', 'orillacart' ) }
						value={ attributes.layout }
						options={ [
							{
								label: __( 'Tiles', 'orillacart' ),
								value: 'grid',
							},
							{
								label: __( 'A list', 'orillacart' ),
								value: 'list',
							},
						] }
						onChange={ ( layout ) =>
							setAttributes( {
								layout: layout as BrandsAttributes[ 'layout' ],
							} )
						}
					/>
					{ attributes.layout === 'grid' && (
						<RangeControl
							__next40pxDefaultSize
							label={ __( 'Columns', 'orillacart' ) }
							value={ attributes.columns }
							min={ 1 }
							max={ 6 }
							onChange={ ( columns ) =>
								setAttributes( { columns: columns ?? 4 } )
							}
						/>
					) }
					<ToggleControl
						label={ __( 'Show logos', 'orillacart' ) }
						help={ __(
							'A brand’s logo is chosen where the brand is edited.',
							'orillacart'
						) }
						checked={ attributes.showImage }
						onChange={ ( showImage ) =>
							setAttributes( { showImage } )
						}
					/>
					<ToggleControl
						label={ __( 'Show how many products', 'orillacart' ) }
						checked={ attributes.showCount }
						onChange={ ( showCount ) =>
							setAttributes( { showCount } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show brands without products',
							'orillacart'
						) }
						checked={ attributes.showEmpty }
						onChange={ ( showEmpty ) =>
							setAttributes( { showEmpty } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block="orillacart/product-brands"
				attributes={ attributes }
				EmptyResponsePlaceholder={ () => (
					<Placeholder
						label={ __( 'Product brands', 'orillacart' ) }
						instructions={ __(
							'No brands with products to show here yet.',
							'orillacart'
						) }
					/>
				) }
			/>
		</div>
	);
}

export function WishlistEdit( {
	attributes,
	setAttributes,
}: EditProps< WishlistAttributes > ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Wishlist', 'orillacart' ) }>
					<ToggleControl
						label={ __( 'Show the heading', 'orillacart' ) }
						checked={ attributes.showTitle }
						onChange={ ( showTitle ) =>
							setAttributes( { showTitle } )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Columns', 'orillacart' ) }
						value={ attributes.columns }
						min={ 1 }
						max={ 6 }
						onChange={ ( columns ) =>
							setAttributes( { columns: columns ?? 4 } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Wishlist', 'orillacart' ) }
				instructions={ __(
					'Shoppers see the products they saved for later here: a customer’s from their account, a visitor’s from their browser when the shop lets visitors keep one (Settings → Storefront). It shows nothing while the shop keeps no wishlists.',
					'orillacart'
				) }
			/>
		</div>
	);
}

// Title, icon and attributes come from the server's registration.
registerBlockType< ProgressAttributes >( 'orillacart/free-shipping-progress', {
	edit: FreeShippingEdit,
	save: () => null,
} as never );
registerBlockType< PromotionsAttributes >( 'orillacart/promotion-progress', {
	edit: PromotionProgressEdit,
	save: () => null,
} as never );
registerBlockType( 'orillacart/store-notice', {
	edit: StoreNoticeEdit,
	save: () => null,
} as never );
registerBlockType< BrandsAttributes >( 'orillacart/product-brands', {
	edit: BrandsEdit,
	save: () => null,
} as never );
registerBlockType< WishlistAttributes >( 'orillacart/wishlist', {
	edit: WishlistEdit,
	save: () => null,
} as never );
