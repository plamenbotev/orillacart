import { useCallback, useEffect, useRef } from '@wordpress/element';

/**
 * Keeps only the answer to the latest of a screen's requests: when the filters change while a list loads, or a
 * quote is asked again before the last came back, the older answer arriving late is dropped, never shown over
 * the newer one. Nothing arrives once the screen is gone.
 *
 * @return Wraps a request; the promise it gives settles only when the request is still the latest.
 */
export function useLatest(): < T >( request: Promise< T > ) => Promise< T > {
	const latest = useRef( 0 );
	useEffect(
		() => () => {
			latest.current += 1;
		},
		[]
	);

	return useCallback( < T >( request: Promise< T > ): Promise< T > => {
		latest.current += 1;
		const ticket = latest.current;
		return new Promise< T >( ( resolve, reject ) => {
			request.then(
				( value ) => {
					if ( ticket === latest.current ) {
						resolve( value );
					}
				},
				( reason: unknown ) => {
					if ( ticket === latest.current ) {
						reject( reason );
					}
				}
			);
		} );
	}, [] );
}
