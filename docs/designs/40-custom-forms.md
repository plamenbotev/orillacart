# 40 · Custom forms: personalised products and checkout questions

Status: building (2026-10-22), PLAN item 106.

One way to ask shoppers questions, used in two places: on the product page, where the answers belong to the item
bought (an engraving, a name on a shirt, a gift message), and at checkout, where they belong to the order (a
delivery note, a company's purchase order number). The shop builds the forms; add-ons keep their own checkout
fields (extension API), checked by the same code.

## Forms (src/Forms)

- Shop → Forms: a form has a name, where it is asked (**the product page** or **checkout**), on or off, its place
  among the others, and the products it is for: all, those in chosen categories (their subcategories too), or
  chosen products. A checkout form is asked when the cart holds a product it is for.
- Its fields, in order. A field has a label, a kind, whether it is required, help text, and a placeholder:
  - text (up to N characters), long text, number (a lowest and highest, whole or not), date, a tick box, a
    choice of labelled values shown as a list or as buttons, and a file (product forms only, see Uploads);
  - on the product page, a price per item: a field's own (charged when answered, as engraving +5 €) or each
    choice's (gift box +3 €). Amounts are in the shop's currency and are prices as the shop enters them (with or
    without tax, as its setting says);
  - at checkout, the part of the form it goes with: billing, shipping (orders that ship only) or order;
  - "only when": shown and asked only when another field of the form is answered, ticked, or has one of the
    chosen values. Without script the page shows every field and the shop ignores answers to ones not asked.
- A field's key is made from its label once and kept, unique in its form; answers are named by form and key
  (`f12_engraving`), so two forms may both ask "Name".
- `FieldAnswers` checks answers against fields: required, kind, length, range, choices and conditions. It gives
  each answer as kept (a choice by its label, a ticked box as "Yes", a date as YYYY-MM-DD) with its price, or
  `InvalidFields` next to each field. Checkout passes add-ons' fields through it too.

## Product page and cart

- The product page shows the forms for the product under its options, each price in its label as options show
  theirs; the cart shows the item's price with them. Add to cart sends them as `personalisation` (the REST API,
  and the form without script, which cannot send files).
- A cart line keeps the answers. The same product with other answers is another line. The line shows each
  "Label: answer" and its unit price includes their prices; quantity counts every item with those answers.
- Answers are checked again whenever the cart is priced, against the forms as they are then: a form changed so
  that the answers no longer hold (a required field added, a choice gone) marks the line "Its choices changed:
  remove it and add it again", and checkout does not take it.
- Prices by quantity, price rules and coupons see the item's price with its answers.

## Orders

- An order line keeps the answers as sold: label, answer and price, which do not follow later form changes. The
  receipt, the customer's account, the emails, the invoice and the order screen show them below the item's
  options, as do the add-ons (`OrderLine::$personalisation`, extension API 7).
- Reorder adds the line with its answers when they still hold, otherwise without the item, saying so.
- Anonymising an order blanks the answers (they may name people); the personal data export lists them.
- Checkout answers are the order's fields, as add-ons' are; they show where those do and the order screen edits them.

## Uploads

- A file field takes a picture or a document to print: the types the shop ticks (JPEG, PNG, WebP, GIF, PDF; never
  SVG, which can carry scripts) and a largest size (1 to 20 MB, 10 by default). Its price applies when a file is
  given.
- The product page sends the file as soon as it is chosen (`POST /form-uploads`, see Security), and the
  answer is the upload's token. The shop reads the file's type from its content, not its name, and keeps it among
  its private files (`PrivateFiles`, as downloads are), never in the media library.
- The token is a random secret only the visitor who sent the file holds (the shop keeps its hash, and the cart
  line keeps the hash, never the token), so an answer cannot name another's upload, nor one an order took. The line shows its file name; the order line keeps the name and the upload, which the order screen downloads and the
  customer's account names.
- Uploads are deleted by a daily job when no order took them: two days after they were sent, or a week after a
  cart last took them. A file in an older cart has to be sent again (the line says its choices changed). Anonymising or deleting an order deletes its
  files; the personal data export names them.

## Security

Everything a shopper sends is untrusted, the files most of all (asked for 2026-10-22: as tight as it goes).

Answers
- Only the fields the forms ask are read; other keys, arrays and objects are ignored or refused, and the answers
  of one item are at most 64 KB together. Prices come from the form, never from what the browser sends.
- Text must be valid UTF-8. Control characters are removed (a long text keeps its line breaks), and so are the
  characters that reverse the direction of text (U+202A–U+202E, U+2066–U+2069), which can make an answer read
  differently on the order screen. Lengths count characters after that.
- A number is a plain decimal within its range; a date is a real YYYY-MM-DD from 1900 to 2200; a choice is one of
  its values; a tick box is ticked or not.
- Answers are always shown escaped: Twig's autoescape on the storefront, in emails and invoices, React text on the
  admin screens, never as HTML. The orders export puts an apostrophe before an answer that would start a formula.
- Only shop managers (`manage_orillacart`) build forms; their labels, help and choices are escaped the same way.

Uploads
- `POST /form-uploads` takes one file under the field's limit and the shop's (whichever is smaller), with a REST
  nonce for a logged-in visitor, and a rate guard by address and cart (20 an hour). Each sender (an address; an
  IPv6 /64 network as one) sends up to 100 MB a day; a cart holds up to 10 files, for a week; and the shop stops
  taking uploads while those in no cart or order come to 500 MB, or all those no order took to 2 GB: the most
  disk they take, however many addresses send them. Someone who fills that with many addresses stops uploads
  for at most a week, until the carts' files expire. A shop that takes no orders takes no files.
- PHP's own upload checks first (`is_uploaded_file`, the upload error, the size), then the type read from the
  content (`finfo`), never the name or what the browser says; it has to be one the field takes.
- Pictures are checked for their size in pixels before they are opened (up to 25 megapixels, 10 000 a side, so
  a small file cannot unpack to gigabytes), then decoded and written again as a new file of the same type. That
  drops anything hidden after or inside the picture (scripts, a second file) and the metadata (a phone's GPS
  position). A picture that does not decode is refused. A photo up to 12 megapixels is turned upright first, as its camera noted (turning holds it twice in memory). Without GD, file fields take PDFs only, and
  the status screen says so.
- The check fails closed: a PDF the shop cannot read through is refused. Every stream is found (up to 10 000) and
  every stream filtered with FlateDecode alone is unpacked to its end, piece by piece within 64 MB together; a
  picture's own encodings (DCT, JPX, CCITT, JBIG2) are taken for pictures only; any other encoding (ASCIIHex,
  ASCII85, LZW, RunLength, chains), a stream that does not unpack or stops early (a false `endstream`), and a
  stream outside an object refuse the file. Every `/Filter` of a stream's dictionary counts, so a nested
  dictionary cannot hide one.
- A PDF must start as one and not ask to run anything. One naming scripts, launch or remote actions, attached
  files, actions on opening, forms or encryption (`/JavaScript`, `/JS`, `/Launch`, `/GoToR`, `/GoToE`,
  `/EmbeddedFile`, `/OpenAction`, `/AA`, `/RichMedia`, `/XFA`, `/AcroForm`, `/SubmitForm`, `/ImportData`,
  `/Encrypt`) is refused, also when the name is written with #xx escapes or sits in a compressed stream (each
  is unpacked and read, 64 MB at most together).
- The file is kept under a random name in the private store (outside the media library, which the web server
  must not serve), with the extension of its real type. The name the shopper gave is only shown: the path is
  dropped, control and direction characters removed, and it is cut to 100 characters.
- The shopper gets back a token (32 random bytes) and the name; the shop keeps only the token's SHA-256. There is
  no address a shopper or visitor can download a file from, so the shop cannot be used to host files.
- Only shop managers download a file, from the order screen: sent as an attachment, with the type it was stored
  as, `X-Content-Type-Options: nosniff` and `Content-Security-Policy: sandbox`, so a browser never runs it.
- Uploads no order took are deleted (see Uploads); an order deleted or anonymised deletes its files; temporary
  copies a request that failed on the way left behind are swept daily.

An independent review (2026-10-22) found the first PDF check skipped streams it could not unpack, the disk
budget could be held by one sender for a month, a large photo turned upright could run out of memory, and carts
named files by id; each is fixed as described above.

## Not in this round

Prices on checkout questions (an order fee), file questions at checkout, and adding personalised items when the
shop creates or edits an order by hand (those lines have no answers).
