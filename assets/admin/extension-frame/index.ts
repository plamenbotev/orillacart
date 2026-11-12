/**
 * The extensions' admin screens (design 73): each frame the page holds is answered by the host.
 */
import { attach } from './host';

document
	.querySelectorAll< HTMLIFrameElement >( 'iframe[data-orillacart-frame]' )
	.forEach( ( element ) => {
		attach( { element, extension: element.dataset.extension ?? '' } );
	} );
