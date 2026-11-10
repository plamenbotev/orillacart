import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	answerDownloadStorage,
	type DownloadStorage,
	fetchDownloadStorage,
} from '../catalog/api';
import { errorMessage } from '../shared/api';

/**
 * The question the downloads check asks after the site was moved, restored or copied: whose is the folder of
 * bought files? Moved, the site adopts it and serves its files again; a copy, it starts an empty folder of its
 * own and leaves the other alone. Nothing shows while the folder is the site's.
 *
 * @param props          The props.
 * @param props.onAnswer Called once the shop took the answer, to check again.
 */
export function DownloadsFolderAnswer( {
	onAnswer,
}: {
	onAnswer: () => void;
} ) {
	const [ storage, setStorage ] = useState< DownloadStorage | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		fetchDownloadStorage()
			.then( setStorage )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const answer = ( choice: 'adopt' | 'new' ) => {
		setBusy( true );
		setError( null );
		answerDownloadStorage( choice )
			.then( ( answered ) => {
				setStorage( answered );
				onAnswer();
			} )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			)
			.finally( () => setBusy( false ) );
	};

	if ( error !== null ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if (
		storage === null ||
		( storage.claim !== 'elsewhere' && storage.claim !== 'missing' )
	) {
		return null;
	}

	return (
		<div className="orillacart-check__answer">
			{ storage.claim === 'elsewhere' && (
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => answer( 'adopt' ) }
				>
					{ __( 'The site moved: adopt the folder', 'orillacart' ) }
				</Button>
			) }
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => answer( 'new' ) }
			>
				{ storage.claim === 'elsewhere'
					? __( 'This is a copy: start a new folder', 'orillacart' )
					: __( 'Start a new, empty folder', 'orillacart' ) }
			</Button>
		</div>
	);
}
