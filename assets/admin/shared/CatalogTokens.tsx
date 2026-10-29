import { FormTokenField } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/*
 * Fields that pick products, categories or brands by name, for rules that apply to some of the catalog
 * (coupons, price rules).
 */

export interface Named {
	id: number;
	name: string;
}

const decoded = ( html: string ): string => {
	const decode = document.createElement( 'textarea' );
	decode.innerHTML = html;
	return decode.value;
};

/**
 * The terms of a product taxonomy, by name.
 *
 * @param taxonomy "orillacart_category" or "orillacart_brand".
 */
export async function fetchTerms( taxonomy: string ): Promise< Named[] > {
	// Every term, past the API's 100 a page: apiFetch reads each page for per_page=-1.
	const terms = await apiFetch< { id: number; name: string }[] >( {
		path: `/wp/v2/${ taxonomy }?per_page=-1&_fields=id,name&orderby=name`,
	} );
	return terms.map( ( term ) => ( {
		id: term.id,
		name: decoded( term.name ),
	} ) );
}

/**
 * Products by name or by id, at most 20 found: each product once, by its own id, found by its name in any of the
 * site's languages and named in the admin's (GET /orillacart/v1/product-choices).
 *
 * @param query A name to search, or the ids to name.
 */
export async function fetchProducts(
	query: { search: string } | { include: number[] }
): Promise< Named[] > {
	if ( 'include' in query && query.include.length === 0 ) {
		return [];
	}
	const products = await apiFetch< Named[] >( {
		path: addQueryArgs(
			'/orillacart/v1/product-choices',
			'search' in query
				? { search: query.search }
				: { include: query.include.join( ',' ) }
		),
	} );
	return products.map( ( product ) => ( {
		id: product.id,
		name: product.name || `#${ product.id }`,
	} ) );
}

export function TermTokens( {
	label,
	help,
	terms,
	ids,
	onChange,
}: {
	label: string;
	/** In place of the field's own "Separate with commas" hint. */
	help?: string;
	terms: Named[];
	ids: number[];
	onChange: ( ids: number[] ) => void;
} ) {
	const name = ( id: number ) =>
		terms.find( ( term ) => term.id === id )?.name ?? `#${ id }`;
	return (
		<FormTokenField
			__next40pxDefaultSize
			label={ label }
			value={ ids.map( name ) }
			suggestions={ terms.map( ( term ) => term.name ) }
			__experimentalExpandOnFocus
			help={ help }
			onChange={ ( tokens ) =>
				onChange(
					tokens
						.map( ( token ) => {
							const text =
								typeof token === 'string' ? token : token.value;
							return (
								terms.find( ( term ) => term.name === text )
									?.id ??
								ids.find( ( id ) => name( id ) === text ) ??
								0
							);
						} )
						.filter( ( id ) => id > 0 )
				)
			}
		/>
	);
}

export function ProductTokens( {
	label,
	help,
	ids,
	onChange,
	onError,
}: {
	label: string;
	help?: string;
	ids: number[];
	onChange: ( ids: number[] ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ known, setKnown ] = useState< Named[] >( [] );
	const [ found, setFound ] = useState< Named[] >( [] );

	useEffect( () => {
		fetchProducts( { include: ids } ).then( setKnown ).catch( onError );
		// Only the ids the coupon had when the form opened; picked ones join from the search.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const all = [ ...known, ...found ];
	const name = ( id: number ) =>
		all.find( ( product ) => product.id === id )?.name ?? `#${ id }`;
	return (
		<>
			<FormTokenField
				__next40pxDefaultSize
				label={ label }
				value={ ids.map( name ) }
				suggestions={ found.map( ( product ) => product.name ) }
				onInputChange={ ( search ) => {
					if ( search.trim().length >= 2 ) {
						fetchProducts( { search } )
							.then( setFound )
							.catch( onError );
					}
				} }
				onChange={ ( tokens ) => {
					setKnown( all );
					onChange(
						tokens
							.map( ( token ) => {
								const text =
									typeof token === 'string'
										? token
										: token.value;
								return (
									all.find(
										( product ) => product.name === text
									)?.id ?? 0
								);
							} )
							.filter( ( id ) => id > 0 )
					);
				} }
			/>
			{ help !== undefined && (
				<p className="components-form-token-field__help">{ help }</p>
			) }
		</>
	);
}
