import { Flex, FlexItem, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import type { Address } from './address';

/**
 * The fields of a postal address, as orders and customer accounts keep it.
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
			<Flex>
				<FlexItem isBlock>
					{ field( 'country', __( 'Country code', 'orillacart' ) ) }
				</FlexItem>
				<FlexItem isBlock>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'State code', 'orillacart' ) }
						value={ address.state ?? '' }
						onChange={ ( value ) =>
							onChange( {
								...address,
								state:
									value === '' ? null : value.toUpperCase(),
							} )
						}
					/>
				</FlexItem>
			</Flex>
			{ field( 'phone', __( 'Phone', 'orillacart' ) ) }
		</>
	);
}
