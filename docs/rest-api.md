# The shop's REST API

Written by `bin/rest-docs` from the routes themselves; do not edit by hand.

Every route is under `/wp-json/orillacart/v1` (or `?rest_route=/orillacart/v1/…` without pretty permalinks).
Bodies are JSON; field names are in snake_case. Answers are JSON, as the shop's screens read them.

## Who may call a route

- **Anyone**: the storefront's routes (the cart, checkout, receipts, reviews). Changes need a nonce:
  `GET /session` gives a visitor one, never cached; send it as `X-WP-Nonce`.
- **Logged-in customers** and **shop managers**: with WordPress's login cookie and a nonce
  (`X-WP-Nonce`, `wp_create_nonce('wp_rest')`), as the shop's screens do.
- **Integrations**: with an API key from Shop → Integrations, `Authorization: Bearer oc_…` (or
  `X-OrillaCart-Key` where the host drops `Authorization`), acting as the shop manager who made it. A
  read-only key changes nothing. Keys need no nonce. WordPress's application passwords (Basic auth) work
  too.

## Errors

`{"code": "orillacart_…", "message": "…", "data": {"status": 422}}`, with `data.violations`
(`[{"field", "message"}]`) for fields that are not valid. 401: log in, or a key the shop has not;
403: not allowed, or the nonce is missing or expired; 404: no such thing; 422: refused, as the message says;
429: too many tries, see `Retry-After`.

## /account

### `GET /account`

The logged-in customer's account: who they are and the addresses checkout fills in.

- Who: logged-in customers

### `POST /account/addresses`

The customer saves a new address in their address book, or changes one.

- Who: logged-in customers; needs a nonce (not with an API key)
- Body (JSON):
  - `address` (AddressData): required
  - `billing` (bool): default false
  - `shipping` (bool): default false

### `PUT /account/addresses/{address}`

The customer saves a new address in their address book, or changes one.

- Who: logged-in customers; needs a nonce (not with an API key)
- Body (JSON):
  - `address` (AddressData): required
  - `billing` (bool): default false
  - `shipping` (bool): default false

### `DELETE /account/addresses/{address}`

- Who: logged-in customers; needs a nonce (not with an API key)

### `GET /account/downloads`

Every file the logged-in customer bought and may download.

- Who: logged-in customers

### `PUT /account/emails`

The customer chooses the newsletter, and the reminders of carts they leave.

- Who: logged-in customers; needs a nonce (not with an API key)
- Body (JSON):
  - `cart_reminders` (bool): default true
  - `newsletter` (bool|null)

### `GET /account/orders`

The logged-in customer's orders, newest first. Whatever customer the query names, it is this one.

- Who: logged-in customers
- Query:
  - `status` (string|null): matches `/^(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)(,(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)){0,7}$/`
  - `search` (string|null): at most 100 characters
  - `customer_id` (int|null): more than 0
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20
  - `attention` (bool): default false
  - `placed_from` (string|null): date time
  - `placed_before` (string|null): date time
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `min_total` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `max_total` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `placed_by` (string|null): one of account, guest
  - `order_by` (string): one of number, total; default 'number'
  - `order` (string): one of asc, desc; default 'desc'
  - `paid` (string|null): one of unpaid, paid, partly_refunded, refunded
  - `shipped` (string|null): one of yes, no
  - `shipping` (string|null): at most 200 characters
  - `country` (string|null): matches `/^[A-Z]{2}$/`
  - `delivery_from` (string|null): a date, Y-m-d
  - `delivery_until` (string|null): a date, Y-m-d
  - `has` (string|null): matches `/^[a-z][a-z0-9_]{0,39}(,[a-z][a-z0-9_]{0,39}){0,19}$/`
  - `currency` (string|null): matches `/^[A-Z]{3}$/`

### `GET /account/orders/{id}`

- Who: logged-in customers

### `GET /account/tax-exemptions`

The signed-in customer's tax exemption certificates, and whether the shop takes them online.

- Who: logged-in customers

### `POST /account/tax-exemptions`

The signed-in customer sends a tax exemption certificate for the shop to check: multipart, with reason, certificate, regions[] ("US-TX"), expires_on, and its copy in "file". The copy is never served to visitors.

- Who: logged-in customers; needs a nonce (not with an API key); at most 10 an hour from each address

### `GET /account/wishlist`

The products the customer saved for later, newest first.

- Who: logged-in customers

### `POST /account/wishlist`

The wishlist kept in the browser before the customer logged in joins their account's.

- Who: logged-in customers; needs a nonce (not with an API key)
- Body (JSON):
  - `product_ids` (list<int>): at most 100 items; each more than 0

### `PUT /account/wishlist/{product}`

The customer saves a product for later, or takes it off their wishlist.

- Who: logged-in customers; needs a nonce (not with an API key)

### `DELETE /account/wishlist/{product}`

The customer saves a product for later, or takes it off their wishlist.

- Who: logged-in customers; needs a nonce (not with an API key)

## /accounts

### `POST /accounts`

A visitor asks for an account. The answer is the same whether the email has one or not; the email tells them.

- Who: anyone; needs a nonce (not with an API key); at most 5 an hour from each address
- Body (JSON):
  - `email` (string): required; not empty; an email; at most 200 characters
  - `first_name` (string): required; not empty; at most 200 characters
  - `last_name` (string): required; not empty; at most 200 characters

### `POST /accounts/confirm`

The visitor followed the emailed link and chose a password: the account is made and the browser logged in.

- Who: anyone; needs a nonce (not with an API key); at most 10 an hour from each address
- Body (JSON):
  - `token` (string): required; matches `/^[a-f0-9]{64}$/`
  - `password` (string): required; not empty; at most 200 characters

## /api-keys

### `GET /api-keys`

- Who: shop managers

### `POST /api-keys`

A new key, acting as the shop manager who makes it; the answer holds the key, shown this once.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): not empty; at most 100 characters
  - `scope` (string): one of read, read_write; default 'read'

### `DELETE /api-keys/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /attribute-sets

### `GET /attribute-sets`

- Who: shop managers

### `POST /attribute-sets`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `active` (bool): default true
  - `attributes` (list<SaveAttribute>)

### `GET /attribute-sets/{id}`

- Who: shop managers

### `PUT /attribute-sets/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `active` (bool): default true
  - `attributes` (list<SaveAttribute>)

### `DELETE /attribute-sets/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /back-in-stock

### `POST /back-in-stock`

A visitor asks to hear when a sold-out item is back; answered alike whether or not they asked before.

- Who: anyone; needs a nonce (not with an API key); at most 10 an hour from each address
- Body (JSON):
  - `product_id` (int): required; more than 0
  - `email` (string): required; not empty; an email; at most 254 characters
  - `variation_id` (int|null): more than 0

## /carriers

### `GET /carriers`

- Who: shop managers

### `POST /carriers`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `active` (bool): default true
  - `position` (int): 0 or more; default 0
  - `rates` (list<SaveShippingRate>)
  - `method` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `method_settings` (array)
  - `method_taxable` (bool): default true
  - `method_tax_group_id` (int|null): more than 0
  - `pickup_locations` (list<SavePickupLocation>): at most 100 items
  - `delivery_schedule` (SaveDeliverySchedule|null)
  - `classes_kept_to_their_rates` (bool): default false

### `GET /carriers/{id}`

- Who: shop managers

### `PUT /carriers/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `active` (bool): default true
  - `position` (int): 0 or more; default 0
  - `rates` (list<SaveShippingRate>)
  - `method` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `method_settings` (array)
  - `method_taxable` (bool): default true
  - `method_tax_group_id` (int|null): more than 0
  - `pickup_locations` (list<SavePickupLocation>): at most 100 items
  - `delivery_schedule` (SaveDeliverySchedule|null)
  - `classes_kept_to_their_rates` (bool): default false

### `DELETE /carriers/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /cart

### `GET /cart`

- Who: anyone

### `POST /cart/checkout-contact`

The checkout page tells of the customer at checkout with their cart: the email so far, and whether they ask to be reminded of the cart if they leave it.

- Who: anyone; needs a nonce (not with an API key); at most 20 every ten minutes from each address
- Body (JSON):
  - `email` (string|null): an email; at most 254 characters
  - `remind` (bool): default false

### `POST /cart/coupons`

- Who: anyone; needs a nonce (not with an API key); at most 20 every ten minutes from each address
- Body (JSON):
  - `code` (string): required; not empty; at most 40 characters

### `DELETE /cart/coupons/{code}`

- Who: anyone; needs a nonce (not with an API key); at most 20 every ten minutes from each address

### `POST /cart/gift-cards`

- Who: anyone; needs a nonce (not with an API key); at most 10 every ten minutes from each address
- Body (JSON):
  - `code` (string): required; not empty; at most 40 characters

### `DELETE /cart/gift-cards/{code}`

- Who: anyone; needs a nonce (not with an API key)

### `POST /cart/items`

- Who: anyone; needs a nonce (not with an API key); at most 120 every ten minutes from each address
- Body (JSON):
  - `product_id` (int): required; more than 0
  - `option_ids` (list<int>): each more than 0
  - `quantity` (int): 1 to 9999; default 1
  - `bundle_choices` (list<int>): at most 100 items; each more than 0
  - `personalisation` (array): at most 100 items

### `PUT /cart/items/{id}`

- Who: anyone; needs a nonce (not with an API key)
- Body (JSON):
  - `quantity` (int): required; 0 to 9999

### `DELETE /cart/items/{id}`

- Who: anyone; needs a nonce (not with an API key)

### `PUT /cart/store-credit`

- Who: anyone; needs a nonce (not with an API key)
- Body (JSON):
  - `use` (bool): default true

## /change-log

### `GET /change-log`

The change log, newest first: ?area=payment-methods&user_id=1&page=2.

- Who: shop managers
- Query:
  - `area` (string|null): at most 64 characters
  - `user_id` (int|null): more than 0
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 50

## /checkout

### `POST /checkout`

- Who: anyone; needs a nonce (not with an API key); at most 30 every ten minutes from each address
- Body (JSON):
  - `email` (string): required; not empty; an email; at most 200 characters
  - `billing` (AddressData): required
  - `shipping_address` (AddressData|null)
  - `shipping_option` (string|null): matches `/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/`
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `note` (string): at most 2000 characters
  - `create_account` (bool): default false
  - `remember_addresses` (bool): default true
  - `pickup_point` (string|null): at most 64 characters
  - `fields` (array): at most 100 items
  - `vat_number` (string|null): at most 20 characters
  - `buyer_reference` (string|null): at most 100 characters; matches `/^[^\x00-\x1F<>]*$/`
  - `expected_total` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `delivery_date` (string|null): a date, Y-m-d
  - `delivery_slot` (string|null): at most 40 characters
  - `newsletter` (bool): default false
  - `accepts_terms` (bool): default false

### `POST /checkout/addresses`

What the add-ons' address checks say of the addresses on the checkout form, before the order is placed. Changes nothing; guarded, as each call may cost the shop a lookup at the register.

- Who: anyone; needs a nonce (not with an API key); at most 30 an hour from each address
- Body (JSON):
  - `billing` (AddressData): required
  - `shipping_address` (AddressData|null)

### `POST /checkout/summary`

The totals for the addresses and shipping the customer has chosen so far. Changes nothing.

- Who: anyone; needs a nonce (not with an API key)
- Body (JSON):
  - `shipping_country` (string|null): matches `/^[A-Z]{2}$/`
  - `shipping_state` (string|null): matches `/^[A-Z0-9]{1,3}$/`
  - `billing_country` (string|null): matches `/^[A-Z]{2}$/`
  - `billing_state` (string|null): matches `/^[A-Z0-9]{1,3}$/`
  - `shipping_option` (string|null): matches `/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/`
  - `shipping_postcode` (string|null): at most 20 characters
  - `pickup_point` (string|null): at most 64 characters
  - `vat_number` (string|null): at most 20 characters
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `delivery_date` (string|null): a date, Y-m-d
  - `delivery_slot` (string|null): at most 40 characters
  - `billing_postcode` (string|null): at most 20 characters
  - `shipping_city` (string|null): at most 100 characters
  - `billing_city` (string|null): at most 100 characters

## /countries

### `GET /countries`

Public: checkout and account forms list the countries before anyone logs in.

- Who: anyone

### `GET /countries/{code}/states`

- Who: anyone

## /coupons

### `GET /coupons`

- Who: shop managers
- Query:
  - `search` (string|null): at most 100 characters
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20
  - `automatic` (bool): default false

### `POST /coupons`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `code` (string): required; at most 40 characters
  - `kind` (string): required; one of percent, order_amount, item_amount, free_shipping, buy_get, free_gift
  - `amount` (string): matches `/^\d{1,11}(\.\d{1,4})?$/`; default '0'
  - `description` (string): at most 255 characters
  - `cap` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `active` (bool): default true
  - `starts_at` (string|null): date time
  - `ends_at` (string|null): date time
  - `min_subtotal` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `max_subtotal` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `min_quantity` (int): 0 or more; default 0
  - `countries` (list<string>): at most 300 items
  - `product_ids` (list<int>): at most 1000 items
  - `category_ids` (list<int>): at most 1000 items
  - `brand_ids` (list<int>): at most 1000 items
  - `excluded_product_ids` (list<int>): at most 1000 items
  - `excluded_category_ids` (list<int>): at most 1000 items
  - `exclude_sale_items` (bool): default false
  - `combines` (bool): default false
  - `usage_limit` (int|null): more than 0
  - `per_customer_limit` (int|null): more than 0
  - `first_order_only` (bool): default false
  - `automatic` (bool): default false
  - `buy_quantity` (int): 0 to 1000; default 0
  - `get_quantity` (int): 0 to 1000; default 0
  - `get_product_ids` (list<int>): at most 1000 items
  - `get_category_ids` (list<int>): at most 1000 items
  - `gift_product_id` (int|null): more than 0
  - `overrides_price_rules` (bool): default false
  - `free_shipping_every_rate` (bool): default false

### `GET /coupons/{id}`

- Who: shop managers

### `PUT /coupons/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `code` (string): required; at most 40 characters
  - `kind` (string): required; one of percent, order_amount, item_amount, free_shipping, buy_get, free_gift
  - `amount` (string): matches `/^\d{1,11}(\.\d{1,4})?$/`; default '0'
  - `description` (string): at most 255 characters
  - `cap` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `active` (bool): default true
  - `starts_at` (string|null): date time
  - `ends_at` (string|null): date time
  - `min_subtotal` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `max_subtotal` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `min_quantity` (int): 0 or more; default 0
  - `countries` (list<string>): at most 300 items
  - `product_ids` (list<int>): at most 1000 items
  - `category_ids` (list<int>): at most 1000 items
  - `brand_ids` (list<int>): at most 1000 items
  - `excluded_product_ids` (list<int>): at most 1000 items
  - `excluded_category_ids` (list<int>): at most 1000 items
  - `exclude_sale_items` (bool): default false
  - `combines` (bool): default false
  - `usage_limit` (int|null): more than 0
  - `per_customer_limit` (int|null): more than 0
  - `first_order_only` (bool): default false
  - `automatic` (bool): default false
  - `buy_quantity` (int): 0 to 1000; default 0
  - `get_quantity` (int): 0 to 1000; default 0
  - `get_product_ids` (list<int>): at most 1000 items
  - `get_category_ids` (list<int>): at most 1000 items
  - `gift_product_id` (int|null): more than 0
  - `overrides_price_rules` (bool): default false
  - `free_shipping_every_rate` (bool): default false

### `DELETE /coupons/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /currencies

### `GET /currencies`

- Who: shop managers

### `PUT /currencies`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `offered` (list<OfferedCurrencyView>): at most 20 items

## /customer-groups

### `GET /customer-groups`

- Who: shop managers

### `POST /customer-groups`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters

### `PUT /customer-groups/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters

### `DELETE /customer-groups/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /customer-settings

### `GET /customer-settings`

- Who: shop managers

### `PUT /customer-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `accounts` (string): required; one of optional, required, disabled
  - `guest_orders` (bool|null)

## /customers

### `GET /customers`

- Who: shop managers
- Query:
  - `search` (string|null): at most 100 characters
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20

### `GET /customers/{id}`

- Who: shop managers

### `POST /customers/{id}/addresses`

The shop saves a new address in a customer's address book, or changes one.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `address` (AddressData): required
  - `billing` (bool): default false
  - `shipping` (bool): default false

### `PUT /customers/{id}/addresses/{address}`

The shop saves a new address in a customer's address book, or changes one.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `address` (AddressData): required
  - `billing` (bool): default false
  - `shipping` (bool): default false

### `DELETE /customers/{id}/addresses/{address}`

- Who: shop managers; needs a nonce (not with an API key)

### `PUT /customers/{id}/groups`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `group_ids` (list<int>): at most 100 items; each more than 0

### `GET /customers/{id}/notes`

- Who: shop managers

### `POST /customers/{id}/notes`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `text` (string): required; not empty; at most 2000 characters

### `DELETE /customers/{id}/notes/{note}`

- Who: shop managers; needs a nonce (not with an API key)

### `GET /customers/{id}/store-credit`

- Who: shop managers

### `POST /customers/{id}/store-credit`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `amount` (string): required; matches `/^-?\d{1,11}(\.\d{1,4})?$/`
  - `note` (string): required; not empty; at most 255 characters

### `GET /customers/{id}/tax-exemptions`

A customer's exemptions from tax, newest first.

- Who: shop managers

### `POST /customers/{id}/tax-exemptions`

Records a customer's exemption from tax, from their certificate.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `reason` (string): required; one of resale, nonprofit, government, other
  - `certificate` (string): required; not empty; at most 64 characters
  - `regions` (array): at most 100 items
  - `expires_on` (string|null): a date, Y-m-d
  - `note` (string): at most 200 characters

### `DELETE /customers/{id}/tax-exemptions/{exemption}`

Removes a customer's exemption; orders it covered keep it.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /customers/{id}/tax-exemptions/{exemption}/approve`

The shop approves a certificate the customer sent, saying where it holds and until when: their orders into those places are no longer taxed. {regions: ["US-TX"], expires_on: "2027-12-31"|null}

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `regions` (list<string>): required; at least 1 items
  - `expires_on` (string|null): a date, Y-m-d

### `POST /customers/{id}/tax-exemptions/{exemption}/refuse`

The shop refuses a certificate the customer sent, and tells them why.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `reason` (string): required; not empty; at most 200 characters

## /download-delivery

### `GET /download-delivery`

- Who: shop managers

### `PUT /download-delivery`

Tests a way of sending downloads and keeps it when it works. Answers 200 either way, with the outcome and the web server setting the method needs.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `method` (string): required; one of php, x_sendfile, x_accel_redirect
  - `internal_prefix` (string): at most 100 characters; matches `#^/?[A-Za-z0-9_/-]*/?$#`; default '/orillacart-files/'

## /download-storage

### `GET /download-storage`

Where download files are kept and whether the web server would hand them out by itself; ?fresh=1 tests again.

- Who: shop managers

## /email-settings

### `GET /email-settings`

- Who: shop managers

### `PUT /email-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `from_name` (string|null): at most 100 characters
  - `from_address` (string|null): an email
  - `shop_address` (string|null): an email
  - `disabled` (list<string>): each one of order_received, order_processing, order_backordered, tracking_added, order_shipped, ready_for_pickup, order_completed, payment_reminder, order_cancelled, order_failed, order_refunded, customer_note, receipt_link, cart_reminder, back_in_stock, account_welcome, gift_card, new_order, order_attention, payment_failed, refund_made, low_stock, exemption_received, exemption_reviewed, exemption_expiring
  - `reminder_days` (int): 1 to 60; default 3
  - `cart_reminder_hours` (int): 1 to 168; default 4
  - `cart_reminder_coupon` (string|null): at most 100 characters

## /email-templates

### `GET /email-templates`

- Who: shop managers

### `GET /email-templates/{name}`

- Who: shop managers

### `PUT /email-templates/{name}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `source` (string): required; not empty; at most 100000 characters

### `DELETE /email-templates/{name}`

Goes back to the template's file; answers with it.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /email-templates/{name}/preview`

The email a template makes, saved or as edited; a template that fails as it renders says why (422).

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `source` (string|null): at most 100000 characters
  - `kind` (string|null): one of order_received, order_processing, order_backordered, tracking_added, order_shipped, ready_for_pickup, order_completed, payment_reminder, order_cancelled, order_failed, order_refunded, customer_note, receipt_link, cart_reminder, back_in_stock, account_welcome, gift_card, new_order, order_attention, payment_failed, refund_made, low_stock, exemption_received, exemption_reviewed, exemption_expiring, account_confirm, account_exists
  - `order` (int|null): more than 0

### `POST /email-templates/{name}/test`

Sends the preview to whoever asks.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `source` (string|null): at most 100000 characters
  - `kind` (string|null): one of order_received, order_processing, order_backordered, tracking_added, order_shipped, ready_for_pickup, order_completed, payment_reminder, order_cancelled, order_failed, order_refunded, customer_note, receipt_link, cart_reminder, back_in_stock, account_welcome, gift_card, new_order, order_attention, payment_failed, refund_made, low_stock, exemption_received, exemption_reviewed, exemption_expiring, account_confirm, account_exists
  - `order` (int|null): more than 0

## /exports

### `GET /exports/orders/columns`

- Who: shop managers

### `PUT /exports/orders/columns`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `columns` (list<string>): at least 1 items

### `GET /exports/products/columns`

- Who: shop managers

### `PUT /exports/products/columns`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `columns` (list<string>): at least 1 items

## /form-uploads

### `POST /form-uploads`

A shopper sends a file for a product's file field: multipart, with product_id, field and the file in "file". The answer is the token it gives back; the file itself is never served to visitors.

- Who: anyone; needs a nonce (not with an API key); at most 20 an hour from each address

## /forms

### `GET /forms`

- Who: shop managers

### `POST /forms`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `placement` (string): one of product, checkout; default 'product'
  - `active` (bool): default true
  - `position` (int): default 0
  - `scope` (string): one of all, categories, products; default 'all'
  - `category_ids` (list<int>): each more than 0
  - `product_ids` (list<int>): each more than 0
  - `fields` (list<SaveFormField>): at most 30 items

### `GET /forms/{id}`

- Who: shop managers

### `PUT /forms/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `placement` (string): one of product, checkout; default 'product'
  - `active` (bool): default true
  - `position` (int): default 0
  - `scope` (string): one of all, categories, products; default 'all'
  - `category_ids` (list<int>): each more than 0
  - `product_ids` (list<int>): each more than 0
  - `fields` (list<SaveFormField>): at most 30 items

### `DELETE /forms/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /gift-cards

### `GET /gift-cards`

- Who: shop managers
- Query:
  - `search` (string): at most 100 characters
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20

### `POST /gift-cards`

A new gift card; the answer holds its code, shown this once.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `amount` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `expires_at` (string|null): date time
  - `recipient_name` (string): at most 100 characters
  - `recipient_email` (string): an email; at most 254 characters
  - `message` (string): at most 500 characters

### `GET /gift-cards/settings`

- Who: shop managers

### `PUT /gift-cards/settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `product_ids` (list<int>): at most 100 items; each more than 0
  - `valid_months` (int|null): 1 to 120

### `GET /gift-cards/{id}`

- Who: shop managers

### `PUT /gift-cards/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `active` (bool): default true
  - `expires_at` (string|null): date time

### `POST /gift-cards/{id}/adjustments`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `amount` (string): required; matches `/^-?\d{1,11}(\.\d{1,4})?$/`
  - `note` (string): required; not empty; at most 255 characters

### `POST /gift-cards/{id}/code`

A new code for a gift card, sent to whom it is for; the old one pays no more.

- Who: shop managers; needs a nonce (not with an API key)

## /imports

### `POST /imports/products`

Reads a products file, a multipart upload with the file in the "file" field, ready to start.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /imports/products/latest`

The newest import, or null: the screen shows what came of it.

- Who: shop managers

### `GET /imports/products/{id}`

- Who: shop managers

### `DELETE /imports/products/{id}`

- Who: shop managers; needs a nonce (not with an API key)

### `POST /imports/products/{id}/start`

- Who: shop managers; needs a nonce (not with an API key)

## /invoice-settings

### `GET /invoice-settings`

- Who: shop managers

### `PUT /invoice-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `legal_name` (string): at most 2000 characters
  - `address` (string): at most 2000 characters
  - `vat_number` (string): at most 2000 characters
  - `company_number` (string): at most 2000 characters
  - `bank_details` (string): at most 2000 characters
  - `footer` (string): at most 2000 characters
  - `invoice_prefix` (string): matches `/^[A-Za-z0-9\/._-]{0,20}$/`; default 'INV-'
  - `credit_note_prefix` (string): matches `/^[A-Za-z0-9\/._-]{0,20}$/`; default 'CN-'
  - `yearly` (bool): default true
  - `attach_to_emails` (bool): default true
  - `e_invoices` (string): one of none, ubl, cii; default 'none'
  - `phone` (string): matches `/^\+?[0-9 ().\/-]{3,30}$/`
  - `email` (string): at most 254 characters; an email
  - `peppol_id` (string): matches `/^\d{4}:[A-Za-z0-9._-]{1,50}$/`

## /jobs

### `GET /jobs`

- Who: shop managers

### `GET /jobs/failed`

- Who: shop managers
- Query:
  - `type` (string|null): at most 80 characters
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20

### `POST /jobs/failed/delete`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `ids` (list<string>): at most 500 items
  - `all` (bool): default false

### `POST /jobs/failed/retry`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `ids` (list<string>): at most 500 items
  - `all` (bool): default false

### `POST /jobs/workers/restart`

- Who: shop managers; needs a nonce (not with an API key)

## /legal-settings

### `GET /legal-settings`

The shop's legal pages, and whether checkout asks to accept the terms and what its order button says.

- Who: shop managers

### `PUT /legal-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `terms` (int|null): more than 0
  - `withdrawal` (int|null): more than 0
  - `delivery` (int|null): more than 0
  - `legal_notice` (int|null): more than 0
  - `accept_terms` (bool): default false
  - `order_button` (string): one of place_order, buy_now, order_and_pay; default 'place_order'

## /newsletter-settings

### `GET /newsletter-settings`

- Who: shop managers

### `PUT /newsletter-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `offered` (bool): default false
  - `consent` (string): not empty; at most 500 characters
  - `lists` (list<string>)

## /opening

### `GET /opening`

Whether visitors see "Opening soon" (GET); PUT opens the shop, DELETE shows "Opening soon" again.

- Who: shop managers

### `PUT /opening`

Whether visitors see "Opening soon" (GET); PUT opens the shop, DELETE shows "Opening soon" again.

- Who: shop managers; needs a nonce (not with an API key)

### `DELETE /opening`

Whether visitors see "Opening soon" (GET); PUT opens the shop, DELETE shows "Opening soon" again.

- Who: shop managers; needs a nonce (not with an API key)

## /orders

### `GET /orders`

- Who: shop managers
- Query:
  - `status` (string|null): matches `/^(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)(,(pending_payment|on_hold|processing|shipped|completed|cancelled|failed|refunded)){0,7}$/`
  - `search` (string|null): at most 100 characters
  - `customer_id` (int|null): more than 0
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 20
  - `attention` (bool): default false
  - `placed_from` (string|null): date time
  - `placed_before` (string|null): date time
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `min_total` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `max_total` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `placed_by` (string|null): one of account, guest
  - `order_by` (string): one of number, total; default 'number'
  - `order` (string): one of asc, desc; default 'desc'
  - `paid` (string|null): one of unpaid, paid, partly_refunded, refunded
  - `shipped` (string|null): one of yes, no
  - `shipping` (string|null): at most 200 characters
  - `country` (string|null): matches `/^[A-Z]{2}$/`
  - `delivery_from` (string|null): a date, Y-m-d
  - `delivery_until` (string|null): a date, Y-m-d
  - `has` (string|null): matches `/^[a-z][a-z0-9_]{0,39}(,[a-z][a-z0-9_]{0,39}){0,19}$/`
  - `currency` (string|null): matches `/^[A-Z]{3}$/`

### `POST /orders`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `email` (string): required; not empty; an email; at most 200 characters
  - `billing` (AddressData): required
  - `items` (list<EditedItem>): required; at least 1 items
  - `shipping_address` (AddressData|null)
  - `customer_id` (int|null): more than 0
  - `shipping_option` (string|null): matches `/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/`
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `paid` (bool): default false
  - `note` (string): at most 2000 characters
  - `email_customer` (bool): default false
  - `pickup_point` (string|null): at most 64 characters

### `GET /orders/facets`

The choices the orders list's filters offer: the ways orders shipped, their countries and currencies, and what an order may have.

- Who: shop managers

### `POST /orders/quote`

Prices an order the shop is taking, before it creates it. POST, since the items do not fit a query string.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `items` (list<EditedItem>)
  - `billing_country` (string|null): matches `/^[A-Z]{2}$/`
  - `billing_state` (string|null): matches `/^[A-Z0-9]{1,3}$/`
  - `shipping_country` (string|null): matches `/^[A-Z]{2}$/`
  - `shipping_state` (string|null): matches `/^[A-Z0-9]{1,3}$/`
  - `shipping_postcode` (string|null): at most 20 characters
  - `billing_postcode` (string|null): at most 20 characters
  - `shipping_option` (string|null): matches `/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/`
  - `customer_id` (int|null): more than 0
  - `email` (string|null): at most 100 characters
  - `payment` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`

### `GET /orders/{id}`

- Who: shop managers

### `DELETE /orders/{id}`

- Who: shop managers; needs a nonce (not with an API key)

### `GET /orders/{id}/actions`

- Who: shop managers

### `POST /orders/{id}/actions/{action}`

- Who: shop managers; needs a nonce (not with an API key)

### `POST /orders/{id}/adjustments`

Changes the price of an order on hold, or of one of its items; its taxes are worked out again.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `amount` (string): required; matches `/^-?\d{1,11}(\.\d{1,4})?$/`; not equal to
  - `label` (string): required; not empty; at most 200 characters
  - `line` (int|null): 0 or more
  - `reason` (string): at most 200 characters

### `DELETE /orders/{id}/adjustments/{change}`

Takes a change to the price off an order on hold; one for the whole order goes from every item.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /orders/{id}/anonymise`

The customer asked to be forgotten: the order keeps its sums for the books, without their details.

- Who: shop managers; needs a nonce (not with an API key)

### `DELETE /orders/{id}/attention`

The shop looked at what the order needed attention for: the flag goes, the history says so.

- Who: shop managers; needs a nonce (not with an API key)

### `PUT /orders/{id}/contact`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `email` (string): required; not empty; an email; at most 200 characters
  - `billing` (AddressData): required
  - `shipping_address` (AddressData|null)
  - `fields` (array): each at most 2000 characters

### `PUT /orders/{id}/customer`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `customer_id` (int|null): more than 0

### `PUT /orders/{id}/downloads/{grant}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `remaining` (int|null): 0 or more
  - `expires_at` (string|null): a date, Y-m-d

### `POST /orders/{id}/emails`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `kind` (string): required; one of order_received, order_processing, order_backordered, tracking_added, order_shipped, ready_for_pickup, order_completed, payment_reminder, order_cancelled, order_failed, new_order, payment_failed

### `GET /orders/{id}/invoices`

- Who: shop managers

### `PUT /orders/{id}/items`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `items` (list<EditedItem>): required; at least 1 items
  - `shipping_option` (string|null): matches `/^(rate:[1-9]\d{0,9}|carrier:[1-9]\d{0,9}:[a-z0-9_]{1,40}|pickup:[1-9]\d{0,9}:[a-z0-9][a-z0-9-]{0,39})$/`
  - `pickup_point` (string|null): at most 64 characters

### `POST /orders/{id}/notes`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `text` (string): required; not empty; at most 2000 characters
  - `for_customer` (bool): default false

### `POST /orders/{id}/paid`

The shop says the money of an order came, apart from moving it along.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `note` (string): at most 2000 characters

### `POST /orders/{id}/picked-up`

The customer collected the order from the shop's place: it is completed.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /orders/{id}/pickup-points`

The offices or lockers a service (?shipping_option=carrier:5:office) delivers the order to, for changing how it ships.

- Who: shop managers

### `POST /orders/{id}/ready-for-pickup`

Tells the customer the order is ready at the shop's place they collect it from.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /orders/{id}/refunds`

- Who: shop managers

### `POST /orders/{id}/refunds`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `amount` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `lines` (list<RefundLine>): at most 500 items
  - `shipping` (bool): default false
  - `restock` (bool): default false
  - `reason` (string): at most 2000 characters
  - `outside` (bool): default false
  - `store_credit` (bool): default false

### `POST /orders/{id}/restock`

Items of a refunded order came back: they go back in stock.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `lines` (list<RefundLine>): required; at least 1 items

### `GET /orders/{id}/returns`

An order's returns, and what may still go back.

- Who: shop managers

### `GET /orders/{id}/shipments`

An order's shipments, and whether its carrier's courier books one.

- Who: shop managers

### `POST /orders/{id}/shipments`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `parcels` (int): 1 to 50; default 1
  - `items` (list<ShipItem>): at most 500 items

### `POST /orders/{id}/shipments/sent`

Records a shipment the shop sent itself, with each parcel's tracking number.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `carrier` (string): required; not empty; at most 100 characters
  - `parcels` (list<SaveParcel>): required; 1 to 50 items
  - `items` (list<ShipItem>): at most 500 items

### `GET /orders/{id}/shipping-options`

The ways an order can ship to its address, for changing how it ships.

- Who: shop managers

### `PUT /orders/{id}/status`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `status` (string): required; one of pending_payment, on_hold, processing, shipped, completed, cancelled, failed, refunded
  - `note` (string): at most 2000 characters

## /payment-methods

### `GET /payment-methods`

- Who: shop managers

### `PUT /payment-methods/{gateway}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `active` (bool): default false
  - `position` (int): 0 or more; default 0
  - `countries` (list<string>)
  - `instructions` (string): at most 5000 characters
  - `receipt_note` (string): at most 5000 characters
  - `settings` (array)
  - `fee` (string): matches `/^\d{1,11}(\.\d{1,4})?$/`; default '0'
  - `fee_percent` (string): matches `/^\d{1,3}(\.\d{1,4})?$/`; at most 100; default '0'
  - `fee_label` (string): at most 100 characters

### `POST /payment-methods/{gateway}/test`

Tries a gateway's settings at the provider, as the form has them; nothing is saved.

- Who: shop managers; needs a nonce (not with an API key); at most 20 every ten minutes from each address
- Body (JSON):
  - `settings` (array)

## /payments

### `GET /payments/{gateway}/cancel`

Providers send customers here who gave up paying; the customer then sees the order's receipt.

- Who: anyone

### `GET /payments/{gateway}/return`

Providers send customers back here after they paid; the customer then sees the order's receipt.

- Who: anyone

### `POST /payments/{gateway}/webhook`

Providers' notifications (webhooks). They carry no nonce: the gateway checks with the provider that it sent them before anything is read.

- Who: the payment provider, signed

## /price-rules

### `GET /price-rules`

- Who: shop managers

### `POST /price-rules`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `tiers` (list<PriceTierData>): required; 1 to 20 items
  - `group_ids` (list<int>): at most 100 items
  - `product_ids` (list<int>): at most 1000 items
  - `category_ids` (list<int>): at most 1000 items
  - `brand_ids` (list<int>): at most 1000 items
  - `starts_at` (string|null): date time
  - `ends_at` (string|null): date time
  - `with_sales` (bool): default false
  - `active` (bool): default true
  - `with_coupons` (bool): default true
  - `combines` (bool): default false

### `PUT /price-rules/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `tiers` (list<PriceTierData>): required; 1 to 20 items
  - `group_ids` (list<int>): at most 100 items
  - `product_ids` (list<int>): at most 1000 items
  - `category_ids` (list<int>): at most 1000 items
  - `brand_ids` (list<int>): at most 1000 items
  - `starts_at` (string|null): date time
  - `ends_at` (string|null): date time
  - `with_sales` (bool): default false
  - `active` (bool): default true
  - `with_coupons` (bool): default true
  - `combines` (bool): default false

### `DELETE /price-rules/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /problems

### `GET /problems`

The problems the shop logged, the latest first (?page=2), and clearing them (DELETE).

- Who: shop managers

### `DELETE /problems`

The problems the shop logged, the latest first (?page=2), and clearing them (DELETE).

- Who: shop managers; needs a nonce (not with an API key)

## /product-cards

### `GET /product-cards`

The cards of published products, by id (?ids=12,30), as the shop's lists show them: for a visitor's wishlist and the products they viewed, which their browser keeps, so the page around them can be cached.

- Who: anyone

## /products

### `GET /products/{id}`

- Who: shop managers

### `PUT /products/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `price` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `type` (string): one of physical, virtual; default 'physical'
  - `sale` (SaveSale|null)
  - `sku` (string|null): 1 to 64 characters
  - `taxable` (bool): default true
  - `tax_group_id` (int|null): more than 0
  - `stock_mode` (string): one of tracked, always_available, unavailable; default 'tracked'
  - `purchasable` (bool): default true
  - `featured` (bool): default false
  - `weight` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `length` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `width` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `height` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `set_ids` (list<int>): each more than 0
  - `attributes` (list<SaveAttribute>)
  - `download_limit` (int|null): more than 0
  - `download_expiry_days` (int|null): more than 0
  - `price_visibility` (string): one of shop, shown, hidden; default 'shop'
  - `shipping_class_id` (int|null): more than 0
  - `backorders` (string): one of no, yes, notify; default 'no'
  - `backorder_limit` (int|null): more than 0
  - `bundle` (SaveBundle|null)
  - `low_stock_threshold` (int|null): 0 or more
  - `tax_code` (string|null): matches `/^[A-Za-z0-9][A-Za-z0-9._:-]{0,39}$/`

### `POST /products/{id}/bundle-quote`

A bundle's price and stock with the customer's choices, for the product page while they choose: the price is shown as the page shows prices. Changes nothing, so it is a POST only to carry the choices.

- Who: anyone; needs a nonce (not with an API key)
- Body (JSON):
  - `bundle_choices` (list<int>): at most 100 items; each more than 0

### `POST /products/{id}/downloads`

Adds a file to a product's downloads: a multipart upload with the file in the "file" field.

- Who: shop managers; needs a nonce (not with an API key)

### `PUT /products/{id}/downloads/{file}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 200 characters

### `DELETE /products/{id}/downloads/{file}`

- Who: shop managers; needs a nonce (not with an API key)

### `GET /products/{id}/reviews`

A page of a product's published reviews, as its page shows them to anyone.

- Who: anyone

### `POST /products/{id}/reviews`

A visitor reviews a product, as the shop's policy and the site's discussion settings let them.

- Who: anyone; needs a nonce (not with an API key); at most 10 an hour from each address
- Body (JSON):
  - `rating` (int): 1 to 5; default 0
  - `text` (string): not empty; at most 5000 characters
  - `name` (string|null): at most 100 characters
  - `email` (string|null): an email; at most 100 characters
  - `receipt` (string|null): matches `/^[0-9]{1,10}-[0-9]{1,6}-[a-f0-9]{32}$/`

### `POST /products/{id}/variations`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `option_ids` (list<int>): required; at least 1 items; each more than 0
  - `price` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `sale` (SaveSale|null)
  - `sku` (string|null): 1 to 64 characters
  - `stock_mode` (string): one of tracked, always_available, unavailable; default 'tracked'
  - `weight` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `length` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `width` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `height` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `backorders` (string|null): one of no, yes, notify
  - `backorder_limit` (int|null): more than 0
  - `low_stock_threshold` (int|null): 0 or more

### `PUT /products/{id}/variations/{variation}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `option_ids` (list<int>): required; at least 1 items; each more than 0
  - `price` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
  - `sale` (SaveSale|null)
  - `sku` (string|null): 1 to 64 characters
  - `stock_mode` (string): one of tracked, always_available, unavailable; default 'tracked'
  - `weight` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `length` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `width` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `height` (string|null): matches `/^\d{1,8}(\.\d{1,4})?$/`
  - `backorders` (string|null): one of no, yes, notify
  - `backorder_limit` (int|null): more than 0
  - `low_stock_threshold` (int|null): 0 or more

### `DELETE /products/{id}/variations/{variation}`

- Who: shop managers; needs a nonce (not with an API key)

## /receipts

### `POST /receipts/link`

A visitor with an expired receipt link asks for a new one: it goes to the order's email, never to them.

- Who: anyone; needs a nonce (not with an API key); at most 5 an hour from each address
- Body (JSON):
  - `receipt` (string): required; matches `/^[0-9]{1,10}-[0-9]{1,6}-[a-f0-9]{32}$/`

### `POST /receipts/lookup`

A guest looks up an order by its number and email: the receipt link goes to the order's email, never to them.

- Who: anyone; needs a nonce (not with an API key); at most 5 an hour from each address
- Body (JSON):
  - `number` (int): required; more than 0
  - `email` (string): required; not empty; an email

### `GET /receipts/{receipt}`

An order's receipt, for whoever holds a link to it the shop made: the customer gets one when ordering and with each email, so no account is needed. Never cached, nor its address sent on.

- Who: anyone; at most 60 every ten minutes from each address

### `POST /receipts/{receipt}/payment`

Pays an unpaid order from its receipt, for whoever holds a link to it: where to send the customer next.

- Who: anyone; needs a nonce (not with an API key); at most 60 every ten minutes from each address
- Body (JSON):
  - `payment` (string): required; not empty; at most 40 characters

### `POST /receipts/{receipt}/reorder`

Whoever holds the link to a receipt puts the order's items in their cart again.

- Who: anyone; needs a nonce (not with an API key); at most 60 every ten minutes from each address

### `POST /receipts/{receipt}/returns`

The customer holding the order's receipt asks to send some of its items back.

- Who: anyone; needs a nonce (not with an API key); at most 10 an hour from each address
- Body (JSON):
  - `items` (list<ReturnItem>): required; 1 to 500 items
  - `reason` (string): required; one of damaged, wrong_item, not_as_described, no_longer_needed, other
  - `comment` (string): at most 1000 characters

## /refunds

### `POST /refunds/{id}/give-up`

- Who: shop managers; needs a nonce (not with an API key)

### `POST /refunds/{id}/retry`

- Who: shop managers; needs a nonce (not with an API key)

## /reports

### `GET /reports/carts`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `GET /reports/categories`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `GET /reports/coupons`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `GET /reports/customers`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `GET /reports/eu-distance-sales`

A shop in the EU against the EU's threshold of distance sales.

- Who: shop managers

### `GET /reports/nexus`

The shop's sales in each US state against its economic nexus thresholds.

- Who: shop managers

### `GET /reports/products`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `POST /reports/rebuild`

Counts every order again, in the background.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /reports/sales`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

### `GET /reports/taxes`

- Who: shop managers
- Query:
  - `from` (string|null): a date, Y-m-d
  - `to` (string|null): a date, Y-m-d
  - `interval` (string|null): one of day, week, month
  - `compare` (string): one of previous, year, none; default 'previous'
  - `limit` (int): 1 to 100; default 10
  - `sort` (string): one of net, quantity; default 'net'

## /returns

### `POST /returns/{id}/approve`

Approves a return, telling the customer how to send the items.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `answer` (string): at most 2000 characters

### `POST /returns/{id}/receive`

The returned items came back to the shop.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /returns/{id}/refund`

Gives the money back for the items that came back, and puts them back in stock.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `outside` (bool): default false
  - `restock` (bool): default true
  - `store_credit` (bool): default false

### `POST /returns/{id}/refuse`

Refuses a return, telling the customer why.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `answer` (string): at most 2000 characters

## /review-settings

### `GET /review-settings`

- Who: shop managers

### `PUT /review-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `policy` (string): one of off, anyone, buyers; default 'anyone'

## /sample-data

### `GET /sample-data`

Whether the sample products are in the shop (GET), adds them (POST), or takes them away (DELETE).

- Who: shop managers

### `POST /sample-data`

Whether the sample products are in the shop (GET), adds them (POST), or takes them away (DELETE).

- Who: shop managers; needs a nonce (not with an API key)

### `DELETE /sample-data`

Whether the sample products are in the shop (GET), adds them (POST), or takes them away (DELETE).

- Who: shop managers; needs a nonce (not with an API key)

## /search-engine

### `GET /search-engine`

- Who: shop managers

### `PUT /search-engine`

Switches the search engine after checking OpenSearch answers; the view says what the check found.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `engine` (string): required; one of database, opensearch
  - `host` (string): at most 300 characters
  - `username` (string): at most 200 characters
  - `password` (string): at most 200 characters
  - `index` (string): at most 100 characters; default 'orillacart'
  - `clear_password` (bool): default false

### `POST /search-engine/rebuild`

- Who: shop managers; needs a nonce (not with an API key)

## /search-filters

### `GET /search-filters`

- Who: shop managers

### `POST /search-filters`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `label` (string): required; not empty; at most 100 characters
  - `key` (string): required; matches `/^[a-z0-9][a-z0-9_-]{0,39}$/`
  - `attributes` (list<string>): required; 1 to 20 items; each at most 100 characters
  - `position` (int): 0 or more; default 0

### `GET /search-filters/attributes`

- Who: shop managers

### `PUT /search-filters/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `label` (string): required; not empty; at most 100 characters
  - `key` (string): required; matches `/^[a-z0-9][a-z0-9_-]{0,39}$/`
  - `attributes` (list<string>): required; 1 to 20 items; each at most 100 characters
  - `position` (int): 0 or more; default 0

### `DELETE /search-filters/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /session

### `GET /session`

A visitor's nonce for the storefront script: visitors' pages carry none, since a page cache would serve one that has expired. Never cached. Logged-in users' pages carry their own: the REST API ignores login cookies on a request without a nonce, so this route only ever sees visitors. For the same reason another site that reads it gets nothing more than a visitor's nonce, and the cart cookie (SameSite=Lax) is not sent to it.

- Who: anyone; at most 120 every ten minutes from each address

## /settings

### `GET /settings`

- Who: shop managers

### `PUT /settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `currency` (string): required; matches `/^[A-Z]{3}$/`
  - `fewer_decimals` (int|null): required; 0 or more
  - `weight_unit` (string): required; one of kg, g, lb, oz
  - `dimension_unit` (string): required; one of cm, m, mm, in
  - `shop_country` (string|null): required; matches `/^[A-Z]{2}$/`
  - `shop_state` (string|null): required; matches `/^[A-Z0-9]{1,3}$/`
  - `shop_postcode` (string|null): 1 to 20 characters
  - `currency_sign` (string): at most 8 characters
  - `currency_position` (string): one of before, before_space, after, after_space; default 'after_space'
  - `catalog_only` (bool): default false
  - `hide_prices` (bool): default false
  - `sell_to` (list<string>)
  - `show_lowest_price` (bool): default false
  - `remove_data_on_uninstall` (bool): default false
  - `low_stock_threshold` (int): 0 or more; default 2
  - `unpaid_hold_days` (int|null): 1 to 365
  - `return_days` (int|null): 1 to 365
  - `minimum_order` (string|null): matches `/^(\d{1,11}(\.\d{1,4})?)?$/`

## /settings-archive

### `GET /settings-archive`

The shop's settings as a file (GET /settings-archive), and a file of them taken in (PUT), section by section.

- Who: shop managers

### `PUT /settings-archive`

The shop's settings as a file (GET /settings-archive), and a file of them taken in (PUT), section by section.

- Who: shop managers; needs a nonce (not with an API key)

## /setup

### `GET /setup`

Whether the setup is finished (GET), and that it is (PUT), so the admin stops offering it.

- Who: shop managers

### `PUT /setup`

Whether the setup is finished (GET), and that it is (PUT), so the admin stops offering it.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /setup/answers`

The setup's answers so far, by step: null for a step not answered yet.

- Who: shop managers

### `PUT /setup/answers/{step}`

Keeps one step's answers, as its questions ask for them; nothing in the shop changes until the plan is applied.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /setup/apply`

Makes the plan the owner saw, replacing only what they chose to; the settings from before are kept.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `fingerprint` (string): required; matches `/^[0-9a-f]{64}$/`
  - `replace` (list<string>): at most 500 items; each at most 100 characters

### `GET /setup/countries/{country}`

What the setup knows of a country: its money and units, how it taxes, what its law asks of a shop.

- Who: shop managers

### `POST /setup/plan`

What the answers would change, in words, with what there is already; nothing is saved.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /setup/readiness`

Whether the shop is ready to sell: what stops it, then what is advised.

- Who: shop managers

### `GET /setup/shipping-zones`

The zones the shipping step prices, from where the answers so far say the shop sells.

- Who: shop managers

## /shipments

### `POST /shipments/{id}/arrived`

A shipment the shop sent itself arrived, or came back.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `status` (string): one of delivered, returned; default 'delivered'

### `POST /shipments/{id}/cancel`

Cancels a booked shipment with the courier, as before it picks the parcel up.

- Who: shop managers; needs a nonce (not with an API key)

### `GET /shipments/{id}/label`

A shipment's label, base64 in JSON, as the REST API speaks it; the admin screen saves or prints it.

- Who: shop managers

## /shipping-classes

### `GET /shipping-classes`

- Who: shop managers

### `POST /shipping-classes`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters

### `PUT /shipping-classes/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters

### `DELETE /shipping-classes/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /shipping-methods

### `GET /shipping-methods`

The shipping methods add-ons registered, which a carrier can be priced with, with their settings.

- Who: shop managers

### `POST /shipping-methods/{method}/test`

Tries a shipping method's settings at the courier, as the form has them; nothing is saved.

- Who: shop managers; needs a nonce (not with an API key); at most 20 every ten minutes from each address
- Body (JSON):
  - `settings` (array)
  - `carrier_id` (int|null): more than 0

## /shop-pages

### `GET /shop-pages`

- Who: shop managers

### `PUT /shop-pages`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `shop` (int|null): more than 0
  - `cart` (int|null): more than 0
  - `checkout` (int|null): more than 0
  - `account` (int|null): more than 0

## /status

### `GET /status`

- Who: shop managers

## /stock-history

### `GET /stock-history`

- Who: shop managers
- Query:
  - `product` (int|null): more than 0
  - `stockroom` (int|null): more than 0
  - `reason` (string|null): one of sale, release, return, count, import, bulk_edit
  - `order` (int|null): more than 0
  - `from` (string|null): date time
  - `before` (string|null): date time
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 50

## /stock-levels

### `PATCH /stock-levels`

Saves the cells of the stock sheet that changed, all or none.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `changes` (list<ChangeStockLevel>): required; 1 to 2000 items

## /stock-sheet

### `GET /stock-sheet`

- Who: shop managers
- Query:
  - `kind` (string): one of items, options; default 'items'
  - `stockroom` (int|null): more than 0
  - `category` (int|null): more than 0
  - `search` (string|null): at most 100 characters
  - `parent` (int|null): more than 0
  - `page` (int): more than 0; default 1
  - `per_page` (int): 1 to 100; default 25

## /stock

### `GET /stock/{kind}/{id}`

- Who: shop managers

### `PUT /stock/{kind}/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `levels` (list<SetStockLevel>): required

## /stockrooms

### `GET /stockrooms`

- Who: shop managers

### `POST /stockrooms`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `min_delivery_days` (int): 0 to 365; default 0
  - `max_delivery_days` (int): 0 to 365; default 0
  - `active` (bool): default true
  - `description` (string): at most 2000 characters
  - `delivery_unit` (string): one of days, weeks; default 'days'
  - `low_stock_threshold` (int|null): 0 or more

### `PUT /stockrooms/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `min_delivery_days` (int): 0 to 365; default 0
  - `max_delivery_days` (int): 0 to 365; default 0
  - `active` (bool): default true
  - `description` (string): at most 2000 characters
  - `delivery_unit` (string): one of days, weeks; default 'days'
  - `low_stock_threshold` (int|null): 0 or more

### `DELETE /stockrooms/{id}`

- Who: shop managers; needs a nonce (not with an API key)

## /storefront-settings

### `GET /storefront-settings`

- Who: shop managers

### `PUT /storefront-settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `structured_data` (bool): default true
  - `sharing_tags` (string): one of auto, always, never; default 'auto'
  - `wishlist` (string): one of guests, accounts, off; default 'guests'
  - `recently_viewed` (bool): default true
  - `data_layer` (bool): default false
  - `hide_single_value_filters` (bool): default true

## /tax

### `PUT /tax/eu-threshold`

Changes the EU's threshold of distance sales the shop measures against, alone: the tax settings then.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `threshold` (string): required; matches `/^\d{1,12}(\.\d{1,2})?$/`

### `GET /tax/groups`

- Who: shop managers

### `POST /tax/groups`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `tax_code` (string|null): matches `/^[A-Za-z0-9][A-Za-z0-9._:-]{0,39}$/`

### `GET /tax/groups/{id}`

- Who: shop managers

### `PUT /tax/groups/{id}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): required; not empty; at most 100 characters
  - `tax_code` (string|null): matches `/^[A-Za-z0-9][A-Za-z0-9._:-]{0,39}$/`

### `DELETE /tax/groups/{id}`

- Who: shop managers; needs a nonce (not with an API key)

### `POST /tax/groups/{id}/eu-standard-rates`

Fills a tax group with the standard VAT rate of every EU country it has none for: the group, as it is then.

- Who: shop managers; needs a nonce (not with an API key)

### `POST /tax/groups/{id}/rates`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `country` (string): required; matches `/^[A-Z]{2}$/`
  - `state` (string|null): required; matches `/^[A-Z0-9]{1,3}$/`
  - `percent` (string): required; matches `/^\d{1,3}(\.\d{1,4})?$/`
  - `name` (string): at most 64 characters
  - `priority` (int): 0 to 1000; default 0
  - `postcodes` (array): at most 500 items; each at most 21 characters
  - `compound` (bool): default false
  - `code` (string|null): matches `/^[A-Za-z0-9._-]{1,40}$/`
  - `cities` (array): at most 100 items; each at most 64 characters
  - `shipping` (bool): default true

### `GET /tax/groups/{id}/rates/csv`

A tax group's rates as CSV, in WooCommerce's columns: {csv}, for the browser to save as a file.

- Who: shop managers

### `POST /tax/groups/{id}/rates/csv`

Adds the rates of a CSV file (WooCommerce's columns) to a tax group, or puts them in place of its rates: the group, as it is then. Every row is checked first; one that cannot be is named and nothing is saved.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `csv` (string): required; not empty; at most 2000000 characters
  - `replace` (bool): default false

### `PUT /tax/groups/{id}/rates/{rate}`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `country` (string): required; matches `/^[A-Z]{2}$/`
  - `state` (string|null): required; matches `/^[A-Z0-9]{1,3}$/`
  - `percent` (string): required; matches `/^\d{1,3}(\.\d{1,4})?$/`
  - `name` (string): at most 64 characters
  - `priority` (int): 0 to 1000; default 0
  - `postcodes` (array): at most 500 items; each at most 21 characters
  - `compound` (bool): default false
  - `code` (string|null): matches `/^[A-Za-z0-9._-]{1,40}$/`
  - `cities` (array): at most 100 items; each at most 64 characters
  - `shipping` (bool): default true

### `DELETE /tax/groups/{id}/rates/{rate}`

- Who: shop managers; needs a nonce (not with an API key)

### `GET /tax/import-schemes`

The import schemes there are for low-value goods, and those the shop sells under.

- Who: shop managers

### `PUT /tax/import-schemes`

Replaces the import schemes the shop sells under: those as they are then.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `schemes` (array): at most 10 items

### `GET /tax/nexus-thresholds`

The economic nexus thresholds the shop goes by.

- Who: shop managers

### `PUT /tax/nexus-thresholds`

Saves the economic nexus thresholds: for every US state, and for those whose own differ.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `everywhere` (SaveNexusThreshold): required
  - `states` (list<SaveNexusThreshold>): at most 60 items

### `GET /tax/settings`

- Who: shop managers

### `PUT /tax/settings`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `basis` (string): required; one of shipping_address, billing_address, shop_address, eu_origin, eu_destination
  - `default_group_id` (int|null): required; more than 0
  - `prices_include_tax` (bool): default false
  - `reverse_charge` (bool): default false
  - `rounds_each_line` (bool): default false
  - `origin_regions` (array): at most 100 items
  - `provider` (string|null): matches `/^[a-z][a-z0-9_]{0,39}$/`
  - `when_provider_fails` (string): one of shop_rates, refuse; default 'shop_rates'
  - `accepts_certificates` (bool): default false
  - `shown_in_shop` (string): one of as_entered, with_tax, without_tax; default 'as_entered'
  - `shown_in_cart` (string): one of as_entered, with_tax, without_tax; default 'as_entered'
  - `price_suffix` (string): at most 80 characters; matches `/^[^<>\x00-\x1F]*$/`
  - `same_price_everywhere` (bool): default true
  - `shipping_taxed_as_items` (bool): default false
  - `eu_threshold` (string|null): matches `/^\d{1,12}(\.\d{1,2})?$/`

## /webhooks

### `GET /webhooks`

The webhooks, and the topics they can listen to.

- Who: shop managers

### `POST /webhooks`

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): not empty; at most 100 characters
  - `url` (string): not empty; an address (URL); at most 500 characters
  - `topics` (list<string>): at least 1 items
  - `active` (bool): default true

### `PUT /webhooks/{id}`

Changes a webhook, or deletes it with what it delivered.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): not empty; at most 100 characters
  - `url` (string): not empty; an address (URL); at most 500 characters
  - `topics` (list<string>): at least 1 items
  - `active` (bool): default true

### `DELETE /webhooks/{id}`

Changes a webhook, or deletes it with what it delivered.

- Who: shop managers; needs a nonce (not with an API key)
- Body (JSON):
  - `name` (string): not empty; at most 100 characters
  - `url` (string): not empty; an address (URL); at most 500 characters
  - `topics` (list<string>): at least 1 items
  - `active` (bool): default true

### `GET /webhooks/{id}/deliveries`

A webhook's test (POST …/ping: a webhook.ping event, sent by the background jobs), its new secret (POST …/secret), and the tries at delivering to it (GET …/deliveries, newest first).

- Who: shop managers

### `POST /webhooks/{id}/ping`

A webhook's test (POST …/ping: a webhook.ping event, sent by the background jobs), its new secret (POST …/secret), and the tries at delivering to it (GET …/deliveries, newest first).

- Who: shop managers; needs a nonce (not with an API key)

### `POST /webhooks/{id}/secret`

A webhook's test (POST …/ping: a webhook.ping event, sent by the background jobs), its new secret (POST …/secret), and the tries at delivering to it (GET …/deliveries, newest first).

- Who: shop managers; needs a nonce (not with an API key)

## Shapes

The objects fields above take.

### AddressData

- `first_name` (string): required; not empty; at most 200 characters
- `last_name` (string): required; not empty; at most 200 characters
- `street` (string): required; not empty; at most 200 characters
- `city` (string): required; not empty; at most 200 characters
- `postcode` (string): required; not empty; at most 20 characters
- `country` (string): required; not empty; matches `/^[A-Z]{2}$/`
- `phone` (string): required; not empty; at most 40 characters; matches `/^[0-9 +().\/-]+$/`
- `state` (string|null): matches `/^[A-Z0-9]{1,3}$/`

### ChangeStockLevel

- `kind` (string): required; one of product, variation, option
- `id` (int): required; more than 0
- `stockroom_id` (int): required; more than 0
- `quantity` (int|null): required; 0 or more

### EditedItem

- `product_id` (int): required; more than 0
- `quantity` (int): required; 1 to 9999
- `option_ids` (list<int>): each more than 0
- `unit_price` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
- `bundle_choices` (list<int>): at most 100 items; each more than 0
- `line` (int|null): 0 or more

### OfferedCurrencyView

- `code` (string): required; matches `/^[A-Z]{3}$/`
- `rate` (string): required; matches `/^\d{1,8}(\.\d{1,8})?$/`
- `rounding` (string): one of decimals, whole, ninety_nine; default 'decimals'

### PriceTierData

- `min_quantity` (int): required; 1 to 1000000
- `kind` (string): required; one of percent, amount_off, fixed_price
- `value` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`

### RefundLine

- `line` (int): required; 0 or more
- `quantity` (int): required; more than 0

### ReturnItem

- `line` (int): required; 0 or more
- `quantity` (int): required; more than 0

### SaveAttribute

- `name` (string): required; not empty; at most 100 characters
- `options` (list<SaveOption>): required; at least 1 items
- `required` (bool): default false
- `price_hidden` (bool): default false
- `id` (int|null): more than 0

### SaveBundle

- `items` (list<SaveBundleItem>): required; 1 to 50 items
- `pricing` (string): one of fixed, sum; default 'fixed'
- `discount` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`

### SaveBundleItem

- `product_id` (int): required; more than 0
- `quantity` (int): 1 to 9999; default 1
- `variation_ids` (list<int>): at most 100 items; each more than 0
- `optional` (bool): default false

### SaveDeliverySchedule

- `weekdays` (list<int>): required; 1 to 7 items
- `lead_days` (int): 0 to 60; default 1
- `cutoff` (string|null): matches `/^([01]\d|2[0-3]):[0-5]\d$/`
- `days_ahead` (int): 1 to 90; default 14
- `closed` (list<string>): at most 366 items
- `slots` (list<SaveDeliverySlot>): at most 24 items
- `required` (bool): default false

### SaveDeliverySlot

- `id` (string): required; matches `/^[a-z0-9][a-z0-9-]{0,39}$/`
- `from` (string): required; matches `/^([01]\d|2[0-3]):[0-5]\d$/`
- `to` (string): required; matches `/^([01]\d|2[0-3]):[0-5]\d$/`
- `limit` (int|null): more than 0

### SaveFieldChoice

- `label` (string): required; not empty; at most 100 characters
- `value` (string): at most 40 characters
- `price` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`

### SaveFieldCondition

- `field` (string): required; not empty
- `values` (list<string>)

### SaveFormField

- `label` (string): required; not empty; at most 100 characters
- `kind` (string): one of text, textarea, number, date, checkbox, choice, file; default 'text'
- `key` (string): at most 30 characters
- `required` (bool): default false
- `help` (string): at most 300 characters
- `placeholder` (string): at most 100 characters
- `longest` (int): 1 to 2000; default 200
- `choices` (list<SaveFieldChoice>): at most 50 items
- `display` (string): one of list, buttons; default 'list'
- `price` (string|null): matches `/^\d{1,11}(\.\d{1,4})?$/`
- `min` (string|null): matches `/^-?\d{1,11}(\.\d{1,4})?$/`
- `max` (string|null): matches `/^-?\d{1,11}(\.\d{1,4})?$/`
- `whole` (bool): default false
- `section` (string): one of billing, shipping, order; default 'order'
- `condition` (SaveFieldCondition|null)
- `types` (list<string>): each one of image/jpeg, image/png, image/webp, image/gif, application/pdf
- `largest` (int): 1 to 20; default 10

### SaveNexusThreshold

- `sales` (string|null): matches `/^\d{1,11}(\.\d{1,2})?$/`
- `orders` (int|null): more than 0
- `state` (string|null): matches `/^[A-Z]{2}$/`

### SaveOption

- `name` (string): required; not empty; at most 100 characters
- `adjustment` (string): matches `/^-?\d{1,11}(\.\d{1,4})?$/`; default '0'
- `id` (int|null): more than 0
- `adjustment_kind` (string): one of amount, percent; default 'amount'

### SaveParcel

- `number` (string): required; not empty; at most 64 characters
- `tracking_url` (string|null): an address (URL); at most 500 characters

### SavePickupLocation

- `id` (string): required; matches `/^[a-z0-9][a-z0-9-]{0,39}$/`
- `name` (string): required; not empty; at most 100 characters
- `address` (string): required; not empty; at most 255 characters
- `hours` (string): at most 255 characters
- `fee` (string): matches `/^\d{1,14}(\.\d{1,4})?$/`; default '0'

### SaveRange

- `min` (string): matches `/^\d{1,14}(\.\d{1,4})?$/`; default '0'
- `max` (string|null): matches `/^\d{1,14}(\.\d{1,4})?$/`

### SaveSale

- `price` (string): required; matches `/^\d{1,11}(\.\d{1,4})?$/`
- `from` (DateTimeImmutable|null)
- `until` (DateTimeImmutable|null)

### SaveShippingRate

- `name` (string): required; not empty; at most 100 characters
- `price` (string): required; matches `/^\d{1,14}(\.\d{1,4})?$/`
- `id` (int|null): more than 0
- `per_item` (bool): default false
- `regions` (list<string>)
- `weight` (SaveRange|null)
- `volume` (SaveRange|null)
- `length` (SaveRange|null)
- `width` (SaveRange|null)
- `height` (SaveRange|null)
- `subtotal` (SaveRange|null)
- `taxable` (bool): default true
- `tax_group_id` (int|null): more than 0
- `shipping_classes` (list<int>)
- `postcodes` (list<string>): each at most 30 characters
- `excluded_regions` (list<string>)
- `excluded_postcodes` (list<string>): each at most 30 characters
- `items` (SaveRange|null)

### SetStockLevel

- `stockroom_id` (int): required; more than 0
- `quantity` (int): required; 0 or more

### ShipItem

- `line` (int): required; 0 or more
- `quantity` (int): required; more than 0
