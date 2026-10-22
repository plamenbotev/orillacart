import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	FILE_TYPES,
	newField,
	slugOf,
	type FieldKind,
	type FormField,
	type Placement,
	type Section,
} from './api';

/**
 * A field as the editor holds it: one not saved yet takes its key from its label, and each new choice its value,
 * so a condition can name them before the form is saved.
 */
export interface FieldDraft {
	field: FormField;
	fresh: boolean;
	freshChoices: boolean[];
}

export function draftsOf( fields: FormField[] ): FieldDraft[] {
	return fields.map( ( field ) => ( {
		field,
		fresh: false,
		freshChoices: field.choices.map( () => false ),
	} ) );
}

const KINDS: { value: FieldKind; label: string }[] = [
	{ value: 'text', label: __( 'Text', 'orillacart' ) },
	{ value: 'textarea', label: __( 'Long text', 'orillacart' ) },
	{ value: 'number', label: __( 'Number', 'orillacart' ) },
	{ value: 'date', label: __( 'Date', 'orillacart' ) },
	{ value: 'checkbox', label: __( 'Tick box', 'orillacart' ) },
	{ value: 'choice', label: __( 'Choice', 'orillacart' ) },
	{ value: 'file', label: __( 'File upload', 'orillacart' ) },
];

const TYPE_NAMES: Record< string, string > = {
	'image/jpeg': 'JPEG',
	'image/png': 'PNG',
	'image/webp': 'WebP',
	'image/gif': 'GIF',
	'application/pdf': 'PDF',
};

const amountOrNull = ( value: string ): string | null =>
	value.trim() === '' ? null : value.trim();

interface Props {
	placement: Placement;
	drafts: FieldDraft[];
	onChange: ( drafts: FieldDraft[] ) => void;
}

/**
 * The questions of a form, in order: what each asks, how, and when. Used for the product page's forms and for
 * checkout's, which differ only in prices and files (product page) and the part of checkout (checkout).
 *
 * @param props           The editor's properties.
 * @param props.placement Where the form is asked.
 * @param props.drafts    The questions, in order.
 * @param props.onChange  Takes the questions changed.
 */
export function FieldsEditor( { placement, drafts, onChange }: Props ) {
	const [ open, setOpen ] = useState< number | null >( null );
	const kinds = KINDS.filter(
		( kind ) => placement === 'product' || kind.value !== 'file'
	);

	const change = ( index: number, next: FieldDraft ) => {
		const before = drafts[ index ]?.field;
		if ( before === undefined ) {
			return;
		}
		// A new field's key and its new choices' values follow their labels: conditions on them follow too.
		const renamed = ( value: string ) => {
			const at = before.choices.findIndex(
				( choice ) => choice.value === value
			);
			return at === -1
				? value
				: ( next.field.choices[ at ]?.value ?? value );
		};
		onChange(
			drafts.map( ( draft, at ) => {
				if ( at === index ) {
					return next;
				}
				const condition = draft.field.condition;
				if ( condition === null || condition.field !== before.key ) {
					return draft;
				}
				return {
					...draft,
					field: {
						...draft.field,
						condition: {
							field: next.field.key,
							values: condition.values.map( renamed ),
						},
					},
				};
			} )
		);
	};
	const move = ( index: number, by: number ) => {
		const next = [ ...drafts ];
		const [ moved ] = next.splice( index, 1 );
		if ( moved === undefined ) {
			return;
		}
		next.splice( index + by, 0, moved );
		// A condition looks at a field above it: one moved below what it looks at loses its condition.
		onChange( withoutBrokenConditions( next ) );
		setOpen( index + by );
	};
	const remove = ( index: number ) => {
		onChange(
			withoutBrokenConditions(
				drafts.filter( ( _, at ) => at !== index )
			)
		);
		setOpen( null );
	};

	return (
		<div className="orillacart-fields-editor">
			{ drafts.length === 0 && (
				<p>{ __( 'No questions yet.', 'orillacart' ) }</p>
			) }
			{ drafts.map( ( draft, index ) => (
				<div
					key={ index }
					className="orillacart-fields-editor__field"
					style={ {
						border: '1px solid #dcdcde',
						borderRadius: 2,
						padding: '8px 12px',
						marginBottom: 8,
					} }
				>
					<Flex>
						<FlexItem>
							<strong>
								{ draft.field.label ||
									__( '(no label yet)', 'orillacart' ) }
							</strong>{ ' ' }
							<span>
								{ KINDS.find(
									( kind ) => kind.value === draft.field.kind
								)?.label ?? draft.field.kind }
								{ draft.field.required
									? ` · ${ __( 'required', 'orillacart' ) }`
									: '' }
								{ draft.field.condition !== null
									? ` · ${ __( 'conditional', 'orillacart' ) }`
									: '' }
							</span>
						</FlexItem>
						<FlexItem>
							<Button
								size="small"
								variant="tertiary"
								disabled={ index === 0 }
								label={ __( 'Move up', 'orillacart' ) }
								onClick={ () => move( index, -1 ) }
							>
								↑
							</Button>
							<Button
								size="small"
								variant="tertiary"
								disabled={ index === drafts.length - 1 }
								label={ __( 'Move down', 'orillacart' ) }
								onClick={ () => move( index, 1 ) }
							>
								↓
							</Button>
							<Button
								size="small"
								variant="secondary"
								aria-expanded={ open === index }
								onClick={ () =>
									setOpen( open === index ? null : index )
								}
							>
								{ open === index
									? __( 'Close', 'orillacart' )
									: __( 'Edit', 'orillacart' ) }
							</Button>
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								onClick={ () => remove( index ) }
							>
								{ __( 'Remove', 'orillacart' ) }
							</Button>
						</FlexItem>
					</Flex>
					{ open === index && (
						<FieldForm
							placement={ placement }
							kinds={ kinds }
							draft={ draft }
							earlier={ drafts.slice( 0, index ) }
							taken={ drafts
								.filter( ( _, at ) => at !== index )
								.map( ( other ) => other.field.key ) }
							onChange={ ( next ) => change( index, next ) }
						/>
					) }
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => {
					onChange( [
						...drafts,
						{ field: newField(), fresh: true, freshChoices: [] },
					] );
					setOpen( drafts.length );
				} }
			>
				{ __( 'Add a question', 'orillacart' ) }
			</Button>
		</div>
	);
}

function withoutBrokenConditions( drafts: FieldDraft[] ): FieldDraft[] {
	return drafts.map( ( draft, index ) => {
		const condition = draft.field.condition;
		if (
			condition === null ||
			drafts
				.slice( 0, index )
				.some( ( other ) => other.field.key === condition.field )
		) {
			return draft;
		}
		return { ...draft, field: { ...draft.field, condition: null } };
	} );
}

interface FieldFormProps {
	placement: Placement;
	kinds: { value: FieldKind; label: string }[];
	draft: FieldDraft;
	/** The fields above it, which a condition may look at. */
	earlier: FieldDraft[];
	/** The other fields' keys. */
	taken: string[];
	onChange: ( draft: FieldDraft ) => void;
}

function FieldForm( {
	placement,
	kinds,
	draft,
	earlier,
	taken,
	onChange,
}: FieldFormProps ) {
	const { field } = draft;
	const set = ( changes: Partial< FormField > ) =>
		onChange( { ...draft, field: { ...field, ...changes } } );
	const priced = placement === 'product';
	const conditionField = earlier.find(
		( other ) => other.field.key === field.condition?.field
	)?.field;

	return (
		<div style={ { marginTop: 12 } }>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Question', 'orillacart' ) }
				value={ field.label }
				maxLength={ 100 }
				onChange={ ( label ) =>
					set(
						draft.fresh
							? { label, key: slugOf( label, '_', 30, taken ) }
							: { label }
					)
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Kind', 'orillacart' ) }
				value={ field.kind }
				options={ kinds }
				onChange={ ( kind ) =>
					set( {
						kind: kind as FieldKind,
						longest: kind === 'textarea' ? 1000 : 200,
					} )
				}
			/>
			<CheckboxControl
				label={
					field.kind === 'checkbox'
						? __( 'Has to be ticked', 'orillacart' )
						: __( 'Required', 'orillacart' )
				}
				checked={ field.required }
				onChange={ ( required ) => set( { required } ) }
			/>
			<TextareaControl
				label={ __( 'Help shown under it', 'orillacart' ) }
				value={ field.help }
				rows={ 2 }
				onChange={ ( help ) => set( { help } ) }
			/>
			{ [ 'text', 'textarea', 'number' ].includes( field.kind ) && (
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Placeholder', 'orillacart' ) }
					value={ field.placeholder }
					onChange={ ( placeholder ) => set( { placeholder } ) }
				/>
			) }
			{ [ 'text', 'textarea' ].includes( field.kind ) && (
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					max={ 2000 }
					label={ __( 'Most characters', 'orillacart' ) }
					value={ String( field.longest ) }
					onChange={ ( longest ) =>
						set( { longest: Number( longest ) || 1 } )
					}
				/>
			) }
			{ field.kind === 'number' && (
				<Flex align="flex-end">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Lowest', 'orillacart' ) }
						value={ field.min ?? '' }
						onChange={ ( min ) =>
							set( { min: amountOrNull( min ) } )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Highest', 'orillacart' ) }
						value={ field.max ?? '' }
						onChange={ ( max ) =>
							set( { max: amountOrNull( max ) } )
						}
					/>
					<CheckboxControl
						label={ __( 'Whole numbers', 'orillacart' ) }
						checked={ field.whole }
						onChange={ ( whole ) => set( { whole } ) }
					/>
				</Flex>
			) }
			{ field.kind === 'choice' && (
				<ChoicesEditor
					draft={ draft }
					priced={ priced }
					onChange={ onChange }
				/>
			) }
			{ field.kind === 'file' && (
				<fieldset>
					<legend>{ __( 'Files it takes', 'orillacart' ) }</legend>
					{ FILE_TYPES.map( ( type ) => (
						<CheckboxControl
							key={ type }
							label={ TYPE_NAMES[ type ] ?? type }
							checked={ field.types.includes( type ) }
							onChange={ ( on ) =>
								set( {
									types: on
										? FILE_TYPES.filter(
												( known ) =>
													known === type ||
													field.types.includes(
														known
													)
											)
										: field.types.filter(
												( known ) => known !== type
											),
								} )
							}
						/>
					) ) }
					<TextControl
						__next40pxDefaultSize
						type="number"
						min={ 1 }
						max={ 20 }
						label={ __(
							'Largest file, in MB (up to 20)',
							'orillacart'
						) }
						value={ String( field.largest ) }
						onChange={ ( largest ) =>
							set( {
								largest: Math.min(
									20,
									Math.max( 1, Number( largest ) || 1 )
								),
							} )
						}
					/>
					<p>
						{ __(
							'Pictures are written again as new files and PDFs that run anything are refused. Only shop managers can download what shoppers send, from the order.',
							'orillacart'
						) }
					</p>
				</fieldset>
			) }
			{ priced && field.kind !== 'choice' && (
				<TextControl
					__next40pxDefaultSize
					label={ __(
						'Price added to each item when answered',
						'orillacart'
					) }
					help={ __( 'Leave empty for none.', 'orillacart' ) }
					value={ field.price ?? '' }
					onChange={ ( price ) =>
						set( { price: amountOrNull( price ) } )
					}
				/>
			) }
			{ placement === 'checkout' && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Part of checkout', 'orillacart' ) }
					value={ field.section }
					options={ [
						{
							value: 'billing',
							label: __(
								'With the billing address',
								'orillacart'
							),
						},
						{
							value: 'shipping',
							label: __(
								'With the shipping address (orders that ship)',
								'orillacart'
							),
						},
						{
							value: 'order',
							label: __( 'With the order note', 'orillacart' ),
						},
					] }
					onChange={ ( section ) =>
						set( { section: section as Section } )
					}
				/>
			) }
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Ask it', 'orillacart' ) }
				value={ field.condition?.field ?? '' }
				options={ [
					{ value: '', label: __( 'Always', 'orillacart' ) },
					...earlier
						.filter( ( other ) => other.field.label !== '' )
						.map( ( other ) => ( {
							value: other.field.key,
							label: sprintf(
								/* translators: %s: another question of the form */
								__(
									'Only when "%s" is answered',
									'orillacart'
								),
								other.field.label
							),
						} ) ),
				] }
				onChange={ ( key ) =>
					set( {
						condition:
							key === '' ? null : { field: key, values: [] },
					} )
				}
			/>
			{ conditionField?.kind === 'choice' && field.condition !== null && (
				<fieldset>
					<legend>
						{ __(
							'With one of these answers (none ticked: any answer)',
							'orillacart'
						) }
					</legend>
					{ conditionField.choices.map( ( choice ) => (
						<CheckboxControl
							key={ choice.value }
							label={ choice.label }
							checked={
								field.condition?.values.includes(
									choice.value
								) ?? false
							}
							onChange={ ( on ) => {
								const values = field.condition?.values ?? [];
								set( {
									condition: {
										field: conditionField.key,
										values: on
											? [ ...values, choice.value ]
											: values.filter(
													( value ) =>
														value !== choice.value
												),
									},
								} );
							} }
						/>
					) ) }
				</fieldset>
			) }
		</div>
	);
}

function ChoicesEditor( {
	draft,
	priced,
	onChange,
}: {
	draft: FieldDraft;
	priced: boolean;
	onChange: ( draft: FieldDraft ) => void;
} ) {
	const { field } = draft;
	const setChoices = ( choices: FormField[ 'choices' ], fresh: boolean[] ) =>
		onChange( {
			...draft,
			field: { ...field, choices },
			freshChoices: fresh,
		} );

	return (
		<fieldset>
			<legend>{ __( 'Choices', 'orillacart' ) }</legend>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Shown as', 'orillacart' ) }
				value={ field.display }
				options={ [
					{
						value: 'list',
						label: __( 'A drop-down list', 'orillacart' ),
					},
					{ value: 'buttons', label: __( 'Buttons', 'orillacart' ) },
				] }
				onChange={ ( display ) =>
					onChange( {
						...draft,
						field: {
							...field,
							display: display as 'list' | 'buttons',
						},
					} )
				}
			/>
			{ field.choices.map( ( choice, index ) => (
				<Flex key={ index } align="flex-end">
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Choice', 'orillacart' ) }
						value={ choice.label }
						maxLength={ 100 }
						onChange={ ( label ) =>
							setChoices(
								field.choices.map( ( other, at ) =>
									at !== index
										? other
										: {
												...other,
												label,
												value: draft.freshChoices[
													index
												]
													? slugOf(
															label,
															'-',
															40,
															field.choices
																.filter(
																	( _, i ) =>
																		i !==
																		index
																)
																.map(
																	( c ) =>
																		c.value
																)
														)
													: other.value,
											}
								),
								draft.freshChoices
							)
						}
					/>
					{ priced && (
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Price added', 'orillacart' ) }
							value={ choice.price ?? '' }
							onChange={ ( price ) =>
								setChoices(
									field.choices.map( ( other, at ) =>
										at === index
											? {
													...other,
													price: amountOrNull(
														price
													),
												}
											: other
									),
									draft.freshChoices
								)
							}
						/>
					) }
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							setChoices(
								field.choices.filter(
									( _, at ) => at !== index
								),
								draft.freshChoices.filter(
									( _, at ) => at !== index
								)
							)
						}
					>
						{ __( 'Remove', 'orillacart' ) }
					</Button>
				</Flex>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					setChoices(
						[
							...field.choices,
							{ value: '', label: '', price: null },
						],
						[ ...draft.freshChoices, true ]
					)
				}
			>
				{ __( 'Add a choice', 'orillacart' ) }
			</Button>
		</fieldset>
	);
}
