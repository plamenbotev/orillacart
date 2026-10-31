/**
 * The blocks placed around a page's Products block in the editor: its sort, count, pages and active filters.
 * The sort and the count show the server's own rendering for the page being edited, once the page with its
 * Products block is saved; the pages and the active filters, which are often empty until a shopper chooses, a
 * note on what they show. The site renders them on the server (ProductsOnPage finds the Products block they
 * follow).
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	Disabled,
	PanelBody,
	Placeholder,
	ToggleControl,
} from '@wordpress/components';
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

type Attributes = Record< string, unknown >;

type EditProps = {
	name: string;
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
};

/** Each block's label and what it shows where there is no Products block to follow. */
const blocks: Record<
	string,
	{ label: string; note: string; preview?: boolean }
> = {
	'orillacart/products-sort': {
		label: __( 'Products sort', 'orillacart' ),
		preview: true,
		note: __(
			'Shoppers choose how the products of this page’s Products block are sorted here. Place it on a page or template with a Products block, and turn off that block’s own sorting.',
			'orillacart'
		),
	},
	'orillacart/products-count': {
		label: __( 'Products count', 'orillacart' ),
		preview: true,
		note: __(
			'Says how many products this page’s Products block lists: “Showing 13–24 of 57”. Place it on a page or template with a Products block, and turn off that block’s own count.',
			'orillacart'
		),
	},
	'orillacart/products-pagination': {
		label: __( 'Products pagination', 'orillacart' ),
		note: __(
			'The links to the pages of this page’s Products block, when it has more than one. Place it on a page or template with a Products block, and turn off that block’s own pages.',
			'orillacart'
		),
	},
	'orillacart/active-filters': {
		label: __( 'Active filters', 'orillacart' ),
		note: __(
			'The choices narrowing this page’s Products block (words, categories, brands, filters, price, stock), each one removable, with a link that clears them all. It shows nothing until a shopper chooses.',
			'orillacart'
		),
	},
};

/** A block's own settings. */
const settings: Record< string, ( props: EditProps ) => ReactNode > = {
	'orillacart/products-sort': ( { attributes, setAttributes } ) => (
		<ToggleControl
			label={ __( 'Show the label', 'orillacart' ) }
			help={ __(
				'Hidden, screen readers still say what the choice is.',
				'orillacart'
			) }
			checked={ attributes.showLabel !== false }
			onChange={ ( showLabel ) => setAttributes( { showLabel } ) }
		/>
	),
};

function ListingEdit( props: EditProps ) {
	const { name, attributes } = props;
	const block = blocks[ name ];
	const own = settings[ name ];
	const note = () => (
		<Placeholder
			label={ block?.label ?? name }
			instructions={ block?.note ?? '' }
		/>
	);
	return (
		<div { ...useBlockProps() }>
			{ own && (
				<InspectorControls>
					<PanelBody title={ __( 'Settings', 'orillacart' ) }>
						{ own( props ) }
					</PanelBody>
				</InspectorControls>
			) }
			{ block?.preview ? (
				<Disabled>
					<ServerSideRender
						block={ name }
						attributes={ attributes }
						EmptyResponsePlaceholder={ note }
						ErrorResponsePlaceholder={ note }
					/>
				</Disabled>
			) : (
				note()
			) }
		</div>
	);
}

// Title, icon and attributes come from the server's registration.
for ( const name of Object.keys( blocks ) ) {
	registerBlockType( name, {
		edit: ListingEdit,
		save: () => null,
	} as never );
}
