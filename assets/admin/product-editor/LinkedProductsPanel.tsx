import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { ProductTokens } from '../shared/CatalogTokens';
import { useEditorHost } from './host';

type LinkedMeta = {
	orillacart_upsells?: number[];
	orillacart_cross_sells?: number[];
	orillacart_gtin?: string;
	orillacart_mpn?: string;
};

/**
 * The products shown beside this one, saved with the post (post meta): upsells on its page, cross-sells in the
 * cart while it is there. Related products, by category and tag, need nothing here.
 */
export function LinkedProductsFields() {
	const host = useEditorHost();
	const [ found, edit ] = host.useMeta();
	const meta = found as LinkedMeta;
	const onError = ( error: unknown ) =>
		host.notify( { status: 'error', message: errorMessage( error ) } );

	return (
		<>
			<ProductTokens
				label={ __( 'Upsells', 'orillacart' ) }
				ids={ meta.orillacart_upsells ?? [] }
				onChange={ ( ids ) => edit( { orillacart_upsells: ids } ) }
				onError={ onError }
			/>
			<p className="components-base-control__help">
				{ __(
					'Better or bigger products the product’s page offers instead.',
					'orillacart'
				) }
			</p>
			<ProductTokens
				label={ __( 'Cross-sells', 'orillacart' ) }
				ids={ meta.orillacart_cross_sells ?? [] }
				onChange={ ( ids ) => edit( { orillacart_cross_sells: ids } ) }
				onError={ onError }
			/>
			<p className="components-base-control__help">
				{ __(
					'Products the cart offers with this one. Related products, by category and tag, show by themselves.',
					'orillacart'
				) }
			</p>
		</>
	);
}

/**
 * What the product is known by outside the shop, for search engines: its barcode and the maker's part number.
 */
export function IdentifiersFields() {
	const [ found, edit ] = useEditorHost().useMeta();
	const meta = found as LinkedMeta;

	return (
		<>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Barcode (GTIN, EAN, UPC, ISBN)', 'orillacart' ) }
				help={ __( '8, 12, 13 or 14 digits.', 'orillacart' ) }
				inputMode="numeric"
				value={ meta.orillacart_gtin ?? '' }
				onChange={ ( value ) =>
					edit( { orillacart_gtin: value.replace( /\D/g, '' ) } )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Manufacturer part number (MPN)', 'orillacart' ) }
				value={ meta.orillacart_mpn ?? '' }
				onChange={ ( value ) => edit( { orillacart_mpn: value } ) }
			/>
		</>
	);
}
