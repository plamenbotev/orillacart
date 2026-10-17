// @wordpress/block-editor ships no type declarations; these are the parts the shop's blocks use.
declare module '@wordpress/block-editor' {
	import type { ReactNode } from 'react';

	export function useBlockProps(
		props?: Record< string, unknown >
	): Record< string, unknown >;
	export function InspectorControls( props: {
		children?: ReactNode;
	} ): ReactNode;
}
