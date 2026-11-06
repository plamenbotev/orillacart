import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { fetchSettingsArchive, importSettingsArchive } from './archive';
import { notify, Section } from '../shared/ui';

/**
 * The shop's settings as a file, to keep or to take to another site, and a file of them taken in.
 *
 * @param props
 * @param props.onImported Called once a file put something in: the screen loads its settings anew.
 */
export function SettingsFileSection( {
	onImported,
}: {
	onImported?: () => void;
} = {} ) {
	const [ error, setError ] = useState< string | null >( null );
	const fail = ( failure: unknown ) => setError( errorMessage( failure ) );

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
				if ( result.imported.length > 0 ) {
					onImported?.();
				}
				setError( null );
				notify(
					[
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
					refused.length > 0
				);
			} )
			.catch( ( failure: unknown ) =>
				failure instanceof SyntaxError
					? setError( __( 'The file is not JSON.', 'orillacart' ) )
					: fail( failure )
			);

	return (
		<Section
			title={ __( 'Settings file', 'orillacart' ) }
			description={ __(
				'Download what the settings screens save, to keep or to take to another site, and import such a file here. The shop’s pages, its folders and secrets such as passwords stay with each site; payment methods, carriers and tax rates are made on their own screens.',
				'orillacart'
			) }
		>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<div className="oc-actions">
				<Button variant="secondary" onClick={ download }>
					{ __( 'Download the settings', 'orillacart' ) }
				</Button>
				<label
					className="components-button is-tertiary"
					htmlFor="orillacart-settings-file"
				>
					{ __( 'Import a settings file', 'orillacart' ) }
				</label>
				<input
					id="orillacart-settings-file"
					className="oc-visually-hidden"
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
			</div>
		</Section>
	);
}
