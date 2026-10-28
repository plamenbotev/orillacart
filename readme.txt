=== OrillaCart ===
Contributors: orillacart
Tags: ecommerce, shop, cart, checkout
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 2.0.0-dev
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

E-commerce for WordPress.

== Description ==

OrillaCart 2 is being rebuilt from scratch and is not ready for use yet.

== Installation ==

1. Install and activate the plugin. Activation makes four pages: Shop, Cart, Checkout and My account, each
   holding its block. Shop → Settings → Shop pages chooses other pages.
2. Set the currency and the shop's address (Shop → Settings), the ways to ship (Shop → Shipping) and to pay
   (Shop → Payments).
3. Add products under Products. Price, stock, options and downloads are in the editor's sidebar.

The Mini cart block puts a link to the cart, with the number of items, in a header.

== Frequently Asked Questions ==

= What happens to the database when the plugin is updated? =

The first request after the update brings the shop's tables up to date; one request does it while the others go
on. On a large shop, run `wp orillacart migrate` right after updating instead, so no visitor's request does the
work. If the update fails, the admin screens say why and offer to try again; `wp orillacart migrate` does too.
On a network, each shop upgrades on its own first request, and the network goes through the quiet ones in the
background; the network admin shows how far it is. When the shop is on for some sites only, activate the bundled
"OrillaCart Network" plugin for the network to follow it there. `wp orillacart migrate --network` upgrades every shop at once.

= Does the shop issue invoices? =

Yes. A paid order gets an invoice with the next number of its series, and money given back a credit note; the
numbers never skip one. Customers open them from their receipt, their account and their emails, and print them
or save them as PDF. Set the seller's details and the number prefixes under Shop → Settings → Invoices. Orders
that were paid are kept for the books, with their invoices: they cannot be deleted.

= How do I change how the shop looks? =

The storefront is rendered from Twig templates in the plugin's templates/ folder. Copy one to an orillacart/
folder in your theme, keeping its path (orillacart/storefront/cart.html.twig), and change it there.

= Does it work with page caching? =

Yes. Shop and product pages carry nothing personal and can be cached; the cart count in the Mini cart is
corrected in the browser. The cart, checkout and account pages tell caches not to keep them.

== Screenshots ==

1. The shop page: products by category with search, the shop's filters with how many products each leaves, and sorting.
2. A product with its options; the price follows the option chosen.
3. The cart, with the shipping, tax and total estimated before an address is known.
4. Shop → Orders: every order, filtered by status, day, payment, total and who placed it, and sorted by number or total.
5. Shop → Reports: sales against the days before or a year before, by product, category, coupon and customer.

== Changelog ==

= 2.0.0 =

Rebuilt from scratch on PHP 8.5, with no code or data kept from 1.x.

* Storefront blocks: shop, product filters, search, mini cart, cart, checkout and account, from Twig templates a theme can override.
* Catalog: variations, options with price changes, bundles, digital downloads, stock in several stockrooms, backorders, price rules per customer group, sales with the lowest earlier price shown.
* Checkout: guest or account orders, taxes by address with reverse charge for EU businesses, coupons, shipping rates by weight, size and postcode, pickup points, PayPal, bank transfer, cheque and cash on delivery.
* Orders: the shop's own orders, editing, partial and full refunds through PayPal, invoices and credit notes, emails in the customer's language.
* Search on the database or OpenSearch, background jobs with an overview screen, reports, Site Health checks, privacy tools, multisite.
* An extension API for payment gateways, shipping methods with live rates and labels, price adjusters, cart rules, checkout fields and order actions.
