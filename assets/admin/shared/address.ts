/** A postal address as orders and customer accounts keep it. */
export interface Address {
	first_name: string;
	last_name: string;
	street: string;
	city: string;
	postcode: string;
	country: string;
	phone: string;
	state: string | null;
}

export const EMPTY_ADDRESS: Address = {
	first_name: '',
	last_name: '',
	street: '',
	city: '',
	postcode: '',
	country: '',
	phone: '',
	state: null,
};
