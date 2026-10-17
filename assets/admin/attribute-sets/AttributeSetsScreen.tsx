import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { AttributesEditor } from '../catalog/AttributesEditor';
import {
	deleteAttributeSet,
	fetchAttributeSets,
	saveAttributeSet,
	toAttributeInput,
	type AttributeSet,
	type AttributeSetInput,
} from '../catalog/api';
import { toApiError } from '../shared/api';

type Message = { status: 'success' | 'error'; text: string } | null;

const NEW = 'new';

/**
 * The Shop → Attribute sets screen: attributes that several products share.
 */
export function AttributeSetsScreen() {
	const [ sets, setSets ] = useState< AttributeSet[] | null >( null );
	const [ selected, setSelected ] = useState< number | typeof NEW | null >(
		null
	);
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) => {
		const { message: text, data } = toApiError( error );
		const details = ( data?.violations ?? [] ).map(
			( violation ) => `${ violation.field }: ${ violation.message }`
		);
		setMessage( {
			status: 'error',
			text: [ text, ...details ].join( ' ' ),
		} );
	};

	useEffect( () => {
		fetchAttributeSets()
			.then( ( loaded ) => {
				setSets( loaded );
				setSelected( loaded[ 0 ]?.id ?? NEW );
			} )
			.catch( fail );
	}, [] );

	if ( sets === null ) {
		return (
			<>
				<h1>{ __( 'Attribute sets', 'orillacart' ) }</h1>
				{ message !== null ? (
					<Notice status={ message.status } isDismissible={ false }>
						{ message.text }
					</Notice>
				) : (
					<Spinner />
				) }
			</>
		);
	}

	const set = sets.find( ( candidate ) => candidate.id === selected );

	return (
		<>
			<h1>{ __( 'Attribute sets', 'orillacart' ) }</h1>
			<p>
				{ __(
					'Attributes that several products share, such as size for clothing. A product offers the attributes of the sets you choose for it, while they are active.',
					'orillacart'
				) }
			</p>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			<Flex wrap justify="flex-start">
				{ sets.map( ( candidate ) => (
					<Button
						key={ candidate.id }
						variant={
							candidate.id === selected ? 'primary' : 'secondary'
						}
						onClick={ () => setSelected( candidate.id ) }
					>
						{ candidate.name }
					</Button>
				) ) }
				<Button
					variant={ selected === NEW ? 'primary' : 'secondary' }
					onClick={ () => setSelected( NEW ) }
				>
					{ __( 'New set', 'orillacart' ) }
				</Button>
			</Flex>
			{ ( set !== undefined || selected === NEW ) && (
				<SetCard
					key={ set?.id ?? NEW }
					set={ set ?? null }
					onSaved={ ( saved ) => {
						setSets( [
							...sets.filter(
								( candidate ) => candidate.id !== saved.id
							),
							saved,
						] );
						setSelected( saved.id );
						setMessage( {
							status: 'success',
							text: __( 'Attribute set saved.', 'orillacart' ),
						} );
					} }
					onDeleted={ ( id ) => {
						const rest = sets.filter(
							( candidate ) => candidate.id !== id
						);
						setSets( rest );
						setSelected( rest[ 0 ]?.id ?? NEW );
						setMessage( null );
					} }
					onError={ fail }
				/>
			) }
		</>
	);
}

interface SetCardProps {
	/** Null for a set not created yet. */
	set: AttributeSet | null;
	onSaved: ( set: AttributeSet ) => void;
	onDeleted: ( id: number ) => void;
	onError: ( error: unknown ) => void;
}

function SetCard( { set, onSaved, onDeleted, onError }: SetCardProps ) {
	const [ draft, setDraft ] = useState< AttributeSetInput >( {
		name: set?.name ?? '',
		active: set?.active ?? true,
		attributes: ( set?.attributes ?? [] ).map( toAttributeInput ),
	} );
	const [ saving, setSaving ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		saveAttributeSet( set?.id ?? null, draft )
			.then( onSaved )
			.catch( onError )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ set?.name ?? __( 'New attribute set', 'orillacart' ) }
				</h2>
				{ set !== null &&
					( confirming ? (
						<Flex justify="flex-end" expanded={ false }>
							<Button
								variant="primary"
								isDestructive
								onClick={ () =>
									deleteAttributeSet( set.id )
										.then( () => onDeleted( set.id ) )
										.catch( onError )
								}
							>
								{ __( 'Delete the set', 'orillacart' ) }
							</Button>
							<Button
								variant="tertiary"
								onClick={ () => setConfirming( false ) }
							>
								{ __( 'Keep it', 'orillacart' ) }
							</Button>
						</Flex>
					) : (
						<Button
							variant="secondary"
							isDestructive
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Delete set', 'orillacart' ) }
						</Button>
					) ) }
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Set name', 'orillacart' ) }
						value={ draft.name }
						onChange={ ( name ) => setDraft( { ...draft, name } ) }
					/>
					<CheckboxControl
						label={ __( 'Active', 'orillacart' ) }
						help={ __(
							'Products offer the attributes of active sets only.',
							'orillacart'
						) }
						checked={ draft.active }
						onChange={ ( active ) =>
							setDraft( { ...draft, active } )
						}
					/>
					<AttributesEditor
						attributes={ draft.attributes }
						onChange={ ( attributes ) =>
							setDraft( { ...draft, attributes } )
						}
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving || draft.name.trim() === '' }
					>
						{ __( 'Save attribute set', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
