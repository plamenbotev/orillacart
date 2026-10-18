import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	FormTokenField,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	deleteFilter,
	fetchAttributeNames,
	fetchFilters,
	saveFilter,
	type SearchFilter,
	type SearchFilterInput,
} from './filters';

const EMPTY: SearchFilterInput = {
	label: '',
	key: '',
	attributes: [],
	position: 0,
};

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
 * The filters shoppers see on the shop page, beside brand, price and stock. Attributes belong to one product
 * or one attribute set, so a filter names the attributes it covers; their options are matched by name.
 */
export function FiltersCard() {
	const [ filters, setFilters ] = useState< SearchFilter[] | null >( null );
	const [ names, setNames ] = useState< string[] >( [] );
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );

	useEffect( () => {
		Promise.all( [ fetchFilters(), fetchAttributeNames() ] )
			.then( ( [ loaded, attributeNames ] ) => {
				setFilters( loaded );
				setNames( attributeNames );
			} )
			.catch( ( reason: unknown ) =>
				setMessage( {
					status: 'error',
					text: toApiError( reason ).message,
				} )
			);
	}, [] );

	const fail = ( reason: unknown ) =>
		setMessage( { status: 'error', text: toApiError( reason ).message } );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Shop filters', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Shoppers narrow the shop page by these, with how many products each choice leaves. A filter covers attributes by name, such as Size and Размер, so products and attribute sets that name an attribute differently still share it.',
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
				{ filters === null ? (
					<Spinner />
				) : (
					<>
						{ filters.map( ( filter ) => (
							<FilterRow
								key={ filter.id }
								filter={ filter }
								names={ names }
								onSaved={ ( saved ) => {
									setFilters(
										filters.map( ( current ) =>
											current.id === saved.id
												? saved
												: current
										)
									);
									setMessage( {
										status: 'success',
										text: __(
											'Filter saved.',
											'orillacart'
										),
									} );
								} }
								onDeleted={ () =>
									setFilters(
										filters.filter(
											( current ) =>
												current.id !== filter.id
										)
									)
								}
								onError={ fail }
							/>
						) ) }
						<FilterRow
							key={ `new-${ filters.length }` }
							filter={ null }
							names={ names }
							onSaved={ ( saved ) => {
								setFilters( [ ...filters, saved ] );
								setMessage( {
									status: 'success',
									text: __( 'Filter added.', 'orillacart' ),
								} );
							} }
							onDeleted={ () => undefined }
							onError={ fail }
						/>
					</>
				) }
			</CardBody>
		</Card>
	);
}

function FilterRow( {
	filter,
	names,
	onSaved,
	onDeleted,
	onError,
}: {
	filter: SearchFilter | null;
	names: string[];
	onSaved: ( filter: SearchFilter ) => void;
	onDeleted: () => void;
	onError: ( reason: unknown ) => void;
} ) {
	const [ draft, setDraft ] = useState< SearchFilterInput >(
		filter ?? EMPTY
	);
	const [ keyEdited, setKeyEdited ] = useState( filter !== null );

	return (
		<Flex align="flex-end" wrap className="orillacart-row">
			<FlexItem>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Label', 'orillacart' ) }
					value={ draft.label }
					onChange={ ( label ) =>
						setDraft( {
							...draft,
							label,
							key: keyEdited ? draft.key : keyOf( label ),
						} )
					}
				/>
			</FlexItem>
			<FlexItem>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Key in the address', 'orillacart' ) }
					value={ draft.key }
					onChange={ ( key ) => {
						setKeyEdited( true );
						setDraft( { ...draft, key } );
					} }
				/>
			</FlexItem>
			<FlexItem isBlock>
				<FormTokenField
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					label={ __( 'Attributes it covers', 'orillacart' ) }
					value={ draft.attributes }
					suggestions={ names }
					onChange={ ( tokens ) =>
						setDraft( {
							...draft,
							attributes: tokens.map( ( token ) =>
								typeof token === 'string' ? token : token.value
							),
						} )
					}
				/>
			</FlexItem>
			<FlexItem>
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={ __( 'Order', 'orillacart' ) }
					value={ String( draft.position ) }
					onChange={ ( value ) =>
						setDraft( {
							...draft,
							position: Math.max(
								0,
								Number.parseInt( value, 10 ) || 0
							),
						} )
					}
				/>
			</FlexItem>
			<FlexItem>
				<Button
					variant="primary"
					disabled={
						draft.label.trim() === '' ||
						draft.attributes.length === 0
					}
					onClick={ () =>
						saveFilter( filter?.id ?? null, {
							label: draft.label,
							key: draft.key,
							attributes: draft.attributes,
							position: draft.position,
						} )
							.then( ( saved ) => {
								setDraft( filter === null ? EMPTY : saved );
								setKeyEdited( filter !== null );
								onSaved( saved );
							} )
							.catch( onError )
					}
				>
					{ filter === null
						? __( 'Add filter', 'orillacart' )
						: sprintf(
								/* translators: %s: filter label */
								__( 'Save %s', 'orillacart' ),
								filter.label
							) }
				</Button>
			</FlexItem>
			{ filter !== null && (
				<FlexItem>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							deleteFilter( filter.id )
								.then( onDeleted )
								.catch( onError )
						}
					>
						{ __( 'Remove', 'orillacart' ) }
					</Button>
				</FlexItem>
			) }
		</Flex>
	);
}
