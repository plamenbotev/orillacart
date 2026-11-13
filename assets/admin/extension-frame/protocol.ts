/**
 * The messages between an extension's frame and the admin (design 73). The frame has an origin of its own, so
 * each side posts to the other with '*' and checks who sent what it receives: the admin by the frame's window,
 * the frame by its parent's.
 */

export const FROM_FRAME = 'orillacart-frame';
export const FROM_HOST = 'orillacart-host';

export type Violation = { field: string; message: string };

export type ApiResult =
	| { ok: true; status: number; data: unknown }
	| {
			ok: false;
			status: number;
			code: string;
			message: string;
			violations: Violation[];
			/** Seconds until a caller turned away (429) may call again; null otherwise. */
			retryAfter: number | null;
	  };

export type MediaItem = {
	id: number;
	url: string;
	alt: string;
	title: string;
	mime: string;
	width: number | null;
	height: number | null;
};

export type Request =
	| {
			kind: 'api';
			payload: { method: string; path: string; body?: unknown };
	  }
	| { kind: 'notice'; payload: { text: string; tone?: string } }
	| { kind: 'navigate'; payload: { url: string } }
	| { kind: 'media'; payload: { type?: string; multiple?: boolean } }
	| { kind: 'block.get'; payload: null }
	| { kind: 'block.set'; payload: Record< string, unknown > }
	| { kind: 'height'; payload: number }
	| { kind: 'hello'; payload: null };

export type FrameMessage = Request & { source: typeof FROM_FRAME; id: number };

export type HostMessage = {
	source: typeof FROM_HOST;
	id: number;
	ok: boolean;
	value?: unknown;
	error?: string;
};
