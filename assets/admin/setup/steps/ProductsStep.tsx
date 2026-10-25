import { Button, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { addSampleData, fetchSampleData, removeSampleData } from '../api';
import type { StepProps } from './types';

/**
 * The first products: one of the shop's, a file of them, or the sample products to try the shop with.
 * @param root0
 * @param root0.next
 * @param root0.fail
 */
export function ProductsStep( { next, fail }: StepProps ) {
	const [ present, setPresent ] = useState< boolean | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchSampleData()
			.then( ( found ) => setPresent( found.present ) )
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( present === null ) {
		return <Spinner />;
	}

	return (
		<>
			<p>
				{ __(
					'Add your first product, import a file of them, or try the shop with eight sample products first: shirts and hoodies in sizes and colours, lamps and mugs. They go again with one click.',
					'orillacart'
				) }
			</p>
			<p>
				<a
					className="button"
					href="post-new.php?post_type=orillacart_product"
				>
					{ __( 'Add a product', 'orillacart' ) }
				</a>{ ' ' }
				<a className="button" href="admin.php?page=orillacart-exchange">
					{ __( 'Import products', 'orillacart' ) }
				</a>{ ' ' }
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						( present ? removeSampleData() : addSampleData() )
							.then( ( result ) => setPresent( result.present ) )
							.catch( fail )
							.finally( () => setBusy( false ) );
					} }
				>
					{ present
						? __( 'Remove the sample products', 'orillacart' )
						: __( 'Add sample products', 'orillacart' ) }
				</Button>
			</p>
			<Button variant="primary" onClick={ next }>
				{ __( 'Go on', 'orillacart' ) }
			</Button>
		</>
	);
}
