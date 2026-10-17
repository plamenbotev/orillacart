import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { GalleryField } from '../catalog/MediaFields';

/**
 * The product's images besides its featured image, saved with the post (post meta orillacart_gallery).
 */
export function GalleryPanel() {
	const gallery = useSelect( ( select ) => {
		const meta = select( editorStore ).getEditedPostAttribute( 'meta' ) as
			{ orillacart_gallery?: number[] } | undefined;
		return meta?.orillacart_gallery ?? [];
	}, [] );
	const { editPost } = useDispatch( editorStore );

	return (
		<PluginDocumentSettingPanel
			name="orillacart-gallery"
			title={ __( 'Gallery', 'orillacart' ) }
		>
			<GalleryField
				label={ __( 'Images besides the featured one', 'orillacart' ) }
				value={ gallery }
				onChange={ ( ids ) =>
					editPost( { meta: { orillacart_gallery: ids } } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}
