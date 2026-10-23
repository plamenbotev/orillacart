import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { exemptionFileUrl } from '../shared/exports';
import { useConfirm } from '../shared/useConfirm';
import {
	addTaxExemption,
	approveTaxExemption,
	refuseTaxExemption,
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

const STATUS_LABELS: Record< TaxExemption[ 'status' ], string > = {
	pending: __( 'Sent by the customer: check it', 'orillacart' ),
	approved: __( 'Approved', 'orillacart' ),
	refused: __( 'Refused', 'orillacart' ),
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
						<p>
							<strong>
								{ STATUS_LABELS[ exemption.status ] }
							</strong>
							{ exemption.refusal !== null &&
								` · ${ exemption.refusal }` }
							{ exemption.file !== null && (
								<>
									{ ' · ' }
									<a
										href={ exemptionFileUrl(
											customerId,
											exemption.id
										) }
										download
									>
										{ exemption.file }
									</a>
								</>
							) }
						</p>
						{ exemption.status === 'pending' && (
							<Review
								customerId={ customerId }
								exemption={ exemption }
								onReviewed={ ( reviewed ) =>
									setExemptions( ( shown ) =>
										shown.map( ( other ) =>
											other.id === reviewed.id
												? reviewed
												: other
										)
									)
								}
								onError={ onError }
							/>
						) }
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

/**
 * Approves a certificate the customer sent, or refuses it with the reason they are told.
 * @param props
 * @param props.customerId
 * @param props.exemption
 * @param props.onReviewed
 * @param props.onError
 */
function Review( {
	customerId,
	exemption,
	onReviewed,
	onError,
}: {
	customerId: number;
	exemption: TaxExemption;
	onReviewed: ( reviewed: TaxExemption ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ reason, setReason ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const run = ( work: Promise< TaxExemption > ) => {
		setBusy( true );
		work.then( onReviewed )
			.catch( onError )
			.finally( () => setBusy( false ) );
	};

	return (
		<div>
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () =>
					run( approveTaxExemption( customerId, exemption.id ) )
				}
			>
				{ __( 'Approve', 'orillacart' ) }
			</Button>
			<TextControl
				__next40pxDefaultSize
				label={ __(
					'Or refuse it, telling the customer why',
					'orillacart'
				) }
				value={ reason }
				maxLength={ 200 }
				onChange={ setReason }
			/>
			<Button
				variant="secondary"
				isDestructive
				disabled={ busy || reason.trim() === '' }
				onClick={ () =>
					run(
						refuseTaxExemption(
							customerId,
							exemption.id,
							reason.trim()
						)
					)
				}
			>
				{ __( 'Refuse', 'orillacart' ) }
			</Button>
		</div>
	);
}
