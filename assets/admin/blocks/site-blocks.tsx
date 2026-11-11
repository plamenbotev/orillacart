/**
 * Add-ons' blocks for the whole site (extension API 24): the server lists each with its settings
 * (AddOnSiteBlocks), the sidebar is built from them, and the block shows as the server renders it.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

export interface SiteBlockSetting {
	key: string;
	label: string;
	type: 'text' | 'number' | 'boolean' | 'choice';
	help: string;
	choices: Record< string, string >;
}

export interface SiteBlockDefinition {
	name: string;
	settings: SiteBlockSetting[];
}

type Values = Record< string, string | number | boolean | undefined >;

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
	value: string | number | boolean | undefined;
	onChange: ( value: string | number | boolean | undefined ) => void;
} ) {
	if ( setting.type === 'boolean' ) {
		return (
			<ToggleControl
				label={ setting.label }
				help={ setting.help }
				checked={ value === true }
				onChange={ onChange }
			/>
		);
	}
	if ( setting.type === 'choice' ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				label={ setting.label }
				help={ setting.help }
				value={ typeof value === 'string' ? value : '' }
				options={ [
					{ label: __( 'Choose', 'orillacart' ), value: '' },
					...Object.entries( setting.choices ).map(
						( [ choice, label ] ) => ( { value: choice, label } )
					),
				] }
				onChange={ ( choice ) =>
					onChange( choice === '' ? undefined : choice )
				}
			/>
		);
	}
	return (
		<TextControl
			__next40pxDefaultSize
			label={ setting.label }
			help={ setting.help }
			type={ setting.type === 'number' ? 'number' : 'text' }
			value={ value === undefined ? '' : String( value ) }
			onChange={ ( text ) => {
				if ( setting.type !== 'number' ) {
					onChange( text );
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
 * The block's editing: its settings in the sidebar, the server's rendering in the canvas.
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
				{ definition.settings.length > 0 && (
					<InspectorControls>
						<PanelBody title={ __( 'Settings', 'orillacart' ) }>
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
		save: () => null,
	} as never );
}
