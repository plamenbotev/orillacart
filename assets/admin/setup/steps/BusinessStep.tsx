import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	type Country,
	fetchCountries,
	fetchStates,
	type State,
} from '../../shared/geography';
import { fetchCountryProfile } from '../api';
import { answered, text, type StepProps } from './types';

/**
 * Who sells and from where: asked once for the shop's settings and its invoices.
 * @param root0
 * @param root0.answers
 * @param root0.save
 * @param root0.fail
 */
export function BusinessStep( { answers, save, fail }: StepProps ) {
	const given = answered( answers, 'business' );
	const [ form, setForm ] = useState( {
		legal_name: text( given, 'legal_name' ),
		street: text( given, 'street' ),
		city: text( given, 'city' ),
		postcode: text( given, 'postcode' ),
		country: text( given, 'country' ),
		state: text( given, 'state' ),
		email: text( given, 'email' ),
		phone: text( given, 'phone' ),
		vat_number: text( given, 'vat_number' ),
		company_number: text( given, 'company_number' ),
		currency: text( given, 'currency' ),
	} );
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ states, setStates ] = useState< State[] >( [] );
	const [ currencies, setCurrencies ] = useState< string[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const change = ( changed: Partial< typeof form > ) =>
		setForm( ( current ) => ( { ...current, ...changed } ) );

	useEffect( () => {
		fetchCountries().then( setCountries ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		if ( form.country === '' ) {
			return;
		}
		fetchStates( form.country )
			.then( setStates )
			.catch( () => setStates( [] ) );
		fetchCountryProfile( form.country )
			.then( ( profile ) => {
				setCurrencies( profile.currencies );
				// A new country starts with its own currency.
				setForm( ( current ) =>
					current.currency === '' ||
					current.country !== text( given, 'country' )
						? { ...current, currency: profile.currency ?? 'EUR' }
						: current
				);
			} )
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ form.country ] );

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( { ...form, state: form.state || null } )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			<p>
				{ __(
					'Who sells, as invoices and the legal notice name you. Asked once: the shop and its invoices both use it.',
					'orillacart'
				) }
			</p>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Legal name of the business', 'orillacart' ) }
				value={ form.legal_name }
				onChange={ ( value ) => change( { legal_name: value } ) }
				required
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Street and number', 'orillacart' ) }
				value={ form.street }
				onChange={ ( value ) => change( { street: value } ) }
				required
			/>
			<div className="orillacart-setup__row">
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Postcode', 'orillacart' ) }
					value={ form.postcode }
					onChange={ ( value ) => change( { postcode: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'City', 'orillacart' ) }
					value={ form.city }
					onChange={ ( value ) => change( { city: value } ) }
					required
				/>
			</div>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Country the shop sells from', 'orillacart' ) }
				value={ form.country }
				options={ [
					{
						value: '',
						label: __( 'Choose a country', 'orillacart' ),
					},
					...countries.map( ( country ) => ( {
						value: country.code,
						label: country.name,
					} ) ),
				] }
				onChange={ ( country ) => change( { country, state: '' } ) }
				required
			/>
			{ states.length > 0 && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'State or province', 'orillacart' ) }
					value={ form.state }
					options={ [
						{ value: '', label: '—' },
						...states.map( ( state ) => ( {
							value: state.code,
							label: state.name,
						} ) ),
					] }
					onChange={ ( state ) => change( { state } ) }
				/>
			) }
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Currency', 'orillacart' ) }
				help={ __(
					'What prices are in. The country’s own comes first; it can be another.',
					'orillacart'
				) }
				value={ form.currency }
				options={ currencies.map( ( currency ) => ( {
					value: currency,
					label: currency,
				} ) ) }
				onChange={ ( currency ) => change( { currency } ) }
				disabled={ currencies.length === 0 }
			/>
			<TextControl
				__next40pxDefaultSize
				type="email"
				label={ __( 'Email for customers', 'orillacart' ) }
				value={ form.email }
				onChange={ ( value ) => change( { email: value } ) }
				required
			/>
			<TextControl
				__next40pxDefaultSize
				type="tel"
				label={ __( 'Phone', 'orillacart' ) }
				value={ form.phone }
				onChange={ ( value ) => change( { phone: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'VAT or tax number', 'orillacart' ) }
				help={ __(
					'Leave it empty if the business has none. An EU number is checked with the EU (VIES).',
					'orillacart'
				) }
				value={ form.vat_number }
				onChange={ ( value ) => change( { vat_number: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Company registration', 'orillacart' ) }
				help={ __(
					'The register and number, if the business is in one.',
					'orillacart'
				) }
				value={ form.company_number }
				onChange={ ( value ) => change( { company_number: value } ) }
			/>
			<Button
				variant="primary"
				type="submit"
				isBusy={ busy }
				disabled={ busy }
			>
				{ __( 'Save and go on', 'orillacart' ) }
			</Button>
		</form>
	);
}
