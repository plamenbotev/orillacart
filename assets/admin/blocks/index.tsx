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
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

type ProductsAttributes = {
	perPage: number;
	columns: number;
	category: string;
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
	'orillacart/mini-cart': __(
		'A link to the cart with the number of items in it.',
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

// Title, icon and attributes come from the server's registration.
registerBlockType< ProductsAttributes >( 'orillacart/products', {
	edit: ProductsEdit,
	save: () => null,
} as never );
for ( const name of Object.keys( notes ) ) {
	registerBlockType( name, {
		edit: () => <Note name={ name } />,
		save: () => null,
	} as never );
}
