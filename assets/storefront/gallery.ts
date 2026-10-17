/**
 * The product page's pictures: thumbnails that show their picture in the page's main image (the theme's
 * featured image, img.wp-post-image), or in the panel's own stage when the page has none. A chosen variation
 * brings its own pictures; without one the product's come back.
 */

export type Picture = {
	id: number;
	src: string;
	srcset: string;
	sizes: string;
	alt: string;
	thumb: string;
};

export type Gallery = {
	/** Shows these pictures, the first in the main image; null for the product's own. */
	use: ( pictures: Picture[] | null ) => void;
};

export function bindGallery( product: Element ): Gallery | null {
	const strip = product.querySelector< HTMLElement >(
		'[data-orillacart-gallery]'
	);
	if ( ! strip ) {
		return null;
	}
	const own = JSON.parse( strip.dataset.images ?? '[]' ) as Picture[];
	const stage = product.querySelector< HTMLElement >(
		'[data-orillacart-stage]'
	);
	const main =
		document.querySelector< HTMLImageElement >( 'img.wp-post-image' ) ??
		( stage ? stage.appendChild( document.createElement( 'img' ) ) : null );
	const original = main
		? {
				src: main.getAttribute( 'src' ),
				srcset: main.getAttribute( 'srcset' ),
				sizes: main.getAttribute( 'sizes' ),
				alt: main.getAttribute( 'alt' ),
			}
		: null;
	let current = own;

	const restore = (
		name: 'src' | 'srcset' | 'sizes' | 'alt',
		value: string | null | undefined
	) => {
		if ( ! main ) {
			return;
		}
		if ( value ) {
			main.setAttribute( name, value );
		} else {
			main.removeAttribute( name );
		}
	};

	const show = ( index: number ) => {
		const picture = current[ index ];
		if ( ! main ) {
			return;
		}
		if ( picture ) {
			restore( 'src', picture.src );
			restore( 'srcset', picture.srcset );
			restore( 'sizes', picture.sizes );
			restore( 'alt', picture.alt );
		} else if ( original ) {
			restore( 'src', original.src );
			restore( 'srcset', original.srcset );
			restore( 'sizes', original.sizes );
			restore( 'alt', original.alt );
		}
		if ( stage ) {
			// The stage stands in only on pages without a featured image, and only with a picture to show.
			stage.hidden = main.parentElement !== stage || ! picture;
		}
		strip
			.querySelectorAll( '[data-orillacart-image]' )
			.forEach( ( button, i ) => {
				button.setAttribute( 'aria-pressed', String( i === index ) );
			} );
	};

	const render = () => {
		strip.replaceChildren(
			...current.map( ( picture, index ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'orillacart-gallery__thumb';
				button.dataset.orillacartImage = String( index );
				button.setAttribute(
					'aria-label',
					picture.alt || String( index + 1 )
				);
				const image = document.createElement( 'img' );
				image.src = picture.thumb;
				image.alt = '';
				image.width = 64;
				image.height = 64;
				button.append( image );
				return button;
			} )
		);
		strip.hidden = current.length < 2;
	};

	strip.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof Element
				? event.target.closest< HTMLElement >(
						'[data-orillacart-image]'
					)
				: null;
		if ( button ) {
			show( Number( button.dataset.orillacartImage ) );
		}
	} );

	return {
		use: ( pictures ) => {
			const next =
				pictures !== null && pictures.length > 0 ? pictures : own;
			if ( next === current ) {
				return;
			}
			current = next;
			render();
			show( 0 );
		},
	};
}
