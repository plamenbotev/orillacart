/**
 * The product blocks in the editor: each shows the server's own rendering of its product (the page's, the card's,
 * or the one its Product setting names), and in a template without a product, the latest one. The site renders
 * them on the server (ProductParts); nothing here reaches the storefront.
 */
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	CheckboxControl,
	ComboboxControl,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { fetchProducts, type Named } from '../shared/CatalogTokens';

type Attributes = Record< string, unknown > & { productId: number };

type EditProps = {
	name: string;
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
	context: { postId?: number; postType?: string };
};

/** A block's own settings beside the product. */
type Settings = ( props: EditProps ) => ReactNode;

/** The ways the share block can offer, in its order (blocks/product-share). */
const SHARE_SERVICES: { value: string; label: string }[] = [
	{ value: 'copy', label: __( 'Copy link', 'orillacart' ) },
	{ value: 'email', label: __( 'Email', 'orillacart' ) },
	{ value: 'facebook', label: 'Facebook' },
	{ value: 'x', label: 'X' },
	{ value: 'pinterest', label: 'Pinterest' },
	{ value: 'whatsapp', label: 'WhatsApp' },
	{ value: 'linkedin', label: 'LinkedIn' },
];

/**
 * The services with one turned on or off, kept in the block's order.
 *
 * @param services The services chosen.
 * @param service  The one changed.
 * @param on       Whether it is offered now.
 */
function withService(
	services: string[],
	service: string,
	on: boolean
): string[] {
	const chosen = new Set( services );
	if ( on ) {
		chosen.add( service );
	} else {
		chosen.delete( service );
	}
	return SHARE_SERVICES.map( ( { value } ) => value ).filter( ( value ) =>
		chosen.has( value )
	);
}

/**
 * Chooses the product a block shows on pages that are not the product's own (a landing page).
 *
 * @param props          The chooser's properties.
 * @param props.value    The product's id; 0 for the page's or the card's.
 * @param props.onChange Receives the new id.
 */
function ProductChooser( {
	value,
	onChange,
}: {
	value: number;
	onChange: ( id: number ) => void;
} ) {
	const [ found, setFound ] = useState< Named[] >( [] );
	useEffect( () => {
		if ( value > 0 ) {
			fetchProducts( { include: [ value ] } )
				.then( setFound )
				.catch( () => setFound( [] ) );
		}
	}, [ value ] );

	return (
		<ComboboxControl
			__next40pxDefaultSize
			label={ __( 'Product', 'orillacart' ) }
			help={ __(
				'Empty: the product of the page, or of the card it is in.',
				'orillacart'
			) }
			value={ value > 0 ? String( value ) : null }
			options={ found.map( ( product ) => ( {
				label: product.name,
				value: String( product.id ),
			} ) ) }
			onFilterValueChange={ ( search ) => {
				if ( search.trim().length >= 2 ) {
					fetchProducts( { search } )
						.then( setFound )
						.catch( () => setFound( [] ) );
				}
			} }
			onChange={ ( id ) => onChange( Number( id ) || 0 ) }
		/>
	);
}

function toggle( props: EditProps, key: string, label: string ): ReactNode {
	return (
		<ToggleControl
			key={ key }
			label={ label }
			checked={ props.attributes[ key ] !== false }
			onChange={ ( checked ) =>
				props.setAttributes( { [ key ]: checked } )
			}
		/>
	);
}

const settings: Record< string, Settings > = {
	'orillacart/product-title': ( props ) => (
		<>
			<RangeControl
				__next40pxDefaultSize
				label={ __( 'Heading level', 'orillacart' ) }
				value={ Number( props.attributes.level ?? 1 ) }
				min={ 1 }
				max={ 6 }
				onChange={ ( level ) =>
					props.setAttributes( { level: level ?? 1 } )
				}
			/>
			<ToggleControl
				label={ __( 'Link to the product', 'orillacart' ) }
				checked={ props.attributes.isLink === true }
				onChange={ ( isLink ) => props.setAttributes( { isLink } ) }
			/>
		</>
	),
	'orillacart/product-price': ( props ) =>
		toggle(
			props,
			'showTiers',
			__( 'Show the quantity tiers', 'orillacart' )
		),
	'orillacart/add-to-cart': ( props ) => (
		<>
			{ toggle(
				props,
				'showContents',
				__( 'What is in the box', 'orillacart' )
			) }
			{ toggle( props, 'showSku', __( 'SKU', 'orillacart' ) ) }
			{ toggle( props, 'showStock', __( 'Stock', 'orillacart' ) ) }
			{ toggle(
				props,
				'showWishlist',
				__( 'Wishlist button', 'orillacart' )
			) }
			{ toggle(
				props,
				'showDownloads',
				__( 'The files it brings', 'orillacart' )
			) }
		</>
	),
	'orillacart/related-products': ( props ) => (
		<>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Show', 'orillacart' ) }
				value={
					props.attributes.kind === 'upsells' ? 'upsells' : 'related'
				}
				options={ [
					{
						label: __(
							'Products like this one (its categories)',
							'orillacart'
						),
						value: 'related',
					},
					{
						label: __(
							'Products offered instead (upsells)',
							'orillacart'
						),
						value: 'upsells',
					},
				] }
				onChange={ ( kind: string ) => props.setAttributes( { kind } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Heading', 'orillacart' ) }
				help={ __( 'Empty: the shop’s own.', 'orillacart' ) }
				value={ String( props.attributes.title ?? '' ) }
				onChange={ ( title ) => props.setAttributes( { title } ) }
			/>
			<RangeControl
				__next40pxDefaultSize
				label={ __( 'How many', 'orillacart' ) }
				value={ Number( props.attributes.count ?? 4 ) }
				min={ 1 }
				max={ 12 }
				onChange={ ( count ) =>
					props.setAttributes( { count: count ?? 4 } )
				}
			/>
		</>
	),
	'orillacart/product-specifications': ( props ) => (
		<>
			{ toggle( props, 'showTitle', __( 'Heading', 'orillacart' ) ) }
			{ toggle(
				props,
				'showAttributes',
				__( 'Attributes', 'orillacart' )
			) }
			{ toggle( props, 'showWeight', __( 'Weight', 'orillacart' ) ) }
			{ toggle(
				props,
				'showDimensions',
				__( 'Dimensions', 'orillacart' )
			) }
		</>
	),
	'orillacart/product-share': ( props ) => {
		const services = Array.isArray( props.attributes.services )
			? ( props.attributes.services as string[] )
			: [];
		return (
			<fieldset>
				<legend>{ __( 'Share with', 'orillacart' ) }</legend>
				{ SHARE_SERVICES.map( ( { value, label } ) => (
					<CheckboxControl
						key={ value }
						label={ label }
						checked={ services.includes( value ) }
						onChange={ ( on ) =>
							props.setAttributes( {
								services: withService( services, value, on ),
							} )
						}
					/>
				) ) }
			</fieldset>
		);
	},
	'orillacart/product-image': ( props ) => (
		<>
			{ toggle(
				props,
				'saleBadge',
				__( 'A “Sale” badge while on sale', 'orillacart' )
			) }
			{ toggle(
				props,
				'isLink',
				__( 'Link to the product', 'orillacart' )
			) }
		</>
	),
};

/** What the block shows, for the placeholder when there is no product to preview. */
const descriptions: Record< string, string > = {
	'orillacart/product-title': __( 'The product’s name.', 'orillacart' ),
	'orillacart/product-gallery': __( 'The product’s pictures.', 'orillacart' ),
	'orillacart/product-price': __( 'The product’s price.', 'orillacart' ),
	'orillacart/product-rating': __(
		'The product’s stars, once it has reviews.',
		'orillacart'
	),
	'orillacart/add-to-cart': __(
		'The way to buy the product: its choices, quantity and button.',
		'orillacart'
	),
	'orillacart/product-reviews': __(
		'The product’s reviews and the form to write one, while the shop takes reviews.',
		'orillacart'
	),
	'orillacart/related-products': __(
		'A row of products beside this one.',
		'orillacart'
	),
	'orillacart/product-image': __(
		'The product’s picture, linking to it.',
		'orillacart'
	),
	'orillacart/product-button': __(
		'Add to cart, choose options, or details.',
		'orillacart'
	),
	'orillacart/product-sku': __(
		'The product’s SKU, once it has one.',
		'orillacart'
	),
	'orillacart/product-stock': __(
		'Whether the product is in stock, and how many.',
		'orillacart'
	),
	'orillacart/product-downloads': __(
		'The files the buyer gets, for a product with files.',
		'orillacart'
	),
	'orillacart/product-excerpt': __(
		'The product’s short description.',
		'orillacart'
	),
	'orillacart/product-specifications': __(
		'The product’s attributes, weight and size.',
		'orillacart'
	),
	'orillacart/product-share': __(
		'Links to share the product.',
		'orillacart'
	),
	'orillacart/delivery-estimate': __(
		'By when the product arrives, once a carrier that delivers it says how long it takes.',
		'orillacart'
	),
};

function ProductPartEdit( props: EditProps ) {
	const { name, attributes, setAttributes, context } = props;
	const Own = settings[ name ];
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Product', 'orillacart' ) }>
					<ProductChooser
						value={ attributes.productId }
						onChange={ ( productId ) =>
							setAttributes( { productId } )
						}
					/>
					{ Own ? <Own { ...props } /> : null }
				</PanelBody>
			</InspectorControls>
			<ServerSideRender
				block={ name }
				attributes={ {
					...attributes,
					productId: attributes.productId || context.postId || 0,
					preview: true,
				} }
				EmptyResponsePlaceholder={ () => (
					<Placeholder
						label={ name.replace( 'orillacart/', '' ) }
						instructions={ descriptions[ name ] ?? '' }
					/>
				) }
			/>
		</div>
	);
}

function BreadcrumbsEdit( {
	attributes,
	setAttributes,
}: {
	attributes: { showHome: boolean };
	setAttributes: ( next: { showHome: boolean } ) => void;
} ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Breadcrumbs', 'orillacart' ) }>
					<ToggleControl
						label={ __( 'Start with Home', 'orillacart' ) }
						checked={ attributes.showHome }
						onChange={ ( showHome ) =>
							setAttributes( { showHome } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<nav className="orillacart-breadcrumbs">
				<ol>
					{ attributes.showHome && (
						<li>{ __( 'Home', 'orillacart' ) }</li>
					) }
					<li>{ __( 'Shop', 'orillacart' ) }</li>
					<li>{ __( 'Category', 'orillacart' ) }</li>
					<li>{ __( 'Product', 'orillacart' ) }</li>
				</ol>
			</nav>
		</div>
	);
}

/** The card the shop designs: the product's picture, name, stars, price and button, which the owner changes. */
export const CARD_TEMPLATE: [ string, Record< string, unknown > ][] = [
	[ 'orillacart/product-image', {} ],
	[ 'core/post-title', { level: 2, isLink: true } ],
	[ 'orillacart/product-rating', {} ],
	[ 'orillacart/product-price', { showTiers: false } ],
	[ 'orillacart/product-button', {} ],
];

function CardEdit() {
	return (
		<div { ...useBlockProps( { className: 'orillacart-product-card' } ) }>
			<InnerBlocks template={ CARD_TEMPLATE } templateLock={ false } />
		</div>
	);
}

/** One product's spotlight: its pictures beside its name, stars, price and buying form. */
const SPOTLIGHT: [ string, Record< string, unknown >, unknown[]? ][] = [
	[
		'core/columns',
		{},
		[
			[ 'core/column', {}, [ [ 'orillacart/product-gallery', {} ] ] ],
			[
				'core/column',
				{},
				[
					[ 'orillacart/product-title', { level: 2, isLink: true } ],
					[ 'orillacart/product-rating', {} ],
					[ 'orillacart/product-price', {} ],
					[ 'orillacart/add-to-cart', {} ],
				],
			],
		],
	],
];

function SingleProductEdit( {
	attributes,
	setAttributes,
}: {
	attributes: { productId: number };
	setAttributes: ( next: { productId: number } ) => void;
} ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Product', 'orillacart' ) }>
					<ProductChooser
						value={ attributes.productId }
						onChange={ ( productId ) =>
							setAttributes( { productId } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ attributes.productId === 0 && (
				<Placeholder
					label={ __( 'Single product', 'orillacart' ) }
					instructions={ __(
						'Choose the product to show; the product blocks inside show it.',
						'orillacart'
					) }
				>
					<ProductChooser
						value={ 0 }
						onChange={ ( productId ) =>
							setAttributes( { productId } )
						}
					/>
				</Placeholder>
			) }
			<InnerBlocks template={ SPOTLIGHT } templateLock={ false } />
		</div>
	);
}

// Title, icon and attributes come from the server's registration.
for ( const name of Object.keys( descriptions ) ) {
	registerBlockType( name, {
		edit: ProductPartEdit,
		save: () => null,
	} as never );
}
// Add-ons' product blocks (extension API 16), which the server lists for the editor.
for ( const name of (
	window as unknown as { orillacartAddOnBlocks?: string[] }
 ).orillacartAddOnBlocks ?? [] ) {
	registerBlockType( name, {
		edit: ProductPartEdit,
		save: () => null,
	} as never );
}
registerBlockType( 'orillacart/breadcrumbs', {
	edit: BreadcrumbsEdit,
	save: () => null,
} as never );
registerBlockType( 'orillacart/product', {
	edit: SingleProductEdit,
	save: () => <InnerBlocks.Content />,
} as never );
registerBlockType( 'orillacart/product-card', {
	edit: CardEdit,
	save: () => <InnerBlocks.Content />,
} as never );
