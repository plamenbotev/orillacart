import { SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { fetchStates, type Country, type State } from './geography';

export interface Location {
	country: string | null;
	state: string | null;
}

/**
 * A country select and, for countries whose addresses have one, a state select. Changing the country
 * clears the state.
 */
interface Props {
	countries: Country[];
	value: Location;
	onChange: ( location: Location ) => void;
	countryLabel: string;
	stateLabel: string;
	/** Label of the empty state choice, such as "All states". */
	anyState: string;
	/** Whether "no country" is a choice. */
	optionalCountry?: boolean;
	countryError?: string | undefined;
	stateError?: string | undefined;
}

export function LocationFields( {
	countries,
	value,
	onChange,
	countryLabel,
	stateLabel,
	anyState,
	optionalCountry = false,
	countryError,
	stateError,
}: Props ) {
	const [ states, setStates ] = useState< State[] >( [] );
	const hasStates =
		countries.find( ( country ) => country.code === value.country )
			?.has_states ?? false;

	useEffect( () => {
		setStates( [] );
		if ( value.country === null || ! hasStates ) {
			return;
		}
		let current = true;
		fetchStates( value.country )
			.then( ( loaded ) => current && setStates( loaded ) )
			.catch( () => current && setStates( [] ) );

		return () => {
			current = false;
		};
	}, [ value.country, hasStates ] );

	const countryOptions = countries.map( ( country ) => ( {
		label: country.name,
		value: country.code,
	} ) );

	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				label={ countryLabel }
				help={ countryError }
				value={ value.country ?? '' }
				options={
					optionalCountry || value.country === null
						? [
								{
									label: __( '— Not set —', 'orillacart' ),
									value: '',
								},
								...countryOptions,
							]
						: countryOptions
				}
				onChange={ ( country ) =>
					onChange( {
						country: country === '' ? null : country,
						state: null,
					} )
				}
			/>
			{ hasStates && (
				<SelectControl
					__next40pxDefaultSize
					label={ stateLabel }
					help={ stateError }
					value={ value.state ?? '' }
					options={ [
						{ label: anyState, value: '' },
						...states.map( ( state ) => ( {
							label: state.name,
							value: state.code,
						} ) ),
					] }
					onChange={ ( state ) =>
						onChange( {
							country: value.country,
							state: state === '' ? null : state,
						} )
					}
				/>
			) }
		</>
	);
}
