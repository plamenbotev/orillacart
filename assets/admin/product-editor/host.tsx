/**
 * The editor the product's forms are in: WordPress's block editor, or the classic edit screen (the Classic Editor
 * plugin, or a site that turned the block editor off for products). The forms ask it for the post, its meta and
 * when the post is saved, and work the same in both.
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	createContext,
	useContext,
	useEffect,
	useRef,
	useSyncExternalStore,
} from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

import { errorMessage } from '../shared/api';

export type Meta = Record< string, unknown >;

/** A save that goes with the post's; it throws to stop the classic screen's submit. */
export type SaveWithPost = () => Promise< void >;

export type HostNotice = { status: 'error' | 'success'; message: string };

export interface EditorHost {
	usePostId: () => number | null;
	/** The post's meta as edited, and an edit, saved with the post. */
	useMeta: () => [ Meta, ( edits: Meta ) => void ];
	/** Runs the save with the post's: after it in the block editor, before the page is sent in the classic one. */
	useSaveWithPost: ( save: SaveWithPost ) => void;
	notify: ( notice: HostNotice ) => void;
	/** The notices the forms show themselves: the classic screen has no notices area of the editor's. */
	useNotices: () => HostNotice[];
}

const BLOCK_NOTICE = 'orillacart-product';

/** The block editor: its store has the post and its meta, and tells when it saves. */
export const blockEditorHost: EditorHost = {
	usePostId: () =>
		useSelect(
			( select ) =>
				select( editorStore ).getCurrentPostId() as number | null,
			[]
		),
	useMeta: () => {
		const meta = useSelect(
			( select ) =>
				( select( editorStore ).getEditedPostAttribute( 'meta' ) as
					Meta | undefined ) ?? {},
			[]
		);
		const { editPost } = useDispatch( editorStore );
		return [ meta, ( edits: Meta ) => editPost( { meta: edits } ) ];
	},
	useSaveWithPost: ( save ) => {
		const { isSaving, isAutosaving, saveFailed } = useSelect(
			( select ) => ( {
				isSaving: select( editorStore ).isSavingPost(),
				isAutosaving: select( editorStore ).isAutosavingPost(),
				saveFailed: select( editorStore ).didPostSaveRequestFail(),
			} ),
			[]
		);
		const { createErrorNotice } = useDispatch( noticesStore );
		// When a save that is not an autosave finishes.
		const wasSaving = useRef( false );
		useEffect( () => {
			if (
				wasSaving.current &&
				! isSaving &&
				! isAutosaving &&
				! saveFailed
			) {
				save().catch( ( error: unknown ) => {
					void createErrorNotice( errorMessage( error ), {
						id: BLOCK_NOTICE,
					} );
				} );
			}
			wasSaving.current = isSaving && ! isAutosaving;
		}, [ isSaving, isAutosaving, saveFailed, save, createErrorNotice ] );
	},
	notify: ( notice ) => {
		void dispatch( noticesStore ).createNotice(
			notice.status,
			notice.message,
			{
				id: BLOCK_NOTICE,
				type: notice.status === 'success' ? 'snackbar' : 'default',
			}
		);
	},
	useNotices: () => [],
};

/**
 * The classic edit screen: one post form for every box on it. The boxes' meta edits and saves are kept here,
 * shared by them, and run when the form is sent: the meta through the REST API, then each save, then the form
 * goes on. A refusal keeps the page, with the reason in the box.
 */
class ClassicPost {
	readonly postId: number | null;
	private meta: Meta = {};
	private edits: Meta = {};
	/** The meta as edited, the same object until it changes, as useSyncExternalStore needs. */
	private snapshot: Meta = {};
	private notices: HostNotice[] = [];
	private readonly saves = new Set< SaveWithPost >();
	private readonly listeners = new Set< () => void >();
	private sending = false;

	constructor( form: HTMLFormElement | null ) {
		const id = Number(
			( document.getElementById( 'post_ID' ) as HTMLInputElement | null )
				?.value
		);
		this.postId = id > 0 ? id : null;
		if ( this.postId !== null ) {
			apiFetch< { meta?: Meta } >( {
				path: `/wp/v2/orillacart_product/${ this.postId }?context=edit&_fields=meta`,
			} )
				.then( ( post ) => {
					this.meta = post.meta ?? {};
					this.changed();
				} )
				.catch( ( error: unknown ) =>
					this.notify( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
		// Before WordPress's own handlers, which lock the buttons for the page to go.
		form?.addEventListener(
			'submit',
			( event ) => this.submitted( form, event ),
			true
		);
	}

	values(): Meta {
		return this.snapshot;
	}

	edit( edits: Meta ): void {
		this.edits = { ...this.edits, ...edits };
		this.changed();
	}

	add( save: SaveWithPost ): () => void {
		this.saves.add( save );
		return () => this.saves.delete( save );
	}

	notify( notice: HostNotice ): void {
		this.notices = [ notice ];
		this.changed();
	}

	shown(): HostNotice[] {
		return this.notices;
	}

	subscribe = ( listener: () => void ): ( () => void ) => {
		this.listeners.add( listener );
		return () => this.listeners.delete( listener );
	};

	private changed(): void {
		this.snapshot = { ...this.meta, ...this.edits };
		for ( const listener of this.listeners ) {
			listener();
		}
	}

	private submitted( form: HTMLFormElement, event: SubmitEvent ): void {
		const submitter = event.submitter as HTMLElement | null;
		// The preview shows the post as it stands; the shop's details go when it is saved.
		if ( this.sending || submitter?.id === 'post-preview' ) {
			return;
		}
		event.preventDefault();
		event.stopImmediatePropagation();
		void this.save().then( ( saved ) => {
			if ( ! saved ) {
				return;
			}
			this.sending = true;
			if (
				submitter instanceof HTMLInputElement ||
				submitter instanceof HTMLButtonElement
			) {
				form.requestSubmit( submitter );
			} else {
				form.requestSubmit();
			}
		} );
	}

	private async save(): Promise< boolean > {
		try {
			if (
				this.postId !== null &&
				Object.keys( this.edits ).length > 0
			) {
				const saved = await apiFetch< { meta?: Meta } >( {
					path: `/wp/v2/orillacart_product/${ this.postId }?context=edit&_fields=meta`,
					method: 'POST',
					data: { meta: this.edits },
				} );
				this.meta = saved.meta ?? this.snapshot;
				this.edits = {};
			}
			for ( const save of this.saves ) {
				await save();
			}
			this.notices = [];
			this.changed();
			return true;
		} catch ( error ) {
			this.notify( { status: 'error', message: errorMessage( error ) } );
			document
				.getElementById( 'orillacart-product-data' )
				?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			return false;
		}
	}
}

let classicPost: ClassicPost | null = null;

/** The classic screen's post, one for every box on the page. */
function classic(): ClassicPost {
	classicPost ??= new ClassicPost(
		document.getElementById( 'post' ) as HTMLFormElement | null
	);
	return classicPost;
}

export const classicEditorHost: EditorHost = {
	usePostId: () => classic().postId,
	useMeta: () => {
		const post = classic();
		const meta = useSyncExternalStore( post.subscribe, () =>
			post.values()
		);
		return [ meta, ( edits: Meta ) => post.edit( edits ) ];
	},
	useSaveWithPost: ( save ) => {
		useEffect( () => classic().add( save ), [ save ] );
	},
	notify: ( notice ) => classic().notify( notice ),
	useNotices: () => {
		const post = classic();
		return useSyncExternalStore( post.subscribe, () => post.shown() );
	},
};

/** For tests: the classic screen read afresh. */
export function forgetClassicPost(): void {
	classicPost = null;
}

const HostContext = createContext< EditorHost >( blockEditorHost );

export const EditorHostProvider = HostContext.Provider;

export const useEditorHost = (): EditorHost => useContext( HostContext );
