/**
 * Puts the shop's blocks in the editor's inserter. The server registers them and renders them on the site
 * (BlockDefinition); in the editor they show a placeholder, since they display one shopper's cart or account.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

type ProductsAttributes = {
	perPage: number;
	columns: number;
	category: string;
	sort: 'order' | 'newest' | 'name' | 'price_asc' | 'price_desc';
	featured: boolean;
	onSale: boolean;
	filters: boolean;
};

type SearchAttributes = { placeholder: string };

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

function ProductsEdit( {
	attributes,
	setAttributes,
}: {
	attributes: ProductsAttributes;
	setAttributes: ( next: Partial< ProductsAttributes > ) => void;
} ) {
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
			</InspectorControls>
			<Placeholder
				label={ __( 'Products', 'orillacart' ) }
				instructions={ __(
					'The shop: published products with their prices, by category, with search and sorting.',
					'orillacart'
				) }
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

// Title, icon and attributes come from the server's registration.
registerBlockType( 'orillacart/mini-cart', {
	edit: MiniCartEdit,
	save: () => null,
} as never );
registerBlockType< ProductsAttributes >( 'orillacart/products', {
	edit: ProductsEdit,
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
