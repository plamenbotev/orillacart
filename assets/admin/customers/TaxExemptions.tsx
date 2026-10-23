import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useConfirm } from '../shared/useConfirm';
import {
	addTaxExemption,
	EXEMPTION_REASONS,
	fetchTaxExemptions,
	removeTaxExemption,
	type TaxExemption,
	type TaxExemptionInput,
} from './api';

const REASON_LABELS: Record< TaxExemption[ 'reason' ], string > = {
	resale: __( 'Resale', 'orillacart' ),
	nonprofit: __( 'Nonprofit', 'orillacart' ),
	government: __( 'Government', 'orillacart' ),
	other: __( 'Other', 'orillacart' ),
};

const EMPTY: TaxExemptionInput = {
	reason: 'resale',
	certificate: '',
	regions: [],
	expires_on: null,
	note: '',
};

/**
 * A customer's exemptions from tax: the certificates they gave the shop, where and until when each holds. Their
 * orders taxed where one holds are sold without tax, and keep the exemption.
 * @param props
 * @param props.customerId
 * @param props.onError
 */
export function TaxExemptions( {
	customerId,
	onError,
}: {
	customerId: number;
	onError: ( error: unknown ) => void;
} ) {
	const [ exemptions, setExemptions ] = useState< TaxExemption[] >( [] );
	const [ form, setForm ] = useState< TaxExemptionInput >( EMPTY );
	const [ regions, setRegions ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ confirmation, confirm ] = useConfirm();
	const failed = useRef( onError );
	failed.current = onError;
	useEffect( () => {
		let current = true;
		fetchTaxExemptions( customerId )
			.then( ( loaded ) => current && setExemptions( loaded ) )
			.catch( ( error: unknown ) => failed.current( error ) );
		return () => {
			current = false;
		};
	}, [ customerId ] );

	return (
		<>
			<h3>{ __( 'Tax exemptions', 'orillacart' ) }</h3>
			{ confirmation }
			<ul className="orillacart-notes">
				{ exemptions.map( ( exemption ) => (
					<li key={ exemption.id }>
						<p>
							{ REASON_LABELS[ exemption.reason ] } ·{ ' ' }
							{ exemption.certificate } ·{ ' ' }
							{ exemption.regions.length === 0
								? __( 'Everywhere', 'orillacart' )
								: exemption.regions.join( ', ' ) }
							{ exemption.expires_on !== null &&
								` · ${ __( 'until', 'orillacart' ) } ${
									exemption.expires_on
								}` }
						</p>
						{ exemption.note !== '' && (
							<small>{ exemption.note }</small>
						) }{ ' ' }
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								confirm(
									__(
										'Remove this exemption? Orders it covered keep it.',
										'orillacart'
									),
									() =>
										void removeTaxExemption(
											customerId,
											exemption.id
										)
											.then( () =>
												setExemptions( ( shown ) =>
													shown.filter(
														( other ) =>
															other.id !==
															exemption.id
													)
												)
											)
											.catch( onError )
								)
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
					</li>
				) ) }
			</ul>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Reason', 'orillacart' ) }
				value={ form.reason }
				options={ EXEMPTION_REASONS.map( ( value ) => ( {
					value,
					label: REASON_LABELS[ value ],
				} ) ) }
				onChange={ ( reason ) =>
					setForm( {
						...form,
						reason: reason as TaxExemption[ 'reason' ],
					} )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Certificate number', 'orillacart' ) }
				value={ form.certificate }
				onChange={ ( certificate ) =>
					setForm( { ...form, certificate } )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Where it holds', 'orillacart' ) }
				help={ __(
					'Countries and states, such as US-TX, US-CA or DE, separated by commas. Empty for everywhere.',
					'orillacart'
				) }
				value={ regions }
				onChange={ setRegions }
			/>
			<TextControl
				__next40pxDefaultSize
				type="date"
				label={ __( 'Expires after', 'orillacart' ) }
				help={ __(
					'Its last day; empty when it does not expire.',
					'orillacart'
				) }
				value={ form.expires_on ?? '' }
				onChange={ ( day ) =>
					setForm( { ...form, expires_on: day === '' ? null : day } )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Note', 'orillacart' ) }
				value={ form.note }
				onChange={ ( note ) => setForm( { ...form, note } ) }
			/>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy || form.certificate.trim() === '' }
				onClick={ () => {
					setBusy( true );
					addTaxExemption( customerId, {
						...form,
						regions: regions
							.split( ',' )
							.map( ( region ) => region.trim().toUpperCase() )
							.filter( ( region ) => region !== '' ),
					} )
						.then( ( added ) => {
							setExemptions( ( shown ) => [ added, ...shown ] );
							setForm( EMPTY );
							setRegions( '' );
						} )
						.catch( onError )
						.finally( () => setBusy( false ) );
				} }
			>
				{ __( 'Add exemption', 'orillacart' ) }
			</Button>
		</>
	);
}
