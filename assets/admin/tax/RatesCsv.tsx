import {
	Button,
	CheckboxControl,
	Flex,
	FormFileUpload,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useBusy } from '../shared/useBusy';
import { exportRates, importRates, MAX_CSV_BYTES, type TaxGroup } from './api';

/**
 * A group's rates to and from CSV, in WooCommerce's columns, so a shop moving from it brings its rates.
 * @param root0
 * @param root0.group
 * @param root0.onImported
 * @param root0.onError
 */
export function RatesCsv( {
	group,
	onImported,
	onError,
}: {
	group: TaxGroup;
	onImported: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ replace, setReplace ] = useState( false );
	const [ busy, run ] = useBusy();

	const download = () =>
		run( () =>
			exportRates( group.id )
				.then( ( { csv } ) => {
					const url = URL.createObjectURL(
						new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } )
					);
					const link = document.createElement( 'a' );
					link.href = url;
					link.download = `tax-rates-${ group.id }.csv`;
					link.click();
					URL.revokeObjectURL( url );
				} )
				.catch( onError )
		);

	const upload = ( file: File | undefined ) => {
		if ( file === undefined ) {
			return;
		}
		if ( file.size > MAX_CSV_BYTES ) {
			onError(
				new Error(
					__( 'The file is too large: up to 2 MB.', 'orillacart' )
				)
			);
			return;
		}
		run( () =>
			file
				.text()
				.then( ( csv ) => importRates( group.id, csv, replace ) )
				.then( onImported )
				.catch( onError )
		);
	};

	return (
		<>
			<h3>{ __( 'Rates as a CSV file', 'orillacart' ) }</h3>
			<p className="description">
				{ __(
					'In WooCommerce’s columns: country code, state code, postcodes, cities, rate, name, priority, compound, shipping and tax class, then the filing code. A file exported from WooCommerce imports as it is. Every row is checked before any is saved.',
					'orillacart'
				) }
			</p>
			<CheckboxControl
				label={ sprintf(
					/* translators: %s: tax group name */
					__( 'Replace the rates of %s', 'orillacart' ),
					group.name
				) }
				help={ __(
					'Otherwise the file’s rates are added to them.',
					'orillacart'
				) }
				checked={ replace }
				onChange={ setReplace }
			/>
			<Flex justify="flex-start">
				<FormFileUpload
					accept=".csv,text/csv"
					render={ ( { openFileDialog } ) => (
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ openFileDialog }
						>
							{ __( 'Import rates', 'orillacart' ) }
						</Button>
					) }
					onChange={ ( event ) => {
						upload( event.currentTarget.files?.[ 0 ] );
						event.currentTarget.value = '';
					} }
				/>
				<Button
					variant="tertiary"
					disabled={ busy || group.rates.length === 0 }
					onClick={ download }
				>
					{ __( 'Export rates', 'orillacart' ) }
				</Button>
			</Flex>
		</>
	);
}
