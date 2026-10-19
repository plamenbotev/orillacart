import { Flex, FlexItem, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { Address } from './address';
import { knownCountries, type Country } from './geography';
import { LocationFields } from './LocationFields';

/**
 * The fields of a postal address, as orders and customer accounts keep it: the country and its state chosen from
 * lists, as the storefront's forms offer them, so no code is mistyped.
 *
 * @param props          The address and what to do when it changes.
 * @param props.address  The address being edited.
 * @param props.onChange Receives the changed address.
 */
export function AddressFields( {
	address,
	onChange,
}: {
	address: Address;
	onChange: ( address: Address ) => void;
} ) {
	const [ countries, setCountries ] = useState< Country[] >( [] );
	useEffect( () => {
		let current = true;
		knownCountries()
			.then(
				( loaded ) =>
					current &&
					setCountries( Array.isArray( loaded ) ? loaded : [] )
			)
			.catch( () => undefined );
		return () => {
			current = false;
		};
	}, [] );
	const field = ( key: Exclude< keyof Address, 'state' >, label: string ) => (
		<TextControl
			__next40pxDefaultSize
			label={ label }
			value={ address[ key ] }
			onChange={ ( value ) => onChange( { ...address, [ key ]: value } ) }
		/>
	);

	return (
		<>
			<Flex>
				<FlexItem isBlock>
					{ field( 'first_name', __( 'First name', 'orillacart' ) ) }
				</FlexItem>
				<FlexItem isBlock>
					{ field( 'last_name', __( 'Last name', 'orillacart' ) ) }
				</FlexItem>
			</Flex>
			{ field( 'street', __( 'Street', 'orillacart' ) ) }
			<Flex>
				<FlexItem isBlock>
					{ field( 'postcode', __( 'Postcode', 'orillacart' ) ) }
				</FlexItem>
				<FlexItem isBlock>
					{ field( 'city', __( 'City', 'orillacart' ) ) }
				</FlexItem>
			</Flex>
			<LocationFields
				countries={ countries }
				value={ {
					country: address.country === '' ? null : address.country,
					state: address.state,
				} }
				onChange={ ( location ) =>
					onChange( {
						...address,
						country: location.country ?? '',
						state: location.state,
					} )
				}
				countryLabel={ __( 'Country', 'orillacart' ) }
				stateLabel={ __( 'State', 'orillacart' ) }
				anyState={ __( '— Choose —', 'orillacart' ) }
			/>
			{ field( 'phone', __( 'Phone', 'orillacart' ) ) }
		</>
	);
}
