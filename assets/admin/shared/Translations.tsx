import apiFetch from '@wordpress/api-fetch';
import { TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { sprintf, __ } from '@wordpress/i18n';

/** A language of the site's content, as Polylang or WPML names it. */
export interface ContentLanguage {
	code: string;
	locale: string;
	name: string;
	/** The fields' own values are in it. */
	default: boolean;
}

/** A field's value in the site's other languages, by locale. */
export type FieldTranslations = Record< string, string >;

let languages: Promise< ContentLanguage[] > | null = null;

/**
 * Whether the server's answer is a language, as /content-languages lists them.
 *
 * @param value One entry of the answer.
 */
const isLanguage = ( value: unknown ): value is ContentLanguage =>
	typeof value === 'object' &&
	value !== null &&
	typeof ( value as ContentLanguage ).locale === 'string' &&
	typeof ( value as ContentLanguage ).name === 'string' &&
	typeof ( value as ContentLanguage ).default === 'boolean';

/** Forgets the languages read, for tests. */
export function forgetContentLanguages() {
	languages = null;
}

/**
 * The site's languages other than the default, read once per page; none on a site in one language.
 *
 * @return The languages to translate into.
 */
export function useOtherLanguages(): ContentLanguage[] {
	const [ others, setOthers ] = useState< ContentLanguage[] >( [] );
	useEffect( () => {
		let current = true;
		languages ??= apiFetch< unknown >( {
			path: '/orillacart/v1/content-languages',
		} )
			.then( ( answer ) =>
				Array.isArray( answer ) ? answer.filter( isLanguage ) : []
			)
			.catch( () => [] );
		void languages.then( ( all ) => {
			if ( current ) {
				setOthers( all.filter( ( language ) => ! language.default ) );
			}
		} );
		return () => {
			current = false;
		};
	}, [] );
	return others;
}

interface Props {
	/** The field's label, as its own field shows it. */
	label: string;
	languages: ContentLanguage[];
	value: FieldTranslations | undefined;
	onChange: ( value: FieldTranslations ) => void;
}

/**
 * A field in each of the site's other languages, under the field itself; nothing with one language. An empty one
 * keeps the field's own value in that language.
 *
 * @param props           The fields' properties.
 * @param props.label     The field's label.
 * @param props.languages The languages to translate into.
 * @param props.value     The translations, by locale.
 * @param props.onChange  Receives all of them, changed.
 */
export function TranslationFields( {
	label,
	languages: others,
	value,
	onChange,
}: Props ) {
	if ( others.length === 0 ) {
		return null;
	}
	return (
		<div className="orillacart-translations">
			{ others.map( ( language ) => (
				<TextControl
					key={ language.locale }
					__next40pxDefaultSize
					lang={ language.locale.replace( '_', '-' ) }
					label={ sprintf(
						/* translators: 1: a field's label, such as "Option"; 2: a language, such as "Deutsch" */
						__( '%1$s in %2$s', 'orillacart' ),
						label,
						language.name
					) }
					value={ value?.[ language.locale ] ?? '' }
					onChange={ ( text ) =>
						onChange( { ...value, [ language.locale ]: text } )
					}
				/>
			) ) }
		</div>
	);
}
