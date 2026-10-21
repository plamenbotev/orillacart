import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import { fetchSettingsArchive, importSettingsArchive } from './archive';

/**
 * The shop's settings as a file, to keep or to take to another site, and a file of them taken in.
 */
export function ArchiveCard() {
	const [ message, setMessage ] = useState< {
		status: 'success' | 'warning' | 'error';
		text: string;
	} | null >( null );
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: toApiError( failure ).message } );

	const download = () =>
		fetchSettingsArchive()
			.then( ( file ) => {
				const link = document.createElement( 'a' );
				link.href = URL.createObjectURL(
					new Blob( [ JSON.stringify( file, null, 2 ) ], {
						type: 'application/json',
					} )
				);
				link.download = `orillacart-settings-${ new Date()
					.toISOString()
					.slice( 0, 10 ) }.json`;
				link.click();
				URL.revokeObjectURL( link.href );
			} )
			.catch( fail );

	const upload = ( file: File ) =>
		file
			.text()
			.then( ( text ) => importSettingsArchive( JSON.parse( text ) ) )
			.then( ( result ) => {
				const refused = Object.entries( result.refused );
				setMessage( {
					status: refused.length === 0 ? 'success' : 'warning',
					text: [
						result.imported.length > 0
							? sprintf(
									/* translators: %s: the sections of settings */
									__( 'Imported: %s.', 'orillacart' ),
									result.imported.join( ', ' )
								)
							: __( 'Nothing was imported.', 'orillacart' ),
						...refused.map( ( [ section, reason ] ) =>
							sprintf(
								/* translators: 1: a section of settings, 2: why it was not taken */
								__(
									'Left as it was: %1$s (%2$s)',
									'orillacart'
								),
								section,
								reason
							)
						),
					].join( ' ' ),
				} );
			} )
			.catch( ( failure: unknown ) =>
				failure instanceof SyntaxError
					? setMessage( {
							status: 'error',
							text: __( 'The file is not JSON.', 'orillacart' ),
						} )
					: fail( failure )
			);

	return (
		<Card className="orillacart-settings-card">
			<CardHeader>
				<h2>{ __( 'Settings file', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Download what the settings screens save, to keep or to take to another site, and import such a file here. The shop’s pages, its folders and secrets such as passwords stay with each site; payment methods, carriers and tax rates are made on their own screens.',
						'orillacart'
					) }
				</p>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				<p>
					<Button variant="secondary" onClick={ download }>
						{ __( 'Download the settings', 'orillacart' ) }
					</Button>
				</p>
				<label htmlFor="orillacart-settings-file">
					{ __( 'Import a settings file', 'orillacart' ) }
				</label>{ ' ' }
				<input
					id="orillacart-settings-file"
					type="file"
					accept="application/json,.json"
					onChange={ ( event ) => {
						const file = event.target.files?.[ 0 ];
						if ( file ) {
							void upload( file );
						}
						event.target.value = '';
					} }
				/>
			</CardBody>
		</Card>
	);
}
