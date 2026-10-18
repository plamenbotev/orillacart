import {
	Button,
	CheckboxControl,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { FormEvent } from 'react';

import { toApiError } from '../shared/api';
import { fetchCountries, type Country } from '../shared/geography';
import {
	createGroup,
	deleteGroup,
	deleteRate,
	fetchGroups,
	fetchTaxSettings,
	renameGroup,
	saveTaxSettings,
	type TaxGroup,
	type TaxRate,
	type TaxSettings,
} from './api';
import { RateModal } from './RateModal';

type Message = { status: 'success' | 'error'; text: string } | null;

const BASIS_LABELS: Record< string, string > = {
	shipping_address: __( "Customer's shipping address", 'orillacart' ),
	billing_address: __( "Customer's billing address", 'orillacart' ),
	shop_address: __( "Shop's address", 'orillacart' ),
	eu_origin: __(
		"Shop's address for EU customers, no tax outside the EU",
		'orillacart'
	),
};

/**
 * The Shop → Tax screen: how tax is charged, and the tax groups with their rates.
 */
export function TaxScreen() {
	const [ groups, setGroups ] = useState< TaxGroup[] | null >( null );
	const [ settings, setSettings ] = useState< TaxSettings | null >( null );
	const [ countries, setCountries ] = useState< Country[] >( [] );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );

	const reload = useCallback(
		() =>
			fetchGroups()
				.then( setGroups )
				.catch( ( error: unknown ) =>
					setMessage( {
						status: 'error',
						text: toApiError( error ).message,
					} )
				),
		[]
	);

	useEffect( () => {
		Promise.all( [ fetchGroups(), fetchTaxSettings(), fetchCountries() ] )
			.then( ( [ loadedGroups, loadedSettings, loadedCountries ] ) => {
				setGroups( loadedGroups );
				setSettings( loadedSettings );
				setCountries( loadedCountries );
				setSelected( loadedGroups[ 0 ]?.id ?? null );
			} )
			.catch( ( error: unknown ) =>
				setMessage( {
					status: 'error',
					text: toApiError( error ).message,
				} )
			);
	}, [] );

	if ( groups === null || settings === null ) {
		return (
			<>
				<h1>{ __( 'Tax', 'orillacart' ) }</h1>
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

	const group = groups.find( ( candidate ) => candidate.id === selected );

	return (
		<>
			<h1>{ __( 'Tax', 'orillacart' ) }</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			<SettingsCard
				settings={ settings }
				groups={ groups }
				onSaved={ ( saved ) => {
					setSettings( saved );
					setMessage( {
						status: 'success',
						text: __( 'Tax settings saved.', 'orillacart' ),
					} );
				} }
				onError={ fail }
			/>
			<GroupsCard
				groups={ groups }
				selected={ selected }
				onSelect={ setSelected }
				onCreated={ ( created ) => {
					setSelected( created.id );
					void reload();
				} }
				onError={ fail }
			/>
			{ group !== undefined && (
				<GroupCard
					key={ group.id }
					group={ group }
					countries={ countries }
					onChanged={ () => void reload() }
					onDeleted={ () => {
						setSelected(
							groups.find( ( other ) => other.id !== group.id )
								?.id ?? null
						);
						void reload();
					} }
					onError={ fail }
				/>
			) }
		</>
	);
}

function SettingsCard( {
	settings,
	groups,
	onSaved,
	onError,
}: {
	settings: TaxSettings;
	groups: TaxGroup[];
	onSaved: ( saved: TaxSettings ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ basis, setBasis ] = useState( settings.basis );
	const [ defaultGroup, setDefaultGroup ] = useState(
		settings.default_group_id
	);
	const [ includeTax, setIncludeTax ] = useState(
		settings.prices_include_tax
	);
	const [ reverseCharge, setReverseCharge ] = useState(
		settings.reverse_charge
	);
	const [ saving, setSaving ] = useState( false );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		setSaving( true );
		saveTaxSettings( {
			basis,
			default_group_id: defaultGroup,
			prices_include_tax: includeTax,
			reverse_charge: reverseCharge,
		} )
			.then( onSaved )
			.catch( onError )
			.finally( () => setSaving( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'How tax is charged', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<form onSubmit={ submit }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Tax is based on', 'orillacart' ) }
						value={ basis }
						options={ settings.bases.map( ( value ) => ( {
							label: BASIS_LABELS[ value ] ?? value,
							value,
						} ) ) }
						onChange={ setBasis }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Default tax group', 'orillacart' ) }
						help={ __(
							'For products and shipping rates without their own group. Without one, they are not taxed.',
							'orillacart'
						) }
						value={
							defaultGroup === null ? '' : String( defaultGroup )
						}
						options={ [
							{
								label: __( '— None —', 'orillacart' ),
								value: '',
							},
							...groups.map( ( group ) => ( {
								label: group.name,
								value: String( group.id ),
							} ) ),
						] }
						onChange={ ( value ) =>
							setDefaultGroup(
								value === ''
									? null
									: Number.parseInt( value, 10 )
							)
						}
					/>
					<CheckboxControl
						label={ __( 'Prices include tax', 'orillacart' ) }
						help={ __(
							'Prices of products and shipping are entered with tax in them, as EU shops show them. Every customer pays them as entered, and the tax in them is their own country’s; a customer who owes no tax, such as abroad, pays them less your own tax.',
							'orillacart'
						) }
						checked={ includeTax }
						onChange={ setIncludeTax }
					/>
					<CheckboxControl
						label={ __(
							'Ask businesses for their EU VAT number',
							'orillacart'
						) }
						help={ __(
							'Businesses in another EU country than yours, with a VAT number the EU’s VIES service confirms, buy without VAT and account for it themselves (reverse charge). When VIES cannot be asked, VAT is charged.',
							'orillacart'
						) }
						checked={ reverseCharge }
						onChange={ setReverseCharge }
					/>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save tax settings', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}

function GroupsCard( {
	groups,
	selected,
	onSelect,
	onCreated,
	onError,
}: {
	groups: TaxGroup[];
	selected: number | null;
	onSelect: ( id: number ) => void;
	onCreated: ( group: TaxGroup ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( '' );

	const submit = ( event: FormEvent ) => {
		event.preventDefault();
		createGroup( name )
			.then( ( created ) => {
				setName( '' );
				onCreated( created );
			} )
			.catch( onError );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Tax groups', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ groups.length === 0 ? (
					<p>
						{ __(
							'No tax groups yet. Add one, such as "Standard", then its rates.',
							'orillacart'
						) }
					</p>
				) : (
					<Flex wrap justify="flex-start">
						{ groups.map( ( group ) => (
							<Button
								key={ group.id }
								variant={
									group.id === selected
										? 'primary'
										: 'secondary'
								}
								onClick={ () => onSelect( group.id ) }
							>
								{ group.name }
							</Button>
						) ) }
					</Flex>
				) }
				<form onSubmit={ submit }>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'New group', 'orillacart' ) }
								value={ name }
								onChange={ setName }
							/>
						</FlexItem>
						<Button
							variant="secondary"
							type="submit"
							disabled={ name.trim() === '' }
						>
							{ __( 'Add group', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
			</CardBody>
		</Card>
	);
}

function GroupCard( {
	group,
	countries,
	onChanged,
	onDeleted,
	onError,
}: {
	group: TaxGroup;
	countries: Country[];
	onChanged: () => void;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( group.name );
	const [ editing, setEditing ] = useState< TaxRate | 'new' | null >( null );
	const [ confirming, setConfirming ] = useState( false );
	const countryName = ( code: string ) =>
		countries.find( ( country ) => country.code === code )?.name ?? code;

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ group.name }</h2>
				{ confirming ? (
					<Flex justify="flex-end" expanded={ false }>
						<Button
							variant="primary"
							isDestructive
							onClick={ () =>
								deleteGroup( group.id )
									.then( onDeleted )
									.catch( onError )
							}
						>
							{ __(
								'Delete the group and its rates',
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
						{ __( 'Delete group', 'orillacart' ) }
					</Button>
				) }
			</CardHeader>
			<CardBody>
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						renameGroup( group.id, name )
							.then( onChanged )
							.catch( onError );
					} }
				>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Group name', 'orillacart' ) }
								value={ name }
								onChange={ setName }
							/>
						</FlexItem>
						<Button
							variant="secondary"
							type="submit"
							disabled={
								name.trim() === '' || name === group.name
							}
						>
							{ __( 'Rename', 'orillacart' ) }
						</Button>
					</Flex>
				</form>
				<table className="widefat striped orillacart-rates">
					<thead>
						<tr>
							<th>{ __( 'Country', 'orillacart' ) }</th>
							<th>{ __( 'State', 'orillacart' ) }</th>
							<th>{ __( 'Rate', 'orillacart' ) }</th>
							<th>{ __( 'Name', 'orillacart' ) }</th>
							<th>{ __( 'Priority', 'orillacart' ) }</th>
							<th>
								<span className="screen-reader-text">
									{ __( 'Actions', 'orillacart' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ group.rates.length === 0 && (
							<tr>
								<td colSpan={ 6 }>
									{ __( 'No rates yet.', 'orillacart' ) }
								</td>
							</tr>
						) }
						{ group.rates.map( ( rate ) => (
							<tr key={ rate.id }>
								<td>{ countryName( rate.country ) }</td>
								<td>
									{ rate.state ??
										__( 'All states', 'orillacart' ) }
								</td>
								<td>{ rate.percent } %</td>
								<td>{ rate.name }</td>
								<td>{ rate.priority }</td>
								<td>
									<Button
										variant="link"
										onClick={ () => setEditing( rate ) }
									>
										{ __( 'Edit', 'orillacart' ) }
									</Button>{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											deleteRate( group.id, rate.id )
												.then( onChanged )
												.catch( onError )
										}
									>
										{ __( 'Delete', 'orillacart' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<Button
					variant="secondary"
					onClick={ () => setEditing( 'new' ) }
				>
					{ __( 'Add rate', 'orillacart' ) }
				</Button>
			</CardBody>
			{ editing !== null && (
				<RateModal
					groupId={ group.id }
					rate={ editing === 'new' ? null : editing }
					countries={ countries }
					onSaved={ () => {
						setEditing( null );
						onChanged();
					} }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</Card>
	);
}
