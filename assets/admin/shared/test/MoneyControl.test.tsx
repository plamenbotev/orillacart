import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';

import { amountProblem, MoneyControl } from '../MoneyControl';

function Field( { decimals }: { decimals: number } ) {
	const [ value, setValue ] = useState( '' );
	return (
		<>
			<MoneyControl
				label="Price"
				value={ value }
				onChange={ setValue }
				decimals={ decimals }
			/>
			<output>{ value }</output>
		</>
	);
}

describe( 'MoneyControl', () => {
	it( 'takes a decimal comma as the point and says what is not an amount', async () => {
		const user = userEvent.setup();
		render( <Field decimals={ 2 } /> );

		await user.type( screen.getByLabelText( 'Price' ), '19,90' );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( '19.90' );

		await user.type( screen.getByLabelText( 'Price' ), '5' );
		expect( screen.getByText( 'Use up to 2 decimals.' ) ).toBeVisible();
	} );

	it( 'suggests and wants whole amounts in a currency without decimals', () => {
		render( <Field decimals={ 0 } /> );

		expect( screen.getByLabelText( 'Price' ) ).toHaveAttribute(
			'placeholder',
			'0'
		);
		expect( amountProblem( '1200.5', 0 ) ).toBe(
			'Use a whole amount: the currency has no decimals.'
		);
		expect( amountProblem( '1200', 0 ) ).toBeNull();
		expect( amountProblem( 'ten', 2 ) ).toBe(
			'Use an amount such as 19.90.'
		);
	} );
} );
