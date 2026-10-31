/**
 * Puts the shop's blocks in the editor's inserter. The server registers them and renders them on the site
 * (BlockDefinition); in the editor they show a placeholder, since they display one shopper's cart or account.
 */
import {
	InnerBlocks,
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { fetchTerms, type Named, TermTokens } from '../shared/CatalogTokens';
import './product-blocks';
import './shop-blocks';
import './listing-blocks';

type ProductsAttributes = {
	perPage: number;
	columns: number;
	layout: 'grid' | 'list';
	category: string;
	tag: string;
	sort: 'order' | 'newest' | 'name' | 'price_asc' | 'price_desc';
	featured: boolean;
	onSale: boolean;
	filters: boolean;
	showCategories: boolean;
	showSearch: boolean;
	showSort: boolean;
	showLayouts: boolean;
	showCount: boolean;
	showPagination: boolean;
};

type SearchAttributes = { placeholder: string };

type CategoriesAttributes = {
	source: 'top' | 'below' | 'chosen';
	parent: number;
	include: number[];
	followPage: boolean;
	columns: number;
	showImage: boolean;
	showCount: boolean;
	showEmpty: boolean;
};

const notes: Record< string, string > = {
	'orillacart/cart': __( 'Shoppers see their cart here.', 'orillacart' ),
	'orillacart/checkout': __(
		'Shoppers enter their addresses and pay here, and see their receipt after ordering.',
		'orillacart'
	),
	'orillacart/account': __(
		'Customers log in here and see their orders, files and addresses.',
		'orillacart'
	),
	'orillacart/recently-viewed': __(
		'Shoppers see the products they viewed lately here, which their browser keeps. Product pages show them by themselves.',
		'orillacart'
	),
	'orillacart/currency-switcher': __(
		'Shoppers choose the currency they see prices and pay in here, among those the shop offers (Settings → Currencies). It shows nothing while the shop offers only its own.',
		'orillacart'
	),
	'orillacart/legal-notice': __(
		'Shows who sells: the name, address, contacts, registration and VAT number given in Settings → Invoices.',
		'orillacart'
	),
	'orillacart/withdrawal': __(
		'Customers withdraw from a purchase here, without logging in, and get a confirmation by email at once, as the EU asks of online shops.',
		'orillacart'
	),
	'orillacart/shipping-payment': __(
		'Shows where the shop ships and what it costs, and the ways to pay, as the shop has them now.',
		'orillacart'
	),
	'orillacart/product-filters': __(
		'Shoppers narrow the products by brand, the shop’s filters, price and stock here, with how many products each choice leaves. Place it beside a Products block whose own filters are off; it counts within that block’s category, featured or sale products.',
		'orillacart'
	),
};

function Note( { name }: { name: string } ) {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ name.replace( 'orillacart/', '' ) }
				instructions={ notes[ name ] ?? '' }
			/>
		</div>
	);
}

/** The parts around the grid, each on unless turned off. */
const listingParts: [ keyof ProductsAttributes, string ][] = [
	[ 'showCategories', __( 'Category links', 'orillacart' ) ],
	[ 'showSearch', __( 'Search', 'orillacart' ) ],
	[ 'showSort', __( 'Sorting', 'orillacart' ) ],
	[ 'showLayouts', __( 'Grid or list switch', 'orillacart' ) ],
	[ 'showCount', __( 'How many products', 'orillacart' ) ],
	[ 'showPagination', __( 'Pages', 'orillacart' ) ],
];

function ProductsEdit( {
	clientId,
	attributes,
	setAttributes,
}: {
	clientId: string;
	attributes: ProductsAttributes;
	setAttributes: ( next: Partial< ProductsAttributes > ) => void;
} ) {
	const designed = useSelect(
		( select ) =>
			(
				select( blockEditorStore ) as {
					getBlockCount: ( id: string ) => number;
				}
			 ).getBlockCount( clientId ) > 0,
		[ clientId ]
	);
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Products', 'orillacart' ) }>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Products per page', 'orillacart' ) }
						value={ attributes.perPage }
						min={ 1 }
						max={ 60 }
						onChange={ ( perPage ) =>
							setAttributes( { perPage: perPage ?? 12 } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Shown as', 'orillacart' ) }
						help={ __(
							'A category can choose its own on its edit screen, and shoppers can switch.',
							'orillacart'
						) }
						value={ attributes.layout }
						options={ [
							{
								label: __( 'A grid of cards', 'orillacart' ),
								value: 'grid',
							},
							{
								label: __( 'A list of rows', 'orillacart' ),
								value: 'list',
							},
						] }
						onChange={ ( layout ) =>
							setAttributes( {
								layout: layout as ProductsAttributes[ 'layout' ],
							} )
						}
					/>
					<RangeControl
						__next40pxDefaultSize
						label={ __( 'Columns', 'orillacart' ) }
						value={ attributes.columns }
						min={ 1 }
						max={ 6 }
						onChange={ ( columns ) =>
							setAttributes( { columns: columns ?? 3 } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'Only this category (slug)',
							'orillacart'
						) }
						help={ __(
							'Empty: every category, with links to choose one.',
							'orillacart'
						) }
						value={ attributes.category }
						onChange={ ( category ) =>
							setAttributes( { category } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Only this tag (slug)', 'orillacart' ) }
						help={ __(
							'Empty: products with any tag.',
							'orillacart'
						) }
						value={ attributes.tag }
						onChange={ ( tag ) => setAttributes( { tag } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'First sorted by', 'orillacart' ) }
						help={ __(
							'Shoppers can sort differently.',
							'orillacart'
						) }
						value={ attributes.sort }
						options={ [
							{
								label: __(
									'Recommended (the products’ Order)',
									'orillacart'
								),
								value: 'order',
							},
							{
								label: __( 'Newest', 'orillacart' ),
								value: 'newest',
							},
							{
								label: __( 'Name', 'orillacart' ),
								value: 'name',
							},
							{
								label: __( 'Price: low to high', 'orillacart' ),
								value: 'price_asc',
							},
							{
								label: __( 'Price: high to low', 'orillacart' ),
								value: 'price_desc',
							},
						] }
						onChange={ ( sort ) =>
							setAttributes( {
								sort: sort as ProductsAttributes[ 'sort' ],
							} )
						}
					/>
					<ToggleControl
						label={ __( 'Featured products only', 'orillacart' ) }
						checked={ attributes.featured }
						onChange={ ( featured ) =>
							setAttributes( { featured } )
						}
					/>
					<ToggleControl
						label={ __( 'Products on sale only', 'orillacart' ) }
						checked={ attributes.onSale }
						onChange={ ( onSale ) => setAttributes( { onSale } ) }
					/>
					<ToggleControl
						label={ __( 'Show filters', 'orillacart' ) }
						help={ __(
							'Brands, the shop’s filters, price and stock, with how many products each leaves.',
							'orillacart'
						) }
						checked={ attributes.filters }
						onChange={ ( filters ) => setAttributes( { filters } ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Around the products', 'orillacart' ) }
					initialOpen={ false }
				>
					{ listingParts.map( ( [ key, label ] ) => (
						<ToggleControl
							key={ key }
							label={ label }
							checked={ attributes[ key ] !== false }
							onChange={ ( checked ) =>
								setAttributes( { [ key ]: checked } )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Products', 'orillacart' ) }
				instructions={
					designed
						? __(
								'The shop: published products with their prices, by category, with search and sorting. Each product in the grid shows the card below.',
								'orillacart'
							)
						: __(
								'The shop: published products with their prices, by category, with search and sorting. Add a product card to design what each product shows.',
								'orillacart'
							)
				}
			/>
			<InnerBlocks
				allowedBlocks={ [ 'orillacart/product-card' ] }
				template={ [] }
				renderAppender={
					designed ? false : InnerBlocks.ButtonBlockAppender
				}
			/>
		</div>
	);
}

function MiniCartEdit( {
	attributes,
	setAttributes,
}: {
	attributes: { showButtons: boolean };
	setAttributes: ( next: { showButtons: boolean } ) => void;
} ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Mini cart', 'orillacart' ) }>
					<ToggleControl
						label={ __( 'Show buttons', 'orillacart' ) }
						help={ __(
							'How many items are in the cart, with buttons to the cart and the checkout: for sidebars. Off: a compact link for headers.',
							'orillacart'
						) }
						checked={ attributes.showButtons }
						onChange={ ( showButtons ) =>
							setAttributes( { showButtons } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Mini cart', 'orillacart' ) }
				instructions={
					attributes.showButtons
						? __(
								'How many items are in the cart, with buttons to the cart and the checkout.',
								'orillacart'
							)
						: __(
								'A link to the cart with the number of items in it.',
								'orillacart'
							)
				}
			/>
		</div>
	);
}

function SearchEdit( {
	attributes,
	setAttributes,
}: {
	attributes: SearchAttributes;
	setAttributes: ( next: Partial< SearchAttributes > ) => void;
} ) {
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Product search', 'orillacart' ) }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Placeholder', 'orillacart' ) }
						value={ attributes.placeholder }
						onChange={ ( placeholder ) =>
							setAttributes( { placeholder } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				label={ __( 'Product search', 'orillacart' ) }
				instructions={ __(
					'A search box that lists the matching products on the shop page.',
					'orillacart'
				) }
			/>
		</div>
	);
}

function CategoriesEdit( {
	attributes,
	setAttributes,
}: {
	attributes: CategoriesAttributes;
	setAttributes: ( next: Partial< CategoriesAttributes > ) => void;
} ) {
	const [ categories, setCategories ] = useState< Named[] >( [] );
	useEffect( () => {
		fetchTerms( 'orillacart_category' )
			.then( setCategories )
			.catch( () => setCategories( [] ) );
	}, [] );

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Product categories', 'orillacart' ) }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Show', 'orillacart' ) }
						value={ attributes.source }
						options={ [
							{
								label: __( 'The top categories', 'orillacart' ),
								value: 'top',
							},
							{
								label: __(
									'The categories below one',
									'orillacart'
								),
								value: 'below',
							},
							{
								label: __(
									'The categories I choose',
									'orillacart'
								),
								value: 'chosen',
							},
						] }
						onChange={ ( source ) =>
							setAttributes( {
								source: source as CategoriesAttributes[ 'source' ],
							} )
						}
					/>
					{ attributes.source === 'below' && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Below', 'orillacart' ) }
							value={ String( attributes.parent ) }
							options={ [
								{
									label: __(
										'Choose a category',
										'orillacart'
									),
									value: '0',
								},
								...categories.map( ( category ) => ( {
									label: category.name,
									value: String( category.id ),
								} ) ),
							] }
							onChange={ ( parent ) =>
								setAttributes( {
									parent: Number.parseInt( parent, 10 ) || 0,
								} )
							}
						/>
					) }
					{ attributes.source === 'chosen' && (
						<TermTokens
							label={ __( 'Categories', 'orillacart' ) }
							help={ __(
								'Shown in the order chosen.',
								'orillacart'
							) }
							terms={ categories }
							ids={ attributes.include }
							onChange={ ( include ) =>
								setAttributes( { include } )
							}
						/>
					) }
					<ToggleControl
						label={ __(
							'On a category’s page, the categories below it',
							'orillacart'
						) }
						help={ __(
							'In a category template or a sidebar: shoppers go further into the category they are in.',
							'orillacart'
						) }
						checked={ attributes.followPage }
						onChange={ ( followPage ) =>
							setAttributes( { followPage } )
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
					<ToggleControl
						label={ __( 'Show pictures', 'orillacart' ) }
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
							'Show categories without products',
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
				block="orillacart/product-categories"
				attributes={ attributes }
				EmptyResponsePlaceholder={ () => (
					<Placeholder
						label={ __( 'Product categories', 'orillacart' ) }
						instructions={ __(
							'No categories with products to show here yet.',
							'orillacart'
						) }
					/>
				) }
			/>
		</div>
	);
}

// Title, icon and attributes come from the server's registration.
registerBlockType( 'orillacart/mini-cart', {
	edit: MiniCartEdit,
	save: () => null,
} as never );
registerBlockType< ProductsAttributes >( 'orillacart/products', {
	edit: ProductsEdit,
	// The card the shop designs is kept inside the block.
	save: () => <InnerBlocks.Content />,
} as never );
registerBlockType< CategoriesAttributes >( 'orillacart/product-categories', {
	edit: CategoriesEdit,
	save: () => null,
} as never );
registerBlockType< SearchAttributes >( 'orillacart/product-search', {
	edit: SearchEdit,
	save: () => null,
} as never );
for ( const name of Object.keys( notes ) ) {
	registerBlockType( name, {
		edit: () => <Note name={ name } />,
		save: () => null,
	} as never );
}
