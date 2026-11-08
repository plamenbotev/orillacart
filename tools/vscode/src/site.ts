/**
 * The shop over REST, with an API key: the two routes an editor needs (the static check over files sent in the
 * request, and the reference of the site's functions) and the extension routes the pull and push commands use.
 * Node's fetch, so the language server and the client share this file.
 */

export interface Finding {
	file: string;
	line: number;
	column: number;
	message: string;
}

export interface ReferenceFunction {
	name: string;
	summary: string;
	arguments: [ number, number | null ];
	needs: string | null;
}

export interface Reference {
	language: string;
	declarations: Record< string, string >;
	functions: ReferenceFunction[];
	filters: string[];
	tests: string[];
	keywords: string[];
	slots: string[];
}

export interface TestResult {
	name: string;
	ok: boolean;
	message: string | null;
	file: string | null;
	line: number | null;
	http: { method: string; url: string; status: number }[];
}

export interface ExtensionFile {
	path: string;
	content: string;
}

export interface Extension {
	id: string;
	name: string;
	version: string;
	state: string;
	files: ExtensionFile[] | null;
	findings: string[] | null;
}

export class SiteError extends Error {
	constructor(
		message: string,
		public readonly status: number
	) {
		super( message );
	}
}

export class Site {
	constructor(
		private readonly url: string,
		private readonly key: string
	) {}

	get configured(): boolean {
		return this.url !== '' && this.key !== '';
	}

	check(
		files: Record< string, string >,
		id?: string
	): Promise< { findings: Finding[] } > {
		return this.call( 'POST', '/script/check', { files, id } );
	}

	test(
		id: string,
		files: Record< string, string >
	): Promise< { tests: TestResult[] } > {
		return this.call( 'POST', `/extensions/${ id }/test`, { files } );
	}

	reference(): Promise< Reference > {
		return this.call( 'GET', '/script-reference' );
	}

	extension( id: string ): Promise< Extension > {
		return this.call( 'GET', `/extensions/${ id }` );
	}

	install(
		id: string,
		name: string,
		files: Record< string, string >
	): Promise< Extension > {
		return this.call( 'POST', '/extensions', { id, name, files } );
	}

	writeFile(
		id: string,
		path: string,
		content: string
	): Promise< Extension > {
		return this.call(
			'PUT',
			`/extensions/${ id }/files/${ encodeURIComponent( path ) }`,
			{ content }
		);
	}

	private async call< T >(
		method: string,
		path: string,
		body?: unknown
	): Promise< T > {
		const base = this.url.replace( /\/+$/, '' );
		const response = await fetch(
			`${ base }/wp-json/orillacart/v1${ path }`,
			{
				method,
				headers: {
					Authorization: `Bearer ${ this.key }`,
					'X-OrillaCart-Key': this.key,
					Accept: 'application/json',
					...( body === undefined
						? {}
						: { 'Content-Type': 'application/json' } ),
				},
				...( body === undefined
					? {}
					: { body: JSON.stringify( body ) } ),
			}
		);
		const text = await response.text();
		let parsed: unknown = null;
		try {
			parsed = text === '' ? null : JSON.parse( text );
		} catch {
			parsed = null;
		}
		if ( ! response.ok ) {
			const message =
				parsed &&
				typeof parsed === 'object' &&
				typeof ( parsed as { message?: unknown } ).message === 'string'
					? ( parsed as { message: string } ).message
					: `${ response.status } from ${ path }`;
			throw new SiteError( message, response.status );
		}
		return parsed as T;
	}
}
