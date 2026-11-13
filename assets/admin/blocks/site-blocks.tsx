/**
 * Add-ons' blocks for the whole site (extension API 24, 28): the server lists each with its settings
 * (AddOnSiteBlocks), the sidebar is built from them, and the block shows as the server renders it. A block that
 * holds others has them below its preview; one with an editing screen of its own has it in the sidebar, in a
 * sandboxed frame that reads and sets the block's settings through the bridge (design 73).
 */
import {
	InnerBlocks,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	BaseControl,
	Button,
	ColorPalette,
	ComboboxControl,
	PanelBody,
	Placeholder,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import { attach } from '../extension-frame/host';
import { fetchProducts, fetchTerms, type Named } from '../shared/CatalogTokens';

type Kind =
	| 'text'
	| 'number'
	| 'boolean'
	| 'choice'
	| 'image'
	| 'link'
	| 'color'
	| 'product'
	| 'category'
	| 'rich_text'
	| 'list';

export interface SiteBlockSetting {
	key: string;
	label: string;
	type: Kind;
	help: string;
	choices: Record< string, string >;
	fields?: SiteBlockSetting[];
}

export interface SiteBlockDefinition {
	name: string;
	settings: SiteBlockSetting[];
	inner?: boolean;
	editor?: string | null;
	extension?: string;
}

type Value = unknown;
type Values = Record< string, Value >;

const CATEGORIES = 'orillacart_category';

/**
 * A product chosen by name.
 *
 * @param props          The setting, its value and the change.
 * @param props.label    What it is called.
 * @param props.help     What it is for.
 * @param props.value    The product's id.
 * @param props.onChange Sets it.
 */
function ProductSetting( {
	label,
	help,
	value,
	onChange,
}: {
	label: string;
	help: string;
	value: number;
	onChange: ( id: number | undefined ) => void;
} ) {
	const [ found, setFound ] = useState< Named[] >( [] );
	useEffect( () => {
		if ( value > 0 ) {
			fetchProducts( { include: [ value ] } )
				.then( setFound )
				.catch( () => setFound( [] ) );
		}
	}, [ value ] );
	return (
		<ComboboxControl
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ value > 0 ? String( value ) : null }
			options={ found.map( ( product ) => ( {
				label: product.name,
				value: String( product.id ),
			} ) ) }
			onFilterValueChange={ ( search ) => {
				if ( search.trim().length >= 2 ) {
					fetchProducts( { search } )
						.then( setFound )
						.catch( () => setFound( [] ) );
				}
			} }
			onChange={ ( id ) => onChange( Number( id ) || undefined ) }
		/>
	);
}

/**
 * A product category chosen from the shop's.
 *
 * @param props          The setting, its value and the change.
 * @param props.label    What it is called.
 * @param props.help     What it is for.
 * @param props.value    The category's id.
 * @param props.onChange Sets it.
 */
function CategorySetting( {
	label,
	help,
	value,
	onChange,
}: {
	label: string;
	help: string;
	value: number;
	onChange: ( id: number | undefined ) => void;
} ) {
	const [ terms, setTerms ] = useState< Named[] >( [] );
	useEffect( () => {
		fetchTerms( CATEGORIES )
			.then( setTerms )
			.catch( () => setTerms( [] ) );
	}, [] );
	return (
		<SelectControl
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ value > 0 ? String( value ) : '' }
			options={ [
				{ label: __( 'Choose', 'orillacart' ), value: '' },
				...terms.map( ( term ) => ( {
					label: term.name,
					value: String( term.id ),
				} ) ),
			] }
			onChange={ ( id ) => onChange( Number( id ) || undefined ) }
		/>
	);
}

/**
 * An image of the media library.
 *
 * @param props          The setting, its value and the change.
 * @param props.label    What it is called.
 * @param props.help     What it is for.
 * @param props.value    The image's id.
 * @param props.onChange Sets it.
 */
function ImageSetting( {
	label,
	help,
	value,
	onChange,
}: {
	label: string;
	help: string;
	value: number;
	onChange: ( id: number | undefined ) => void;
} ) {
	return (
		<BaseControl
			__nextHasNoMarginBottom
			id={ `orillacart-image-${ label }` }
			label={ label }
			help={ help }
		>
			<MediaUploadCheck>
				<MediaUpload
					allowedTypes={ [ 'image' ] }
					{ ...( value > 0 ? { value } : {} ) }
					onSelect={ ( media: { id: number } | { id: number }[] ) =>
						onChange(
							Array.isArray( media ) ? media[ 0 ]?.id : media.id
						)
					}
					render={ ( { open }: { open: () => void } ) => (
						<div>
							<Button variant="secondary" onClick={ open }>
								{ value > 0
									? sprintf(
											/* translators: %d: the image's id in the media library */
											__(
												'Image #%d: change',
												'orillacart'
											),
											value
										)
									: __( 'Choose an image', 'orillacart' ) }
							</Button>
							{ value > 0 && (
								<Button
									variant="link"
									isDestructive
									onClick={ () => onChange( undefined ) }
								>
									{ __( 'Remove', 'orillacart' ) }
								</Button>
							) }
						</div>
					) }
				/>
			</MediaUploadCheck>
		</BaseControl>
	);
}

/**
 * The items of a list setting, each with its fields; added, removed and moved here.
 *
 * @param props          The setting, its value and the change.
 * @param props.setting  The list.
 * @param props.value    Its items.
 * @param props.onChange Sets them.
 */
function ListSetting( {
	setting,
	value,
	onChange,
}: {
	setting: SiteBlockSetting;
	value: Values[];
	onChange: ( items: Values[] ) => void;
} ) {
	const set = ( index: number, item: Values ) =>
		onChange( value.map( ( old, at ) => ( at === index ? item : old ) ) );
	const move = ( index: number, by: number ) => {
		const next = [ ...value ];
		const [ item ] = next.splice( index, 1 );
		if ( item ) {
			next.splice( index + by, 0, item );
		}
		onChange( next );
	};
	return (
		<BaseControl
			__nextHasNoMarginBottom
			id={ `orillacart-list-${ setting.key }` }
			label={ setting.label }
			help={ setting.help }
		>
			{ value.map( ( item, index ) => (
				<PanelBody
					key={ index }
					title={ sprintf(
						/* translators: %d: the item's place in the list */
						__( 'Item %d', 'orillacart' ),
						index + 1
					) }
					initialOpen={ false }
				>
					{ ( setting.fields ?? [] ).map( ( field ) => (
						<SettingControl
							key={ field.key }
							setting={ field }
							value={ item[ field.key ] }
							onChange={ ( fieldValue ) =>
								set( index, {
									...item,
									[ field.key ]: fieldValue,
								} )
							}
						/>
					) ) }
					<Button
						variant="link"
						disabled={ index === 0 }
						onClick={ () => move( index, -1 ) }
					>
						{ __( 'Up', 'orillacart' ) }
					</Button>{ ' ' }
					<Button
						variant="link"
						disabled={ index === value.length - 1 }
						onClick={ () => move( index, 1 ) }
					>
						{ __( 'Down', 'orillacart' ) }
					</Button>{ ' ' }
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							onChange(
								value.filter( ( _, at ) => at !== index )
							)
						}
					>
						{ __( 'Remove', 'orillacart' ) }
					</Button>
				</PanelBody>
			) ) }
			<Button
				variant="secondary"
				onClick={ () => onChange( [ ...value, {} ] ) }
			>
				{ __( 'Add an item', 'orillacart' ) }
			</Button>
		</BaseControl>
	);
}

/**
 * One setting's control in the sidebar.
 *
 * @param props          The setting, its value and the change.
 * @param props.setting  The setting.
 * @param props.value    What is set.
 * @param props.onChange Sets it.
 */
export function SettingControl( {
	setting,
	value,
	onChange,
}: {
	setting: SiteBlockSetting;
	value: Value;
	onChange: ( value: Value ) => void;
} ) {
	const id = typeof value === 'number' ? value : 0;
	let input: 'text' | 'number' | 'url' = 'text';
	if ( setting.type === 'number' ) {
		input = 'number';
	} else if ( setting.type === 'link' ) {
		input = 'url';
	}
	switch ( setting.type ) {
		case 'boolean':
			return (
				<ToggleControl
					label={ setting.label }
					help={ setting.help }
					checked={ value === true }
					onChange={ onChange }
				/>
			);
		case 'choice':
			return (
				<SelectControl
					__next40pxDefaultSize
					label={ setting.label }
					help={ setting.help }
					value={ typeof value === 'string' ? value : '' }
					options={ [
						{ label: __( 'Choose', 'orillacart' ), value: '' },
						...Object.entries( setting.choices ).map(
							( [ choice, label ] ) => ( {
								value: choice,
								label,
							} )
						),
					] }
					onChange={ ( choice ) =>
						onChange( choice === '' ? undefined : choice )
					}
				/>
			);
		case 'image':
			return (
				<ImageSetting
					label={ setting.label }
					help={ setting.help }
					value={ id }
					onChange={ onChange }
				/>
			);
		case 'product':
			return (
				<ProductSetting
					label={ setting.label }
					help={ setting.help }
					value={ id }
					onChange={ onChange }
				/>
			);
		case 'category':
			return (
				<CategorySetting
					label={ setting.label }
					help={ setting.help }
					value={ id }
					onChange={ onChange }
				/>
			);
		case 'color':
			return (
				<BaseControl
					__nextHasNoMarginBottom
					id={ `orillacart-color-${ setting.key }` }
					label={ setting.label }
					help={ setting.help }
				>
					<ColorPalette
						{ ...( typeof value === 'string' ? { value } : {} ) }
						onChange={ ( color ) => onChange( color ?? undefined ) }
					/>
				</BaseControl>
			);
		case 'rich_text':
			return (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ setting.label }
					help={
						setting.help ||
						__(
							'HTML: paragraphs, lists, links, emphasis.',
							'orillacart'
						)
					}
					value={ typeof value === 'string' ? value : '' }
					onChange={ ( text ) =>
						onChange( text === '' ? undefined : text )
					}
				/>
			);
		case 'list':
			return (
				<ListSetting
					setting={ setting }
					value={
						Array.isArray( value )
							? ( value as Values[] ).map( ( item ) =>
									item && typeof item === 'object' ? item : {}
								)
							: []
					}
					onChange={ onChange }
				/>
			);
	}
	return (
		<TextControl
			__next40pxDefaultSize
			label={ setting.label }
			help={ setting.help }
			type={ input }
			value={
				value === undefined || value === null ? '' : String( value )
			}
			onChange={ ( text ) => {
				if ( setting.type !== 'number' ) {
					onChange( text === '' ? undefined : text );
					return;
				}
				const number = Number( text );
				onChange(
					text === '' || Number.isNaN( number ) ? undefined : number
				);
			} }
		/>
	);
}

/**
 * The block's own editing screen, in a sandboxed frame of the sidebar (design 73): it reads and sets the block's
 * settings through the bridge, which keeps to the settings the block declares.
 *
 * @param props            The block, its settings and the change.
 * @param props.definition The block.
 * @param props.attributes What is set.
 * @param props.onChange   Sets some of them.
 */
function EditorFrame( {
	definition,
	attributes,
	onChange,
}: {
	definition: SiteBlockDefinition;
	attributes: Values;
	onChange: ( next: Values ) => void;
} ) {
	const frame = useRef< HTMLIFrameElement >( null );
	const current = useRef( { attributes, onChange } );
	current.current = { attributes, onChange };
	useEffect( () => {
		const element = frame.current;
		if ( ! element ) {
			return undefined;
		}
		const keys = new Set(
			definition.settings.map( ( setting ) => setting.key )
		);
		return attach( {
			element,
			extension: definition.extension ?? '',
			block: {
				get: () => current.current.attributes,
				set: ( next ) =>
					current.current.onChange(
						Object.fromEntries(
							Object.entries( next ).filter( ( [ key ] ) =>
								keys.has( key )
							)
						)
					),
			},
		} );
	}, [ definition ] );
	return (
		<iframe
			ref={ frame }
			title={ __( 'The block’s editing screen', 'orillacart' ) }
			sandbox="allow-scripts"
			referrerPolicy="no-referrer"
			srcDoc={ definition.editor ?? '' }
			style={ {
				display: 'block',
				width: '100%',
				border: 0,
				minHeight: 120,
			} }
		/>
	);
}

/**
 * The block's editing: its settings in the sidebar, the server's rendering in the canvas, the blocks it holds
 * below.
 *
 * @param definition The block and its settings.
 */
export function siteBlockEdit( definition: SiteBlockDefinition ) {
	return function SiteBlockEdit( {
		attributes,
		setAttributes,
	}: {
		attributes: Values;
		setAttributes: ( next: Values ) => void;
	} ) {
		return (
			<div { ...useBlockProps() }>
				{ ( definition.settings.length > 0 || definition.editor ) && (
					<InspectorControls>
						<PanelBody title={ __( 'Settings', 'orillacart' ) }>
							{ definition.editor && (
								<EditorFrame
									definition={ definition }
									attributes={ attributes }
									onChange={ setAttributes }
								/>
							) }
							{ definition.settings.map( ( setting ) => (
								<SettingControl
									key={ setting.key }
									setting={ setting }
									value={ attributes[ setting.key ] }
									onChange={ ( value ) =>
										setAttributes( {
											[ setting.key ]: value,
										} )
									}
								/>
							) ) }
						</PanelBody>
					</InspectorControls>
				) }
				<ServerSideRender
					block={ definition.name }
					attributes={ attributes }
					EmptyResponsePlaceholder={ () => (
						<Placeholder
							label={ definition.name }
							instructions={ __(
								'Nothing to show with these settings.',
								'orillacart'
							) }
						/>
					) }
				/>
				{ definition.inner && <InnerBlocks /> }
			</div>
		);
	};
}

// Title, icon and attributes come from the server's registration.
for ( const definition of (
	window as unknown as { orillacartSiteBlocks?: SiteBlockDefinition[] }
 ).orillacartSiteBlocks ?? [] ) {
	registerBlockType( definition.name, {
		edit: siteBlockEdit( definition ),
		save: definition.inner ? () => <InnerBlocks.Content /> : () => null,
	} as never );
}
