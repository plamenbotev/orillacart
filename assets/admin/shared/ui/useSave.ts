import { useBusy } from '../useBusy';
import { notify } from './toasts';

/**
 * Saving, the same on every screen: the button is busy while the request runs and a second click starts
 * nothing; done, a toast says so; failed, the screen's error handler says what went wrong.
 *
 * @param onError Receives the error of a save that failed.
 * @return The busy flag, and save(): the request, and what the toast says once it is done (a string, or a
 * function of the result); nothing for no toast. Resolves with the result, or undefined when it failed.
 */
export function useSave(
	onError: ( error: unknown ) => void
): [
	boolean,
	< T >(
		work: () => Promise< T >,
		saved?: string | ( ( result: T ) => string )
	) => Promise< T | undefined >,
] {
	const [ busy, run ] = useBusy();

	const save = < T >(
		work: () => Promise< T >,
		saved?: string | ( ( result: T ) => string )
	): Promise< T | undefined > =>
		new Promise( ( resolve ) => {
			run( () =>
				work()
					.then( ( result ) => {
						if ( saved !== undefined ) {
							notify(
								typeof saved === 'string'
									? saved
									: saved( result )
							);
						}
						resolve( result );
					} )
					.catch( ( error: unknown ) => {
						onError( error );
						resolve( undefined );
					} )
			);
		} );

	return [ busy, save ];
}
