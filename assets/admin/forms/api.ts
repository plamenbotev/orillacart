import apiFetch from '@wordpress/api-fetch';

import type { FieldTranslations } from '../shared/Translations';

const BASE = '/orillacart/v1';

export type Placement = 'product' | 'checkout';
export type Scope = 'all' | 'categories' | 'products';
export type FieldKind =
	'text' | 'textarea' | 'number' | 'date' | 'checkbox' | 'choice' | 'file';
export type Section = 'billing' | 'shipping' | 'order';

export const FILE_TYPES = [
	'image/jpeg',
	'image/png',
	'image/webp',
	'image/gif',
	'application/pdf',
] as const;

export interface FieldChoice {
	/** Names the choice in answers and conditions; made from the label until the form is saved. */
	value: string;
	label: string;
	/** Added to each item when chosen; product forms only. */
	price: string | null;
	/** The label in the site's other languages; left out, they stay as they are. */
	translations?: FieldTranslations;
}

export interface FormField {
	/** Names the answer; made from the label until the form is saved, then kept. */
	key: string;
	label: string;
	kind: FieldKind;
	required: boolean;
	help: string;
	placeholder: string;
	longest: number;
	choices: FieldChoice[];
	display: 'list' | 'buttons';
	price: string | null;
	min: string | null;
	max: string | null;
	whole: boolean;
	section: Section;
	condition: { field: string; values: string[] } | null;
	types: string[];
	largest: number;
	/** Its label, help and placeholder in the site's other languages, by part. */
	translations?: Partial<
		Record< 'label' | 'help' | 'placeholder', FieldTranslations >
	>;
}

export interface ShopForm {
	id: number;
	name: string;
	placement: Placement;
	active: boolean;
	position: number;
	scope: Scope;
	category_ids: number[];
	product_ids: number[];
	fields: FormField[];
}

export type FormInput = Omit< ShopForm, 'id' >;

export function fetchForms(): Promise< ShopForm[] > {
	return apiFetch< ShopForm[] >( { path: `${ BASE }/forms` } );
}

export function saveForm(
	id: number | null,
	input: FormInput
): Promise< ShopForm > {
	return apiFetch< ShopForm >(
		id === null
			? { path: `${ BASE }/forms`, method: 'POST', data: input }
			: { path: `${ BASE }/forms/${ id }`, method: 'PUT', data: input }
	);
}

export function deleteForm( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/forms/${ id }`, method: 'DELETE' } );
}

export function newField( kind: FieldKind = 'text' ): FormField {
	return {
		key: '',
		label: '',
		kind,
		required: false,
		help: '',
		placeholder: '',
		longest: kind === 'textarea' ? 1000 : 200,
		choices: [],
		display: 'list',
		price: null,
		min: null,
		max: null,
		whole: false,
		section: 'order',
		condition: null,
		types: [ ...FILE_TYPES ],
		largest: 10,
	};
}

const CYRILLIC: Record< string, string > = {
	а: 'a',
	б: 'b',
	в: 'v',
	г: 'g',
	д: 'd',
	е: 'e',
	ж: 'zh',
	з: 'z',
	и: 'i',
	й: 'y',
	к: 'k',
	л: 'l',
	м: 'm',
	н: 'n',
	о: 'o',
	п: 'p',
	р: 'r',
	с: 's',
	т: 't',
	у: 'u',
	ф: 'f',
	х: 'h',
	ц: 'ts',
	ч: 'ch',
	ш: 'sh',
	щ: 'sht',
	ъ: 'a',
	ь: 'y',
	ю: 'yu',
	я: 'ya',
	ё: 'yo',
	ы: 'y',
	э: 'e',
	є: 'ye',
	і: 'i',
	ї: 'yi',
};

/**
 * A key or value from a label: lowercase ASCII, as the shop makes them, unique among the taken ones.
 *
 * @param label   What the shop called it.
 * @param glue    "_" for field keys, "-" for choice values.
 * @param longest The most characters.
 * @param taken   Keys or values already used.
 */
export function slugOf(
	label: string,
	glue: '_' | '-',
	longest: number,
	taken: string[]
): string {
	let slug = [ ...label.toLowerCase() ]
		.map( ( letter ) => CYRILLIC[ letter ] ?? letter )
		.join( '' )
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, glue )
		.replace( new RegExp( `^\\${ glue }+|\\${ glue }+$`, 'g' ), '' );
	if ( slug === '' || ( glue === '_' && /^\d/.test( slug ) ) ) {
		slug = 'field' + ( slug === '' ? '' : glue + slug );
	}
	slug = slug
		.slice( 0, longest - 3 )
		.replace( new RegExp( `\\${ glue }+$` ), '' );
	let candidate = slug;
	for ( let n = 2; taken.includes( candidate ); n++ ) {
		candidate = `${ slug }${ glue }${ n }`;
	}
	return candidate;
}
