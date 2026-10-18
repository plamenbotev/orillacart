import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	deleteShippingClass,
	saveShippingClass,
	type ShippingClass,
} from './api';

interface Props {
	classes: ShippingClass[];
	onChange: ( classes: ShippingClass[] ) => void;
	onError: ( error: unknown ) => void;
}

const byName = ( a: ShippingClass, b: ShippingClass ) =>
	a.name.localeCompare( b.name );

/**
 * Shipping classes: kinds of goods, such as "Bulky", that only some rates carry. A product has one or none.
 *
 * @param props          The card's properties.
 * @param props.classes  The classes there are.
 * @param props.onChange Receives the classes after a change.
 * @param props.onError  Receives what went wrong.
 */
export function ShippingClassesCard( { classes, onChange, onError }: Props ) {
	const [ name, setName ] = useState( '' );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Shipping classes', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Give bulky or fragile goods a class in the product editor, then choose which classes each rate carries.',
						'orillacart'
					) }
				</p>
				{ classes.map( ( shippingClass ) => (
					<ClassRow
						key={ shippingClass.id }
						shippingClass={ shippingClass }
						onSaved={ ( saved ) =>
							onChange(
								classes
									.map( ( current ) =>
										current.id === saved.id
											? saved
											: current
									)
									.sort( byName )
							)
						}
						onDeleted={ () =>
							onChange(
								classes.filter(
									( current ) =>
										current.id !== shippingClass.id
								)
							)
						}
						onError={ onError }
					/>
				) ) }
				<Flex align="flex-end">
					<FlexItem isBlock>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'New class', 'orillacart' ) }
							value={ name }
							onChange={ setName }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ name.trim() === '' }
							onClick={ () =>
								saveShippingClass( null, name )
									.then( ( saved ) => {
										setName( '' );
										onChange(
											[ ...classes, saved ].sort( byName )
										);
									} )
									.catch( onError )
							}
						>
							{ __( 'Add class', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardBody>
		</Card>
	);
}

function ClassRow( {
	shippingClass,
	onSaved,
	onDeleted,
	onError,
}: {
	shippingClass: ShippingClass;
	onSaved: ( saved: ShippingClass ) => void;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( shippingClass.name );

	return (
		<Flex align="flex-end">
			<FlexItem isBlock>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Class name', 'orillacart' ) }
					value={ name }
					onChange={ setName }
				/>
			</FlexItem>
			<FlexItem>
				<Button
					variant="secondary"
					disabled={
						name.trim() === '' || name === shippingClass.name
					}
					onClick={ () =>
						saveShippingClass( shippingClass.id, name )
							.then( onSaved )
							.catch( onError )
					}
				>
					{ __( 'Rename', 'orillacart' ) }
				</Button>
			</FlexItem>
			<FlexItem>
				<Button
					variant="tertiary"
					isDestructive
					onClick={ () =>
						deleteShippingClass( shippingClass.id )
							.then( onDeleted )
							.catch( onError )
					}
				>
					{ __( 'Delete', 'orillacart' ) }
				</Button>
			</FlexItem>
		</Flex>
	);
}
