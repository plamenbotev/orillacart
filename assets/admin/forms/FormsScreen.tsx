import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import {
	fetchTerms,
	ProductTokens,
	TermTokens,
	type Named,
} from '../shared/CatalogTokens';
import { toApiError } from '../shared/api';
import {
	deleteForm,
	fetchForms,
	saveForm,
	type FormInput,
	type Placement,
	type Scope,
	type ShopForm,
} from './api';
import { draftsOf, FieldsEditor, type FieldDraft } from './FieldsEditor';

type Message = { status: 'success' | 'error'; text: string } | null;

const NEW = 'new';

const PLACES: Record< Placement, string > = {
	product: __( 'Product page', 'orillacart' ),
	checkout: __( 'Checkout', 'orillacart' ),
};

/**
 * The Shop → Forms screen: questions asked on the product page, answered for the item bought (an engraving, a
 * logo to print), or at checkout, answered for the order.
 */
export function FormsScreen() {
	const [ forms, setForms ] = useState< ShopForm[] | null >( null );
	const [ categories, setCategories ] = useState< Named[] >( [] );
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
		fetchForms().then( setForms ).catch( fail );
		fetchTerms( 'orillacart_category' ).then( setCategories ).catch( fail );
	}, [] );

	const notice = message !== null && (
		<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
			{ message.text }
		</Notice>
	);

	if ( forms === null ) {
		return (
			<>
				<h1>{ __( 'Forms', 'orillacart' ) }</h1>
				{ notice || <Spinner /> }
			</>
		);
	}

	const form = forms.find( ( candidate ) => candidate.id === selected );
	const forWhom = ( candidate: ShopForm ) => {
		if ( candidate.scope === 'categories' ) {
			return candidate.category_ids
				.map(
					( id ) =>
						categories.find( ( term ) => term.id === id )?.name ??
						`#${ id }`
				)
				.join( ', ' );
		}
		if ( candidate.scope === 'products' ) {
			return sprintf(
				/* translators: %d: how many products */
				_n(
					'%d product',
					'%d products',
					candidate.product_ids.length,
					'orillacart'
				),
				candidate.product_ids.length
			);
		}
		return __( 'All products', 'orillacart' );
	};

	return (
		<>
			<h1>{ __( 'Forms', 'orillacart' ) }</h1>
			<p>
				{ __(
					'Questions for shoppers. On the product page, the answers go with the item bought and can add to its price: an engraving, a name to print, a logo. At checkout, they go with the order: a purchase order number, a delivery note.',
					'orillacart'
				) }
			</p>
			{ notice }
			<Card className="orillacart-card">
				<CardBody>
					{ forms.length === 0 ? (
						<p>{ __( 'No forms yet.', 'orillacart' ) }</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th>{ __( 'Name', 'orillacart' ) }</th>
									<th>{ __( 'Asked on', 'orillacart' ) }</th>
									<th>{ __( 'For', 'orillacart' ) }</th>
									<th>{ __( 'Questions', 'orillacart' ) }</th>
									<th>{ __( 'State', 'orillacart' ) }</th>
									<th>
										<span className="screen-reader-text">
											{ __( 'Actions', 'orillacart' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ forms.map( ( candidate ) => (
									<tr key={ candidate.id }>
										<td>{ candidate.name }</td>
										<td>
											{ PLACES[ candidate.placement ] }
										</td>
										<td>{ forWhom( candidate ) }</td>
										<td>{ candidate.fields.length }</td>
										<td>
											{ candidate.active
												? __( 'On', 'orillacart' )
												: __( 'Off', 'orillacart' ) }
										</td>
										<td>
											<Button
												variant="secondary"
												size="small"
												onClick={ () =>
													setSelected( candidate.id )
												}
											>
												{ __( 'Edit', 'orillacart' ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<p>
						<Button
							variant="primary"
							onClick={ () => setSelected( NEW ) }
						>
							{ __( 'Add form', 'orillacart' ) }
						</Button>
					</p>
				</CardBody>
			</Card>
			{ ( form !== undefined || selected === NEW ) && (
				<FormCard
					key={ form?.id ?? NEW }
					form={ form ?? null }
					categories={ categories }
					onSaved={ ( saved ) => {
						setForms(
							[
								...forms.filter(
									( candidate ) => candidate.id !== saved.id
								),
								saved,
							].sort(
								( a, b ) =>
									a.position - b.position || a.id - b.id
							)
						);
						setSelected( saved.id );
						setMessage( {
							status: 'success',
							text: __( 'Form saved.', 'orillacart' ),
						} );
					} }
					onDeleted={ ( id ) => {
						setForms(
							forms.filter( ( candidate ) => candidate.id !== id )
						);
						setSelected( null );
						setMessage( {
							status: 'success',
							text: __( 'Form deleted.', 'orillacart' ),
						} );
					} }
					onClose={ () => setSelected( null ) }
					onError={ fail }
				/>
			) }
		</>
	);
}

interface FormCardProps {
	/** Null for a form not created yet. */
	form: ShopForm | null;
	categories: Named[];
	onSaved: ( form: ShopForm ) => void;
	onDeleted: ( id: number ) => void;
	onClose: () => void;
	onError: ( error: unknown ) => void;
}

function FormCard( {
	form,
	categories,
	onSaved,
	onDeleted,
	onClose,
	onError,
}: FormCardProps ) {
	const [ draft, setDraft ] = useState< Omit< FormInput, 'fields' > >( {
		name: form?.name ?? '',
		placement: form?.placement ?? 'product',
		active: form?.active ?? true,
		position: form?.position ?? 0,
		scope: form?.scope ?? 'all',
		category_ids: form?.category_ids ?? [],
		product_ids: form?.product_ids ?? [],
	} );
	const [ fields, setFields ] = useState< FieldDraft[] >(
		draftsOf( form?.fields ?? [] )
	);
	const [ saving, setSaving ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const set = ( changes: Partial< typeof draft > ) =>
		setDraft( { ...draft, ...changes } );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		saveForm( form?.id ?? null, {
			...draft,
			fields: fields.map( ( field ) => field.field ),
		} )
			.then( onSaved )
			.catch( onError )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ form?.name ?? __( 'New form', 'orillacart' ) }</h2>
				{ form !== null &&
					( confirming ? (
						<Flex justify="flex-end" expanded={ false }>
							<Button
								variant="primary"
								isDestructive
								onClick={ () =>
									deleteForm( form.id )
										.then( () => onDeleted( form.id ) )
										.catch( onError )
								}
							>
								{ __( 'Delete the form', 'orillacart' ) }
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
							variant="tertiary"
							isDestructive
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Delete', 'orillacart' ) }
						</Button>
					) ) }
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Name', 'orillacart' ) }
						help={ __(
							'For you; shoppers see the questions.',
							'orillacart'
						) }
						value={ draft.name }
						maxLength={ 100 }
						required
						onChange={ ( name ) => set( { name } ) }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Asked on', 'orillacart' ) }
						value={ draft.placement }
						disabled={ form !== null }
						help={
							form !== null
								? __(
										'A form stays where it is asked.',
										'orillacart'
									)
								: undefined
						}
						options={ [
							{
								value: 'product',
								label: __(
									'The product page: answers go with the item',
									'orillacart'
								),
							},
							{
								value: 'checkout',
								label: __(
									'Checkout: answers go with the order',
									'orillacart'
								),
							},
						] }
						onChange={ ( placement ) =>
							set( { placement: placement as Placement } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'For', 'orillacart' ) }
						value={ draft.scope }
						help={
							draft.placement === 'checkout'
								? __(
										'Asked when the cart holds one of these products.',
										'orillacart'
									)
								: undefined
						}
						options={ [
							{
								value: 'all',
								label: __( 'All products', 'orillacart' ),
							},
							{
								value: 'categories',
								label: __(
									'Products in some categories',
									'orillacart'
								),
							},
							{
								value: 'products',
								label: __( 'Some products', 'orillacart' ),
							},
						] }
						onChange={ ( scope ) =>
							set( { scope: scope as Scope } )
						}
					/>
					{ draft.scope === 'categories' && (
						<TermTokens
							label={ __( 'Categories', 'orillacart' ) }
							help={ __(
								'Their subcategories too.',
								'orillacart'
							) }
							terms={ categories }
							ids={ draft.category_ids }
							onChange={ ( ids ) => set( { category_ids: ids } ) }
						/>
					) }
					{ draft.scope === 'products' && (
						<ProductTokens
							label={ __( 'Products', 'orillacart' ) }
							ids={ draft.product_ids }
							onChange={ ( ids ) => set( { product_ids: ids } ) }
							onError={ onError }
						/>
					) }
					<TextControl
						__next40pxDefaultSize
						type="number"
						label={ __( 'Order', 'orillacart' ) }
						help={ __(
							'Forms with lower numbers are asked first.',
							'orillacart'
						) }
						value={ String( draft.position ) }
						onChange={ ( position ) =>
							set( { position: Number( position ) || 0 } )
						}
					/>
					<ToggleControl
						label={ __( 'Asked', 'orillacart' ) }
						checked={ draft.active }
						onChange={ ( active ) => set( { active } ) }
					/>
					<h3>{ __( 'Questions', 'orillacart' ) }</h3>
					<FieldsEditor
						placement={ draft.placement }
						drafts={ fields }
						onChange={ setFields }
					/>
					<Flex justify="flex-start" style={ { marginTop: 16 } }>
						<Button
							variant="primary"
							type="submit"
							isBusy={ saving }
							disabled={ saving }
						>
							{ __( 'Save form', 'orillacart' ) }
						</Button>
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Close', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
			</CardBody>
		</Card>
	);
}
