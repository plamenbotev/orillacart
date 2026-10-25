import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { answered, flag, text, type StepProps } from './types';

/**
 * How the shop is registered for tax, asked as its country's tax works: VAT and the EU's One-Stop Shop, GST
 * and Canada's provincial taxes, US sales tax where the shop has nexus.
 * @param root0
 * @param root0.answers
 * @param root0.profile
 * @param root0.save
 * @param root0.fail
 */
export function TaxStep( { answers, profile, save, fail }: StepProps ) {
	const given = answered( answers, 'tax' );
	const [ registered, setRegistered ] = useState(
		flag( given, 'registered', true )
	);
	const [ oss, setOss ] = useState( flag( given, 'oss', false ) );
	const [ regions, setRegions ] = useState< string[] >(
		Array.isArray( given.regions ) ? ( given.regions as string[] ) : []
	);
	const [ provider, setProvider ] = useState( text( given, 'provider' ) );
	const [ ownRate, setOwnRate ] = useState( text( given, 'own_rate' ) );
	const [ note, setNote ] = useState(
		text( given, 'small_business_note', profile?.small_business_note ?? '' )
	);
	const [ providers, setProviders ] = useState<
		{ id: string; label: string }[]
	>( [] );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		apiFetch< { providers: { id: string; label: string }[] } >( {
			path: '/orillacart/v1/tax/settings',
		} )
			.then( ( settings ) => setProviders( settings.providers ) )
			.catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( profile === null ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Choose where the shop sells from in the first step.',
					'orillacart'
				) }
			</Notice>
		);
	}
	const system = profile.tax_system;
	const states = Array.from(
		new Set( profile.regional.map( ( tax ) => tax.state ) )
	);
	// Canada: GST or HST goes everywhere; a province's own tax only where the shop is registered for it.
	const provincial = profile.regional.filter( ( tax ) =>
		[ 'PST', 'QST', 'RST' ].includes( tax.name )
	);
	const toggle = ( region: string, on: boolean ) =>
		setRegions( ( current ) =>
			on
				? [ ...current, region ]
				: current.filter( ( other ) => other !== region )
		);

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( {
					registered,
					oss: profile.in_eu && oss,
					regions,
					provider: provider || null,
					own_rate: ownRate || null,
					small_business_note: registered ? null : note || null,
				} )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			{ profile.standard !== null && (
				<p>
					{ sprintf(
						/* translators: 1: the tax's name, such as VAT; 2: the standard rate; 3: the reduced rates; 4: a date */
						__(
							'%1$s here: %2$s%% standard, %3$s reduced; as in force on %4$s.',
							'orillacart'
						),
						profile.tax_name,
						profile.standard,
						profile.reduced.length > 0
							? profile.reduced
									.map( ( rate ) => `${ rate }%` )
									.join( ', ' )
							: '—',
						profile.checked_on ?? '—'
					) }
				</p>
			) }
			{ system === 'unknown' ? (
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Tax rate the shop charges, %', 'orillacart' ) }
					help={ __(
						'OrillaCart knows no rates for this country. Leave it empty to charge none.',
						'orillacart'
					) }
					inputMode="decimal"
					value={ ownRate }
					onChange={ setOwnRate }
				/>
			) : (
				<CheckboxControl
					label={
						system === 'sales_tax'
							? __(
									'The shop collects sales tax in some states',
									'orillacart'
								)
							: sprintf(
									/* translators: %s: the tax's name, such as VAT or GST */
									__(
										'The business is registered for %s',
										'orillacart'
									),
									profile.tax_name
								)
					}
					help={
						system === 'sales_tax'
							? undefined
							: __(
									'A small business below the threshold may charge none: its invoices then say why.',
									'orillacart'
								)
					}
					checked={ registered }
					onChange={ setRegistered }
				/>
			) }
			{ ! registered &&
				system !== 'unknown' &&
				system !== 'sales_tax' && (
					<TextareaControl
						label={ __(
							'What invoices say instead',
							'orillacart'
						) }
						help={ __(
							'The reason no tax is charged, as the law of your country words it.',
							'orillacart'
						) }
						value={ note }
						onChange={ setNote }
					/>
				) }
			{ registered && profile.in_eu && (
				<CheckboxControl
					label={ __(
						'Consumers in other EU countries pay their own VAT (One-Stop Shop)',
						'orillacart'
					) }
					help={ __(
						'Required once sales to consumers in other EU countries pass €10,000 a year, or when you registered for the OSS. Until then they pay your rate; the shop watches the threshold.',
						'orillacart'
					) }
					checked={ oss }
					onChange={ setOss }
				/>
			) }
			{ registered && system === 'sales_tax' && (
				<fieldset>
					<legend>
						{ __(
							'States where the shop has nexus and collects sales tax',
							'orillacart'
						) }
					</legend>
					<div className="orillacart-setup__columns">
						{ states.map( ( state ) => (
							<CheckboxControl
								key={ state }
								label={ `${ state } (${
									profile.regional.find(
										( tax ) => tax.state === state
									)?.percent
								}%)` }
								checked={ regions.includes( state ) }
								onChange={ ( on ) => toggle( state, on ) }
							/>
						) ) }
					</div>
					{ providers.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Tax service', 'orillacart' ) }
							help={ __(
								'It charges local sales taxes, which the state rates leave out.',
								'orillacart'
							) }
							value={ provider }
							options={ [
								{
									value: '',
									label: __(
										'None: the state rates',
										'orillacart'
									),
								},
								...providers.map( ( service ) => ( {
									value: service.id,
									label: service.label,
								} ) ),
							] }
							onChange={ setProvider }
						/>
					) }
				</fieldset>
			) }
			{ registered && provincial.length > 0 && (
				<fieldset>
					<legend>
						{ __(
							'Provinces where the shop collects their own tax',
							'orillacart'
						) }
					</legend>
					{ provincial.map( ( tax ) => (
						<CheckboxControl
							key={ tax.state }
							label={ `${ tax.state } ${ tax.name } ${ tax.percent }%` }
							checked={ regions.includes( tax.state ) }
							onChange={ ( on ) => toggle( tax.state, on ) }
						/>
					) ) }
				</fieldset>
			) }
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
