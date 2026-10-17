import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Flex } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

type Thumbnail = { id: number; url: string; alt: string };

/**
 * Small previews of media library images, in the order of the ids.
 *
 * @param ids Media library ids.
 * @return The previews, once loaded.
 */
function useThumbnails( ids: number[] ): Thumbnail[] {
	const [ thumbnails, setThumbnails ] = useState< Thumbnail[] >( [] );
	const key = ids.join( ',' );
	useEffect( () => {
		if ( key === '' ) {
			setThumbnails( [] );
			return;
		}
		apiFetch<
			{
				id: number;
				alt_text: string;
				source_url: string;
				media_details?: {
					sizes?: { thumbnail?: { source_url: string } };
				};
			}[]
		>( {
			path: addQueryArgs( '/wp/v2/media', {
				include: key,
				per_page: 100,
				_fields: 'id,alt_text,source_url,media_details',
			} ),
		} )
			.then( ( media ) => {
				const byId = new Map(
					media.map( ( item ) => [ item.id, item ] )
				);
				setThumbnails(
					key
						.split( ',' )
						.map( Number )
						.flatMap( ( id ) => {
							const item = byId.get( id );
							return item
								? [
										{
											id,
											url:
												item.media_details?.sizes
													?.thumbnail?.source_url ??
												item.source_url,
											alt: item.alt_text,
										},
									]
								: [];
						} )
				);
			} )
			.catch( () => setThumbnails( [] ) );
	}, [ key ] );
	return thumbnails;
}

function Previews( { ids }: { ids: number[] } ) {
	const thumbnails = useThumbnails( ids );
	return thumbnails.length === 0 ? null : (
		<Flex justify="flex-start" wrap className="orillacart-media-previews">
			{ thumbnails.map( ( thumbnail ) => (
				<img
					key={ thumbnail.id }
					src={ thumbnail.url }
					alt={ thumbnail.alt }
					width={ 48 }
					height={ 48 }
				/>
			) ) }
		</Flex>
	);
}

/**
 * One image from the media library, such as a variation's.
 *
 * @param props          The field's properties.
 * @param props.label    The field's label.
 * @param props.value    Media library id, 0 for none.
 * @param props.onChange Receives the new id, 0 when removed.
 */
export function ImageField( {
	label,
	value,
	onChange,
}: {
	label: string;
	value: number;
	onChange: ( id: number ) => void;
} ) {
	return (
		<BaseControl __nextHasNoMarginBottom id="" label={ label }>
			<MediaUploadCheck>
				<Previews ids={ value > 0 ? [ value ] : [] } />
				<MediaUpload
					title={ label }
					allowedTypes={ [ 'image' ] }
					value={ value }
					onSelect={ ( media ) =>
						onChange(
							Array.isArray( media )
								? ( media[ 0 ]?.id ?? 0 )
								: media.id
						)
					}
					render={ ( { open } ) => (
						<Flex justify="flex-start">
							<Button
								variant="secondary"
								size="compact"
								onClick={ open }
							>
								{ value > 0
									? __( 'Replace image', 'orillacart' )
									: __( 'Choose an image', 'orillacart' ) }
							</Button>
							{ value > 0 && (
								<Button
									variant="tertiary"
									size="compact"
									isDestructive
									onClick={ () => onChange( 0 ) }
								>
									{ __( 'Remove', 'orillacart' ) }
								</Button>
							) }
						</Flex>
					) }
				/>
			</MediaUploadCheck>
		</BaseControl>
	);
}

/**
 * Several images in order, chosen and arranged in the media library's gallery view.
 *
 * @param props          The field's properties.
 * @param props.label    The field's label.
 * @param props.value    Media library ids, in order.
 * @param props.onChange Receives the new list.
 */
export function GalleryField( {
	label,
	value,
	onChange,
}: {
	label: string;
	value: number[];
	onChange: ( ids: number[] ) => void;
} ) {
	return (
		<BaseControl __nextHasNoMarginBottom id="" label={ label }>
			<MediaUploadCheck>
				<Previews ids={ value } />
				<MediaUpload
					title={ label }
					allowedTypes={ [ 'image' ] }
					multiple
					gallery
					value={ value }
					onSelect={ ( media ) =>
						onChange(
							( Array.isArray( media ) ? media : [ media ] ).map(
								( item ) => item.id
							)
						)
					}
					render={ ( { open } ) => (
						<Flex justify="flex-start">
							<Button
								variant="secondary"
								size="compact"
								onClick={ open }
							>
								{ value.length > 0
									? __( 'Edit gallery', 'orillacart' )
									: __( 'Choose images', 'orillacart' ) }
							</Button>
							{ value.length > 0 && (
								<Button
									variant="tertiary"
									size="compact"
									isDestructive
									onClick={ () => onChange( [] ) }
								>
									{ __( 'Clear', 'orillacart' ) }
								</Button>
							) }
						</Flex>
					) }
				/>
			</MediaUploadCheck>
		</BaseControl>
	);
}
