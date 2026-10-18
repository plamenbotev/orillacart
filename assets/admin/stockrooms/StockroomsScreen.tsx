import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	Spinner,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import {
	deleteStockroom,
	fetchStockrooms,
	saveStockroom,
	type Stockroom,
	type StockroomInput,
} from '../inventory/api';
import { toApiError } from '../shared/api';

type Message = { status: 'success' | 'error'; text: string } | null;

const EMPTY: StockroomInput = {
	name: '',
	description: '',
	min_delivery_days: 0,
	max_delivery_days: 0,
	delivery_unit: 'days',
	active: true,
};

/**
 * The Shop → Stockrooms screen: the places the shop keeps stock and ships from.
 */
export function StockroomsScreen() {
	const [ stockrooms, setStockrooms ] = useState< Stockroom[] | null >(
		null
	);
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );

	useEffect( () => {
		fetchStockrooms().then( setStockrooms ).catch( fail );
	}, [] );

	if ( stockrooms === null ) {
		return (
			<>
				<h1>{ __( 'Stockrooms', 'orillacart' ) }</h1>
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

	const replace = ( saved: Stockroom ) =>
		setStockrooms(
			[
				...stockrooms.filter( ( current ) => current.id !== saved.id ),
				saved,
			].sort( ( a, b ) => a.name.localeCompare( b.name ) )
		);

	return (
		<>
			<h1>{ __( 'Stockrooms', 'orillacart' ) }</h1>
			<p>
				{ __(
					'Orders take stock from the active stockroom that delivers fastest first. Stock in inactive stockrooms is not sold.',
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
			{ stockrooms.map( ( stockroom ) => (
				<StockroomCard
					key={ stockroom.id }
					stockroom={ stockroom }
					onSaved={ ( saved ) => {
						replace( saved );
						setMessage( {
							status: 'success',
							text: __( 'Stockroom saved.', 'orillacart' ),
						} );
					} }
					onDeleted={ () =>
						setStockrooms(
							stockrooms.filter(
								( current ) => current.id !== stockroom.id
							)
						)
					}
					onError={ fail }
				/>
			) ) }
			<StockroomCard
				key={ `new-${ stockrooms.length }` }
				stockroom={ null }
				onSaved={ ( saved ) => {
					replace( saved );
					setMessage( {
						status: 'success',
						text: __( 'Stockroom added.', 'orillacart' ),
					} );
				} }
				onDeleted={ () => undefined }
				onError={ fail }
			/>
		</>
	);
}

interface StockroomCardProps {
	/** Null for the form that adds a stockroom. */
	stockroom: Stockroom | null;
	onSaved: ( stockroom: Stockroom ) => void;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
}

function StockroomCard( {
	stockroom,
	onSaved,
	onDeleted,
	onError,
}: StockroomCardProps ) {
	const [ draft, setDraft ] = useState< StockroomInput >(
		stockroom ?? EMPTY
	);
	const [ confirming, setConfirming ] = useState( false );
	const days = ( value: string ) =>
		Math.max( 0, Number.parseInt( value, 10 ) || 0 );
	const perUnit = draft.delivery_unit === 'weeks' ? 7 : 1;

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		saveStockroom( stockroom?.id ?? null, draft )
			.then( onSaved )
			.catch( onError );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ stockroom?.name ?? __( 'Add a stockroom', 'orillacart' ) }
				</h2>
				{ stockroom !== null &&
					( confirming ? (
						<Flex justify="flex-end" expanded={ false }>
							<Button
								variant="primary"
								isDestructive
								onClick={ () =>
									deleteStockroom( stockroom.id )
										.then( onDeleted )
										.catch( onError )
								}
							>
								{ __(
									'Delete the stockroom and its stock',
									'orillacart'
								) }
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
							{ __( 'Delete stockroom', 'orillacart' ) }
						</Button>
					) ) }
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Name', 'orillacart' ) }
						value={ draft.name }
						onChange={ ( name ) => setDraft( { ...draft, name } ) }
					/>
					<Flex align="flex-end">
						<FlexItem>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Delivery time in', 'orillacart' ) }
								value={ draft.delivery_unit }
								options={ [
									{
										label: __( 'Days', 'orillacart' ),
										value: 'days',
									},
									{
										label: __( 'Weeks', 'orillacart' ),
										value: 'weeks',
									},
								] }
								onChange={ ( unit ) => {
									const weeks = unit === 'weeks';
									// Times in weeks are whole weeks: round up to the next one.
									const round = ( value: number ) =>
										weeks
											? Math.ceil( value / 7 ) * 7
											: value;
									setDraft( {
										...draft,
										delivery_unit: weeks ? 'weeks' : 'days',
										min_delivery_days: round(
											draft.min_delivery_days
										),
										max_delivery_days: round(
											draft.max_delivery_days
										),
									} );
								} }
							/>
						</FlexItem>
						{ (
							[
								[
									'min_delivery_days',
									__( 'At least', 'orillacart' ),
								],
								[
									'max_delivery_days',
									__( 'At most', 'orillacart' ),
								],
							] as const
						 ).map( ( [ field, label ] ) => (
							<FlexItem isBlock key={ field }>
								<TextControl
									__next40pxDefaultSize
									type="number"
									min={ 0 }
									label={ label }
									value={ String( draft[ field ] / perUnit ) }
									onChange={ ( value ) =>
										setDraft( {
											...draft,
											[ field ]: days( value ) * perUnit,
										} )
									}
								/>
							</FlexItem>
						) ) }
					</Flex>
					<TextareaControl
						label={ __( 'Description', 'orillacart' ) }
						value={ draft.description }
						onChange={ ( description ) =>
							setDraft( { ...draft, description } )
						}
					/>
					<CheckboxControl
						label={ __( 'Active', 'orillacart' ) }
						checked={ draft.active }
						onChange={ ( active ) =>
							setDraft( { ...draft, active } )
						}
					/>
					<Button
						variant="primary"
						type="submit"
						disabled={ draft.name.trim() === '' }
					>
						{ stockroom === null
							? __( 'Add stockroom', 'orillacart' )
							: sprintf(
									/* translators: %s: stockroom name */
									__( 'Save %s', 'orillacart' ),
									stockroom.name
								) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
