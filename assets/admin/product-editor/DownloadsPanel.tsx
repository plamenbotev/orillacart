import {
	Button,
	FormFileUpload,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useConfirm } from '../shared/useConfirm';
import {
	type Download,
	type DownloadStorage,
	fetchDownloadStorage,
	removeDownload,
	uploadDownload,
} from '../catalog/api';

interface DownloadsPanelProps {
	productId: number;
	downloads: Download[];
	limit: number | null;
	expiryDays: number | null;
	onTermsChange: ( terms: {
		download_limit: number | null;
		download_expiry_days: number | null;
	} ) => void;
	onChanged: () => void;
	onError: ( error: unknown ) => void;
}

/**
 * The files buyers of the product may download once paid, and for how long. Files are kept in the shop's
 * private store, never in the media library.
 *
 * @param props               The product's downloads and what to do when they change.
 * @param props.productId     The product's post id.
 * @param props.downloads     The files the product offers.
 * @param props.limit         Downloads per file; null for no limit.
 * @param props.expiryDays    Days the downloads work after payment; null for always.
 * @param props.onTermsChange Receives a changed limit or expiry (saved with the product details).
 * @param props.onChanged     Reloads the product after a file was added or removed.
 * @param props.onError       Shows an error.
 */
export function DownloadsPanel( {
	productId,
	downloads,
	limit,
	expiryDays,
	onTermsChange,
	onChanged,
	onError,
}: DownloadsPanelProps ) {
	const [ storage, setStorage ] = useState< DownloadStorage | null >( null );
	const [ uploading, setUploading ] = useState( false );
	const [ confirmation, confirm ] = useConfirm();

	useEffect( () => {
		fetchDownloadStorage().then( setStorage ).catch( onError );
	}, [ onError ] );

	const number = ( value: string ) => {
		const parsed = Number.parseInt( value, 10 );

		return Number.isNaN( parsed ) || parsed < 1 ? null : parsed;
	};

	return (
		<>
			{ storage?.reachable === true && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Your web server hands out files from the downloads folder by itself, so anyone with the address could get them without buying. Add this rule to the site’s Nginx configuration, then check again:',
						'orillacart'
					) }
					<pre>{ storage.nginx_rule }</pre>
					<Button
						variant="secondary"
						onClick={ () =>
							fetchDownloadStorage( true )
								.then( setStorage )
								.catch( onError )
						}
					>
						{ __( 'Check again', 'orillacart' ) }
					</Button>
				</Notice>
			) }
			<ul>
				{ downloads.map( ( file ) => (
					<li key={ file.id }>
						{ file.name }{ ' ' }
						<small>
							{ sprintf(
								/* translators: %s: file size in kilobytes */
								__( '(%s KB)', 'orillacart' ),
								String( Math.ceil( file.size / 1024 ) )
							) }
						</small>{ ' ' }
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								confirm(
									sprintf(
										/* translators: %s: file name */
										__(
											'Remove %s? Customers who bought the product can no longer download it.',
											'orillacart'
										),
										file.name
									),
									() =>
										removeDownload( productId, file.id )
											.then( onChanged )
											.catch( onError ),
									__( 'Remove', 'orillacart' )
								)
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
						{ confirmation }
					</li>
				) ) }
			</ul>
			<FormFileUpload
				disabled={ uploading }
				onChange={ ( event ) => {
					const file = event.currentTarget.files?.[ 0 ];
					if ( file === undefined ) {
						return;
					}
					setUploading( true );
					uploadDownload( productId, file )
						.then( onChanged )
						.catch( onError )
						.finally( () => setUploading( false ) );
				} }
			>
				{ uploading
					? __( 'Uploading…', 'orillacart' )
					: __( 'Add a file', 'orillacart' ) }
			</FormFileUpload>
			<TextControl
				__next40pxDefaultSize
				type="number"
				min={ 1 }
				label={ __( 'Downloads per file', 'orillacart' ) }
				help={ __( 'Empty for no limit.', 'orillacart' ) }
				value={ limit === null ? '' : String( limit ) }
				onChange={ ( value ) =>
					onTermsChange( {
						download_limit: number( value ),
						download_expiry_days: expiryDays,
					} )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				type="number"
				min={ 1 }
				label={ __( 'Downloads work for (days)', 'orillacart' ) }
				help={ __(
					'Counted from payment. Empty for always.',
					'orillacart'
				) }
				value={ expiryDays === null ? '' : String( expiryDays ) }
				onChange={ ( value ) =>
					onTermsChange( {
						download_limit: limit,
						download_expiry_days: number( value ),
					} )
				}
			/>
		</>
	);
}
