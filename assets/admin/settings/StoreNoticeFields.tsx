import {
	Button,
	CheckboxControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	type ContentLanguage,
	TranslationFields,
} from '../shared/Translations';
import { useSavedCopy } from '../shared/useUnsavedChanges';
import type { StorefrontSettings } from './storefront';

/** The store notice's part of the storefront's settings. */
export type StoreNotice = Pick<
	StorefrontSettings,
	| 'notice'
	| 'notice_text'
	| 'notice_link'
	| 'notice_link_label'
	| 'notice_dismissible'
	| 'notice_translations'
>;

const noticeOf = ( settings: StorefrontSettings ): StoreNotice => ( {
	notice: settings.notice,
	notice_text: settings.notice_text,
	notice_link: settings.notice_link,
	notice_link_label: settings.notice_link_label,
	notice_dismissible: settings.notice_dismissible,
	// PHP sends an empty list for none.
	notice_translations: Array.isArray( settings.notice_translations )
		? {}
		: settings.notice_translations,
} );

interface Props {
	settings: StorefrontSettings;
	languages: ContentLanguage[];
	/** Saves the notice with the other settings as they are; the settings saved, or null when it failed. */
	onSave: ( notice: StoreNotice ) => Promise< StorefrontSettings | null >;
}

/**
 * The notice over the shop's pages: its words, in each language, a link, and whether shoppers may close it. Unlike
 * the choices above it, it is written first and saved with its own button.
 *
 * @param props           The fields' properties.
 * @param props.settings  The storefront's settings as saved.
 * @param props.languages The site's other languages.
 * @param props.onSave    Saves the notice.
 */
export function StoreNoticeFields( { settings, languages, onSave }: Props ) {
	const [ draft, setDraft ] = useState< StoreNotice >( () =>
		noticeOf( settings )
	);
	const [ saving, setSaving ] = useState( false );
	const loaded = useSavedCopy( draft );
	const [ saved, setSaved ] = useState< StoreNotice >( draft );
	useEffect( () => {
		loaded( saved );
	}, [ loaded, saved ] );

	const change = ( changed: Partial< StoreNotice > ) =>
		setDraft( { ...draft, ...changed } );
	const translate = ( field: string, values: Record< string, string > ) =>
		change( {
			notice_translations: {
				...draft.notice_translations,
				[ field ]: values,
			},
		} );
	const unchanged = JSON.stringify( draft ) === JSON.stringify( saved );

	return (
		<fieldset className="orillacart-store-notice-fields">
			<legend>
				<h3>{ __( 'Store notice', 'orillacart' ) }</h3>
			</legend>
			<CheckboxControl
				label={ __(
					'Show a notice over the shop’s pages',
					'orillacart'
				) }
				help={ __(
					'Wherever the Store notice block is: add it to the site’s header in the Site Editor.',
					'orillacart'
				) }
				checked={ draft.notice }
				onChange={ ( notice ) => change( { notice } ) }
			/>
			<TextareaControl
				label={ __( 'Notice', 'orillacart' ) }
				help={ __(
					'Such as “Free delivery this week”. Up to 300 characters, without formatting.',
					'orillacart'
				) }
				value={ draft.notice_text }
				maxLength={ 300 }
				onChange={ ( value ) => change( { notice_text: value } ) }
			/>
			<TranslationFields
				label={ __( 'Notice', 'orillacart' ) }
				languages={ languages }
				multiline
				value={ draft.notice_translations.text }
				onChange={ ( values ) => translate( 'text', values ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Link', 'orillacart' ) }
				help={ __(
					'An address starting with https://, or with / for a page of this site, such as /sale. Leave it empty for none.',
					'orillacart'
				) }
				value={ draft.notice_link }
				onChange={ ( value ) => change( { notice_link: value } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Link text', 'orillacart' ) }
				help={ __(
					'Such as “See the offers”. Leave it empty to make the notice itself the link.',
					'orillacart'
				) }
				value={ draft.notice_link_label }
				maxLength={ 60 }
				onChange={ ( value ) => change( { notice_link_label: value } ) }
			/>
			<TranslationFields
				label={ __( 'Link text', 'orillacart' ) }
				languages={ languages }
				value={ draft.notice_translations.link_label }
				onChange={ ( values ) => translate( 'link_label', values ) }
			/>
			<CheckboxControl
				label={ __( 'Shoppers may close it', 'orillacart' ) }
				help={ __(
					'Their browser remembers, until the notice changes.',
					'orillacart'
				) }
				checked={ draft.notice_dismissible }
				onChange={ ( value ) =>
					change( { notice_dismissible: value } )
				}
			/>
			<Button
				variant="primary"
				isBusy={ saving }
				disabled={ saving || unchanged }
				accessibleWhenDisabled
				onClick={ () => {
					setSaving( true );
					void onSave( draft ).then( ( result ) => {
						setSaving( false );
						if ( result !== null ) {
							const next = noticeOf( result );
							setDraft( next );
							setSaved( next );
						}
					} );
				} }
			>
				{ __( 'Save notice', 'orillacart' ) }
			</Button>
		</fieldset>
	);
}
