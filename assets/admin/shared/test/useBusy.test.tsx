import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { useBusy } from '../useBusy';

function Send( { send }: { send: () => Promise< void > } ) {
	const [ busy, run ] = useBusy();
	return (
		<button aria-busy={ busy } onClick={ () => run( send ) }>
			Send
		</button>
	);
}

describe( 'useBusy', () => {
	it( 'starts one request however often the button is pressed', async () => {
		let finish = (): void => undefined;
		const send = jest.fn(
			() =>
				new Promise< void >( ( resolve ) => {
					finish = resolve;
				} )
		);
		const user = userEvent.setup();
		render( <Send send={ send } /> );

		await user.dblClick( screen.getByRole( 'button' ) );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'button' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);

		finish();
		await screen.findByRole( 'button', { busy: false } );
		await user.click( screen.getByRole( 'button' ) );
		expect( send ).toHaveBeenCalledTimes( 2 );
	} );
} );
