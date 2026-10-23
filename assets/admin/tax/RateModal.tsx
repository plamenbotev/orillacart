import {
	Button,
	CheckboxControl,
	Flex,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { toApiError, violationOf, type Violation } from '../shared/api';
import type { Country } from '../shared/geography';
import { LocationFields } from '../shared/LocationFields';
import { saveRate, type TaxRate, type TaxRateInput } from './api';

/**
 * Adds a rate to a tax group or changes one: where it applies, the percentage, its name on invoices
 * and its place in the list.
 */
interface Props {
	groupId: number;
	/** The rate to change, or null to add one. */
	rate: TaxRate | null;
	countries: Country[];
	onSaved: () => void;
	onClose: () => void;
}

export function RateModal( {
	groupId,
	rate,
	countries,
	onSaved,
	onClose,
}: Props ) {
	const [ form, setForm ] = useState< TaxRateInput >( {
		country: rate?.country ?? countries[ 0 ]?.code ?? '',
		state: rate?.state ?? null,
		percent: rate?.percent ?? '',
		name: rate?.name ?? '',
		priority: rate?.priority ?? 0,
		postcodes: rate?.postcodes ?? [],
		compound: rate?.compound ?? false,
		code: rate?.code ?? null,
	} );
	const [ postcodes, setPostcodes ] = useState(
		( rate?.postcodes ?? [] ).join( ', ' )
	);
	const [ violations, setViolations ] = useState< Violation[] >( [] );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		setViolations( [] );
		setError( null );
		saveRate( groupId, rate?.id ?? null, {
			...form,
			postcodes: postcodes
				.split( ',' )
				.map( ( postcode ) => postcode.trim() )
				.filter( ( postcode ) => postcode !== '' ),
		} )
			.then( onSaved )
			.catch( ( failure: unknown ) => {
				const apiError = toApiError( failure );
				setViolations( apiError.data?.violations ?? [] );
				setError( apiError.message );
				setSaving( false );
			} );
	};

	return (
		<Modal
			title={
				rate === null
					? __( 'Add tax rate', 'orillacart' )
					: __( 'Edit tax rate', 'orillacart' )
			}
			onRequestClose={ onClose }
		>
			<form onSubmit={ submit }>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<LocationFields
					countries={ countries }
					value={ { country: form.country, state: form.state } }
					onChange={ ( location ) =>
						setForm( {
							...form,
							country: location.country ?? '',
							state: location.state,
						} )
					}
					countryLabel={ __( 'Country', 'orillacart' ) }
					stateLabel={ __( 'State', 'orillacart' ) }
					anyState={ __( 'All states', 'orillacart' ) }
					countryError={ violationOf( violations, 'country' ) }
					stateError={ violationOf( violations, 'state' ) }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Postcodes', 'orillacart' ) }
					help={
						violationOf( violations, 'postcodes' ) ??
						__(
							'Only for these postcodes, separated by commas: 1000, a start such as SW1*, or a range such as 1000–1999. Empty for every postcode.',
							'orillacart'
						)
					}
					value={ postcodes }
					onChange={ setPostcodes }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Filing code', 'orillacart' ) }
					help={
						violationOf( violations, 'code' ) ??
						__(
							'Optional: the code you file this tax under, such as a county’s or a city’s. Orders keep it with the tax, and reports sum by it.',
							'orillacart'
						)
					}
					value={ form.code ?? '' }
					onChange={ ( code ) =>
						setForm( {
							...form,
							code: code.trim() === '' ? null : code.trim(),
						} )
					}
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Rate (%)', 'orillacart' ) }
					help={
						violationOf( violations, 'percent' ) ??
						__( 'Up to four decimals, such as 7.25.', 'orillacart' )
					}
					inputMode="decimal"
					value={ form.percent }
					onChange={ ( percent ) => setForm( { ...form, percent } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Name', 'orillacart' ) }
					help={
						violationOf( violations, 'name' ) ??
						__( 'Shown in the cart and on invoices.', 'orillacart' )
					}
					value={ form.name }
					onChange={ ( name ) => setForm( { ...form, name } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={ __( 'Priority', 'orillacart' ) }
					help={
						violationOf( violations, 'priority' ) ??
						__(
							'Rates of one place are listed from the lowest priority up.',
							'orillacart'
						)
					}
					value={ String( form.priority ) }
					onChange={ ( priority ) =>
						setForm( {
							...form,
							priority: Number.parseInt( priority, 10 ) || 0,
						} )
					}
				/>
				<CheckboxControl
					label={ __( 'Compound', 'orillacart' ) }
					help={ __(
						'Charged on the price and the taxes listed before it, instead of on the price alone.',
						'orillacart'
					) }
					checked={ form.compound }
					onChange={ ( compound ) =>
						setForm( { ...form, compound } )
					}
				/>
				<Flex justify="flex-end">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'orillacart' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save rate', 'orillacart' ) }
					</Button>
				</Flex>
			</form>
		</Modal>
	);
}
