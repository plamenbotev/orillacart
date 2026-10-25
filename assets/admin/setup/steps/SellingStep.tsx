import { Button, FormTokenField, RadioControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { type Country, fetchCountries } from '../../shared/geography';
import { answered, text, type StepProps } from './types';

/**
 * Who the shop sells to, and where: consumers, businesses or both; its country, the EU, Europe, the world or a
 * list.
 * @param root0
 * @param root0.answers
 * @param root0.profile
 * @param root0.save
 * @param root0.fail
 */
export function SellingStep( { answers, profile, save, fail }: StepProps ) {
	const given = answered( answers, 'selling' );
	const [ customers, setCustomers ] = useState(
		text( given, 'customers', 'consumers' )
	);
	const [ regions, setRegions ] = useState(
		text( given, 'regions', profile?.in_eu ? 'eu' : 'home' )
	);
	const [ listed, setListed ] = useState< string[] >(
		Array.isArray( given.countries )
			? ( given.countries as unknown[] ).filter(
					( code ): code is string => typeof code === 'string'
				)
			: []
	);
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		fetchCountries().then( setCountries ).catch( fail );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const name = ( code: string ) =>
		countries.find( ( country ) => country.code === code )?.name ?? code;
	const code = ( label: string ) =>
		countries.find( ( country ) => country.name === label )?.code;

	return (
		<form
			onSubmit={ ( event ) => {
				event.preventDefault();
				setBusy( true );
				save( {
					customers,
					regions,
					countries: regions === 'list' ? listed : [],
				} )
					.catch( fail )
					.finally( () => setBusy( false ) );
			} }
		>
			<RadioControl
				label={ __( 'Who buys from the shop?', 'orillacart' ) }
				selected={ customers }
				options={ [
					{
						value: 'consumers',
						label: __( 'Consumers', 'orillacart' ),
					},
					{
						value: 'businesses',
						label: __( 'Businesses', 'orillacart' ),
					},
					{ value: 'both', label: __( 'Both', 'orillacart' ) },
				] }
				onChange={ setCustomers }
			/>
			<RadioControl
				label={ __( 'Where does the shop sell?', 'orillacart' ) }
				help={ __(
					'Checkout takes addresses only there. Shipping is priced for each part in the next steps.',
					'orillacart'
				) }
				selected={ regions }
				options={ [
					{
						value: 'home',
						label: __( 'Only in its own country', 'orillacart' ),
					},
					...( profile?.in_eu
						? [
								{
									value: 'eu',
									label: __( 'In the EU', 'orillacart' ),
								},
							]
						: [] ),
					{ value: 'europe', label: __( 'In Europe', 'orillacart' ) },
					{
						value: 'world',
						label: __( 'Everywhere', 'orillacart' ),
					},
					{
						value: 'list',
						label: __( 'In these countries', 'orillacart' ),
					},
				] }
				onChange={ setRegions }
			/>
			{ regions === 'list' && (
				<FormTokenField
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					label={ __( 'Countries', 'orillacart' ) }
					value={ listed.map( name ) }
					suggestions={ countries.map( ( country ) => country.name ) }
					onChange={ ( tokens ) =>
						setListed(
							tokens
								.map( ( token ) =>
									code(
										typeof token === 'string'
											? token
											: token.value
									)
								)
								.filter(
									( found ): found is string =>
										found !== undefined
								)
						)
					}
				/>
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
