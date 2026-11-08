import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchRecordFields,
	saveRecordFields,
	type ExtensionValues,
	type FieldSection,
	type FieldSpec,
	type RecordKind,
} from './extensionFields';
import { Section, useSave } from './ui';
import './extension-fields.css';

type Draft = Record< string, string | boolean >;

/**
 * The fields the enabled extensions declare on a kind, read once for the screen.
 *
 * @param kind The kind.
 * @return The sections; empty until read, and when no extension declares any.
 */
export function useRecordFields( kind: RecordKind ): FieldSection[] {
	const [ sections, setSections ] = useState< FieldSection[] >( [] );
	useEffect( () => {
		let current = true;
		fetchRecordFields( kind )
			.then( ( found ) => {
				if ( current ) {
					setSections( found );
				}
			} )
			.catch( () => {
				// The record's screen works without them.
			} );
		return () => {
			current = false;
		};
	}, [ kind ] );
	return sections;
}

/**
 * A stored value as the control shows it.
 *
 * @param spec  The field.
 * @param value The value.
 * @return The control's value.
 */
function toDraft( spec: FieldSpec, value: unknown ): string | boolean {
	if ( spec.kind === 'boolean' ) {
		return Boolean( value );
	}
	if ( value === null || value === undefined ) {
		return '';
	}
	if ( Array.isArray( value ) ) {
		return spec.kind === 'list'
			? value.map( String ).join( '\n' )
			: JSON.stringify( value, null, 2 );
	}
	if ( typeof value === 'object' ) {
		const ref = value as { $ref?: string; id?: unknown };
		if ( ref.$ref ) {
			return String( ref.id ?? '' );
		}
		return JSON.stringify( value, null, 2 );
	}
	if ( spec.kind === 'datetime' ) {
		return String( value ).slice( 0, 16 );
	}
	return String( value );
}

/**
 * The control's value as the field takes it.
 *
 * @param spec  The field.
 * @param draft The control's value.
 * @return The value to save; null for an empty control.
 * @throws Error for text the field does not take.
 */
function fromDraft( spec: FieldSpec, draft: string | boolean ): unknown {
	if ( typeof draft === 'boolean' ) {
		return draft;
	}
	const text = draft.trim();
	if ( text === '' ) {
		return null;
	}
	switch ( spec.kind ) {
		case 'number':
		case 'product':
		case 'order':
		case 'customer':
		case 'object':
			if ( Number.isNaN( Number( text ) ) ) {
				throw new Error(
					sprintf(
						/* translators: %s: the field's label */
						__( '%s takes a number.', 'orillacart' ),
						spec.label
					)
				);
			}
			return Number( text );
		case 'list':
			return text
				.split( '\n' )
				.map( ( line ) => line.trim() )
				.filter( ( line ) => line !== '' );
		case 'map':
			try {
				return JSON.parse( text );
			} catch {
				throw new Error(
					sprintf(
						/* translators: %s: the field's label */
						__( '%s takes JSON of a map.', 'orillacart' ),
						spec.label
					)
				);
			}
		default:
			return text;
	}
}

type InputType = 'text' | 'number' | 'date' | 'datetime-local';

const INPUT_TYPES: Record< string, InputType > = {
	number: 'number',
	product: 'number',
	order: 'number',
	customer: 'number',
	object: 'number',
	date: 'date',
	datetime: 'datetime-local',
};

/**
 * One field's control, by its kind.
 *
 * @param props
 * @param props.spec     The field.
 * @param props.value    The control's value.
 * @param props.onChange Receives the new value.
 */
function FieldControl( {
	spec,
	value,
	onChange,
}: {
	spec: FieldSpec;
	value: string | boolean;
	onChange: ( value: string | boolean ) => void;
} ) {
	const help =
		spec.help ??
		( spec.required ? __( 'Required.', 'orillacart' ) : undefined );
	if ( spec.kind === 'boolean' ) {
		return (
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ spec.label }
				help={ help }
				checked={ value === true }
				disabled={ spec.readonly }
				onChange={ onChange }
			/>
		);
	}
	const text = typeof value === 'string' ? value : '';
	if ( spec.kind === 'choice' ) {
		return (
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ spec.label }
				help={ help }
				value={ text }
				disabled={ spec.readonly }
				options={ [
					{ label: '—', value: '' },
					...spec.of.map( ( choice ) => ( {
						label: choice,
						value: choice,
					} ) ),
				] }
				onChange={ onChange }
			/>
		);
	}
	if ( spec.kind === 'list' || spec.kind === 'map' ) {
		return (
			<TextareaControl
				__nextHasNoMarginBottom
				label={ spec.label }
				help={
					help ??
					( spec.kind === 'list'
						? __( 'One item per line.', 'orillacart' )
						: __( 'As JSON.', 'orillacart' ) )
				}
				value={ text }
				disabled={ spec.readonly }
				rows={ 3 }
				onChange={ onChange }
			/>
		);
	}
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ spec.label }
			help={ help }
			type={ INPUT_TYPES[ spec.kind ] ?? 'text' }
			inputMode={ spec.kind === 'money' ? 'decimal' : undefined }
			value={ text }
			disabled={ spec.readonly }
			onChange={ onChange }
		/>
	);
}

interface BlockProps {
	kind: RecordKind;
	id: number;
	section: FieldSection;
	values: Record< string, unknown >;
	onError: ( error: unknown ) => void;
}

/**
 * One extension's fields on the record, with their Save.
 *
 * @param props
 * @param props.kind    The record's kind.
 * @param props.id      The record's id.
 * @param props.section What the extension declared.
 * @param props.values  The extension's values on the record.
 * @param props.onError Receives a save that failed.
 */
function Block( { kind, id, section, values, onError }: BlockProps ) {
	const initial = useMemo( () => {
		const draft: Draft = {};
		for ( const spec of section.fields ) {
			draft[ spec.name ] = toDraft( spec, values[ spec.name ] );
		}
		return draft;
	}, [ section, values ] );
	const [ draft, setDraft ] = useState< Draft >( initial );
	useEffect( () => setDraft( initial ), [ initial, id ] );
	const [ busy, save ] = useSave( onError );
	const editable = section.fields.some( ( spec ) => ! spec.readonly );

	const submit = () => {
		const fields: Record< string, unknown > = {};
		try {
			for ( const spec of section.fields ) {
				if ( ! spec.readonly ) {
					fields[ spec.name ] = fromDraft(
						spec,
						draft[ spec.name ] ?? ''
					);
				}
			}
		} catch ( error ) {
			onError( error );
			return;
		}
		void save(
			() => saveRecordFields( kind, id, section.extension, fields ),
			__( 'Saved.', 'orillacart' )
		).then( ( saved ) => {
			if ( saved ) {
				const next: Draft = {};
				for ( const spec of section.fields ) {
					next[ spec.name ] = toDraft( spec, saved[ spec.name ] );
				}
				setDraft( next );
			}
		} );
	};

	return (
		<div
			className="oc-extension-fields__block"
			data-extension={ section.extension }
		>
			<h3 className="oc-extension-fields__title">{ section.name }</h3>
			{ section.fields.map( ( spec ) => (
				<div key={ spec.name } className="oc-extension-fields__field">
					<FieldControl
						spec={ spec }
						value={ draft[ spec.name ] ?? '' }
						onChange={ ( value ) =>
							setDraft( ( d ) => ( {
								...d,
								[ spec.name ]: value,
							} ) )
						}
					/>
				</div>
			) ) }
			{ editable && (
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ submit }
				>
					{ sprintf(
						/* translators: %s: the extension's name */
						__( 'Save %s', 'orillacart' ),
						section.name
					) }
				</Button>
			) }
		</div>
	);
}

interface BlocksProps {
	kind: RecordKind;
	id: number;
	sections: FieldSection[];
	values?: ExtensionValues | null | undefined;
	onError: ( error: unknown ) => void;
}

/**
 * The extensions' fields on a record: one block per extension that declares any, built from the declaration.
 *
 * @param props
 * @param props.kind     The record's kind.
 * @param props.id       The record's id.
 * @param props.sections What the extensions declared, from useRecordFields().
 * @param props.values   The record's values, from its view.
 * @param props.onError  Receives a save that failed.
 */
export function ExtensionFieldBlocks( {
	kind,
	id,
	sections,
	values,
	onError,
}: BlocksProps ) {
	return (
		<div className="oc-extension-fields">
			{ sections.map( ( section ) => (
				<Block
					key={ section.extension }
					kind={ kind }
					id={ id }
					section={ section }
					values={ values?.[ section.extension ] ?? {} }
					onError={ onError }
				/>
			) ) }
		</div>
	);
}

/**
 * The "Extensions" section of a record's screen; nothing when no enabled extension declares fields on the kind.
 *
 * @param props
 * @param props.kind    The record's kind.
 * @param props.id      The record's id.
 * @param props.values  The record's values, from its view.
 * @param props.onError Receives a save that failed.
 */
export function ExtensionFieldsSection( {
	kind,
	id,
	values,
	onError,
}: Omit< BlocksProps, 'sections' > ) {
	const sections = useRecordFields( kind );
	if ( sections.length === 0 ) {
		return null;
	}
	return (
		<Section title={ __( 'Extensions', 'orillacart' ) }>
			<ExtensionFieldBlocks
				kind={ kind }
				id={ id }
				sections={ sections }
				values={ values }
				onError={ onError }
			/>
		</Section>
	);
}
