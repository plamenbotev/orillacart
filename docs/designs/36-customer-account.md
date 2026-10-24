# 36 · Customer account

Status: built (2026-10-20), PLAN item 93.

## Decided (2026-10-20)

1. Finding a guest's order: the visitor gives the order's number and email, and the link to its receipt goes to
   that email when they match; the page says the same either way and shows nothing of the order.
2. The newsletter: the shop keeps every sign-up (who, when, where, and the words they agreed to), shows it on the
   customer screen and exports it; add-ons' mailing lists get the sign-ups too, each turned on by the shop
   manager; customers leave in their account, and anyone from the link every shop email to them carries (143).

## Address book

- A customer's account keeps up to twenty addresses (`orillacart_addresses` user meta), each under an id, with the
  one checkout starts from for billing and the one for shipping; the first saved is both, and shipping falls
  back to billing. Removing one of them makes another the one to start from.
- Ordering saves the order's addresses, once each, as the ones to start from (unless the customer unticks
  "Save these addresses for next time"); a full book forgets the address it has had longest that is neither.
- The account's Addresses section lists them, with a form each to change it and to use it for billing or
  shipping, and one to add an address; the customer screen does the same for the shop.
- Checkout offers a customer with more than one a list above the billing and the shipping fields; choosing one
  fills the fields in, the states of its country first, and the summary follows.

## Finding an order

The account page asks a visitor for the order number and the email (`POST /receipts/lookup`, five an hour):
when the order of that number was placed with that email (in any case), the receipt link email goes to it,
as when an expired link asks for a new one.

## Ordering again

"Order these again" on the receipt, and so on the account's order page (`POST /receipts/{receipt}/reorder`),
puts each line in the browser's cart with its options, bundle choices and quantity, at today's prices, then
goes to the cart. What is no longer for sale, or not in stock enough, stays out, and the receipt names it.

## The customer screen

- Lifetime value: what the customer paid in the shop's currency (orders with a payment, not cancelled or failed)
  less what went back, over how many paid orders, the average order, and when they first and last ordered
  (`OrderRepository::spendByCustomers`, one query for a page of customers). The list shows what each spent.
- Notes (`customer_notes`): the shop's, with who wrote each and when, newest first; the customer never sees them.
  They go when the account is deleted, and the privacy tools export and erase them.
- The newsletter: subscribed since when, or not.

## Newsletter

- Customers → Newsletter: whether the shop offers it, the words of the box (each sign-up keeps the words it
  agreed to), the add-ons' mailing lists to send the sign-ups to, how many are subscribed, and the subscribers to
  download (`admin-post.php?action=orillacart_export_subscribers`).
- Checkout shows the box, unticked, to whoever is not subscribed; the account's Emails section subscribes and
  unsubscribes. Signing up again while subscribed keeps the first words; one who left and comes back agrees anew.
- `newsletter_subscribers` keeps each email once: names, words, source (checkout, account), when subscribed and
  when they left. The privacy tools export the sign-up and erase it.
- Mailing lists (`MailingList`, extension API version 6) hear of each sign-up, unsubscribe and erasure through a
  job (`newsletter.sync`, the mail lane) that tells the list how the email stands now; one that cannot be reached
  is asked again later. A list turned on gets everyone subscribed so far.

## Emails in the account

The account's Emails section also says whether checkout starts with the cart reminder's box ticked
(`orillacart_no_cart_reminders` user meta, item 91): turned off, the box starts unticked, and the customer is
reminded only if they tick it again. Emails about orders always come.
