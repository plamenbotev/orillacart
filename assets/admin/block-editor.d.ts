// @wordpress/block-editor ships no type declarations; these are the parts the shop's code uses.
declare module '@wordpress/block-editor' {
	import type { ReactNode } from 'react';

	export function useBlockProps(
		props?: Record< string, unknown >
	): Record< string, unknown >;
	export function InspectorControls( props: {
		children?: ReactNode;
	} ): ReactNode;

	// The media library, opened by the render prop's open(); works inside the block editor.
	export function MediaUpload( props: {
		onSelect: ( media: { id: number } | { id: number }[] ) => void;
		allowedTypes?: string[];
		multiple?: boolean | 'add';
		gallery?: boolean;
		value?: number | number[];
		title?: string;
		render: ( props: { open: () => void } ) => ReactNode;
	} ): ReactNode;
	export function MediaUploadCheck( props: {
		children?: ReactNode;
		fallback?: ReactNode;
	} ): ReactNode;

	// Blocks inside a block (the Products block's card): what the editor stores between its comments.
	type InnerBlocksProps = {
		allowedBlocks?: string[];
		template?: [ string, Record< string, unknown >, unknown[]? ][];
		templateLock?: false | 'all' | 'insert' | 'contentOnly';
		renderAppender?: false | ( () => ReactNode );
	};
	export const InnerBlocks: ( ( props: InnerBlocksProps ) => ReactNode ) & {
		Content: () => ReactNode;
		ButtonBlockAppender: () => ReactNode;
	};
	export const store: string;
}
