import {
	Button,
	Flex,
	FormTokenField,
	Modal,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { toApiError, violationOf, type Violation } from '../shared/api';
import { saveFilter, type SearchFilter } from './filters';

interface Props {
	/** The filter to change, or null to add one. */
	filter: SearchFilter | null;
	/** The attribute names products and attribute sets use. */
	names: string[];
	/** Where a new filter goes in the shop's order. */
	nextPosition: number;
	onSaved: ( filter: SearchFilter ) => void;
	onClose: () => void;
}

/**
 * "Size" becomes "size": the key the shop's addresses use.
 * @param label
 */
function keyOf( label: string ): string {
	return label
		.toLowerCase()
		.normalize( 'NFKD' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.slice( 0, 40 );
}

/**
 * Adds a shop filter or changes one: its label, its key in the shop's addresses, the attributes it covers,
 * and its place in the shop's order.
 * @param props
 */
export function FilterModal( props: Props ) {
	const { filter, names, nextPosition, onSaved, onClose } = props;
	const [ form, setForm ] = useState( {
		label: filter?.label ?? '',
		key: filter?.key ?? '',
		attributes: filter?.attributes ?? [],
		position: filter?.position ?? nextPosition,
	} );
	const [ keyEdited, setKeyEdited ] = useState( filter !== null );
	const [ violations, setViolations ] = useState< Violation[] >( [] );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		setViolations( [] );
		setError( null );
		saveFilter( filter?.id ?? null, form )
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
				filter === null
					? __( 'Add filter', 'orillacart' )
					: __( 'Edit filter', 'orillacart' )
			}
			onRequestClose={ onClose }
		>
			<form onSubmit={ submit } className="orillacart-filter-form">
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Label', 'orillacart' ) }
					help={
						violationOf( violations, 'label' ) ??
						__( 'What shoppers see, such as Size.', 'orillacart' )
					}
					value={ form.label }
					onChange={ ( label ) =>
						setForm( {
							...form,
							label,
							key: keyEdited ? form.key : keyOf( label ),
						} )
					}
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Key in the address', 'orillacart' ) }
					help={
						violationOf( violations, 'key' ) ??
						__(
							'Lowercase letters, digits and dashes: ?f[size][]=m.',
							'orillacart'
						)
					}
					value={ form.key }
					onChange={ ( key ) => {
						setKeyEdited( true );
						setForm( { ...form, key } );
					} }
				/>
				<FormTokenField
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					label={ __( 'Attributes it covers', 'orillacart' ) }
					help={
						violationOf( violations, 'attributes' ) ??
						__(
							'Products and attribute sets that name an attribute differently, such as Size and Размер, still share the filter.',
							'orillacart'
						)
					}
					value={ form.attributes }
					suggestions={ names }
					onChange={ ( tokens ) =>
						setForm( {
							...form,
							attributes: tokens.map( ( token ) =>
								typeof token === 'string' ? token : token.value
							),
						} )
					}
				/>
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={ __( 'Order', 'orillacart' ) }
					help={
						violationOf( violations, 'position' ) ??
						__(
							'Filters are listed from the lowest number up. A category can choose its own, in its own order.',
							'orillacart'
						)
					}
					value={ String( form.position ) }
					onChange={ ( value ) =>
						setForm( {
							...form,
							position: Math.max(
								0,
								Number.parseInt( value, 10 ) || 0
							),
						} )
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
						disabled={
							saving ||
							form.label.trim() === '' ||
							form.attributes.length === 0
						}
					>
						{ __( 'Save filter', 'orillacart' ) }
					</Button>
				</Flex>
			</form>
		</Modal>
	);
}
