import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { errorMessage } from '../shared/api';
import { ProductTokens } from '../shared/CatalogTokens';

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
export function LinkedProductsPanel() {
	const meta = useSelect(
		( select ) =>
			( select( editorStore ).getEditedPostAttribute( 'meta' ) as
				LinkedMeta | undefined ) ?? {},
		[]
	);
	const { editPost } = useDispatch( editorStore );
	const { createErrorNotice } = useDispatch( noticesStore );
	const onError = ( error: unknown ) =>
		void createErrorNotice( errorMessage( error ), {
			type: 'snackbar',
		} );

	return (
		<PluginDocumentSettingPanel
			name="orillacart-linked-products"
			title={ __( 'Linked products', 'orillacart' ) }
		>
			<ProductTokens
				label={ __( 'Upsells', 'orillacart' ) }
				ids={ meta.orillacart_upsells ?? [] }
				onChange={ ( ids ) =>
					editPost( { meta: { orillacart_upsells: ids } } )
				}
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
				onChange={ ( ids ) =>
					editPost( { meta: { orillacart_cross_sells: ids } } )
				}
				onError={ onError }
			/>
			<p className="components-base-control__help">
				{ __(
					'Products the cart offers with this one. Related products, by category and tag, show by themselves.',
					'orillacart'
				) }
			</p>
		</PluginDocumentSettingPanel>
	);
}

/**
 * What the product is known by outside the shop, for search engines: its barcode and the maker's part number.
 */
export function IdentifiersPanel() {
	const meta = useSelect(
		( select ) =>
			( select( editorStore ).getEditedPostAttribute( 'meta' ) as
				LinkedMeta | undefined ) ?? {},
		[]
	);
	const { editPost } = useDispatch( editorStore );

	return (
		<PluginDocumentSettingPanel
			name="orillacart-identifiers"
			title={ __( 'Barcode and part number', 'orillacart' ) }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Barcode (GTIN, EAN, UPC, ISBN)', 'orillacart' ) }
				help={ __( '8, 12, 13 or 14 digits.', 'orillacart' ) }
				inputMode="numeric"
				value={ meta.orillacart_gtin ?? '' }
				onChange={ ( value ) =>
					editPost( {
						meta: { orillacart_gtin: value.replace( /\D/g, '' ) },
					} )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Manufacturer part number (MPN)', 'orillacart' ) }
				value={ meta.orillacart_mpn ?? '' }
				onChange={ ( value ) =>
					editPost( { meta: { orillacart_mpn: value } } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}
