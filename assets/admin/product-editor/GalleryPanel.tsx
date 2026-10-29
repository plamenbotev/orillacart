import { __ } from '@wordpress/i18n';

import { GalleryField } from '../catalog/MediaFields';
import { useEditorHost } from './host';

/**
 * The product's images besides its featured image, saved with the post (post meta orillacart_gallery).
 */
export function GalleryFields() {
	const [ meta, edit ] = useEditorHost().useMeta();
	const gallery = Array.isArray( meta.orillacart_gallery )
		? ( meta.orillacart_gallery as number[] )
		: [];

	return (
		<GalleryField
			label={ __( 'Images besides the featured one', 'orillacart' ) }
			value={ gallery }
			onChange={ ( ids ) => edit( { orillacart_gallery: ids } ) }
		/>
	);
}
