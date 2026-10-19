import {
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/** A setting a payment gateway or shipping method declares (OrillaCart\Extension\Setting). */
export interface Setting {
	key: string;
	label: string;
	type: 'text' | 'secret' | 'boolean' | 'number' | 'choice';
	required: boolean;
	help: string;
	/** For a choice: value => label. */
	choices: Record< string, string >;
}

export type SettingValues = Record< string, string | boolean >;

/**
 * The fields of a gateway's or shipping method's settings, as it declares them. Secrets come back empty; a
 * saved one says so and stays unless a new one is typed.
 *
 * @param props            The fields, values and what to do when one changes.
 * @param props.fields     The declared settings.
 * @param props.values     The values being edited.
 * @param props.secretsSet The keys of the secrets that are saved.
 * @param props.onChange   Receives a changed value.
 */
export function SettingFields( {
	fields,
	values,
	secretsSet,
	onChange,
}: {
	fields: Setting[];
	values: SettingValues;
	secretsSet: string[];
	onChange: ( key: string, value: string | boolean ) => void;
} ) {
	return (
		<>
			{ fields.map( ( field ) => {
				if ( field.type === 'boolean' ) {
					return (
						<CheckboxControl
							key={ field.key }
							label={ field.label }
							help={ field.help }
							checked={ values[ field.key ] === true }
							onChange={ ( checked ) =>
								onChange( field.key, checked )
							}
						/>
					);
				}
				if ( field.type === 'choice' ) {
					return (
						<SelectControl
							key={ field.key }
							__next40pxDefaultSize
							label={ field.label }
							help={ field.help }
							value={ String( values[ field.key ] ?? '' ) }
							options={ [
								{
									label: __( '— Choose —', 'orillacart' ),
									value: '',
								},
								...Object.entries( field.choices ).map(
									( [ value, label ] ) => ( { value, label } )
								),
							] }
							onChange={ ( value ) =>
								onChange( field.key, value )
							}
						/>
					);
				}
				const saved =
					field.type === 'secret' && secretsSet.includes( field.key );
				// A saved secret sent as false is cleared; sent empty, it stays.
				const clearing = saved && values[ field.key ] === false;
				return (
					<div key={ field.key }>
						<TextControl
							__next40pxDefaultSize
							type={
								field.type === 'secret' ? 'password' : 'text'
							}
							inputMode={
								field.type === 'number' ? 'decimal' : undefined
							}
							autoComplete="off"
							label={ field.label }
							help={
								saved
									? __(
											'Saved. Leave empty to keep it.',
											'orillacart'
										)
									: field.help
							}
							disabled={ clearing }
							value={
								clearing
									? ''
									: String( values[ field.key ] ?? '' )
							}
							onChange={ ( value ) =>
								onChange( field.key, value )
							}
						/>
						{ saved && (
							<CheckboxControl
								label={ __(
									'Clear the saved one',
									'orillacart'
								) }
								checked={ clearing }
								onChange={ ( checked ) =>
									onChange( field.key, checked ? false : '' )
								}
							/>
						) }
					</div>
				);
			} ) }
		</>
	);
}
