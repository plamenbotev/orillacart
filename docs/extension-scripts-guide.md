# Writing extension scripts

A guide to the shop's script language, for a person or an agent writing an extension. The companion
`docs/extension-scripts.md` is the catalogue, generated from the code: every declaration, host function, filter
and test in one line each. This guide explains how the pieces fit, what each handler receives and must answer,
and how a script fails. Where the two disagree, the catalogue is right, since a test keeps it current; tell us.

Every example here compiles: `tests/Unit/Platform/ScriptGuideTest.php` extracts and checks them.

## 1. What an extension is

An extension is a package of one or more `.ocs` files, plus templates, kept in the site's database and edited on
Settings › Extensions or through the assistant's `extension_*` tools. Enabling it compiles the files together,
runs the static check and registers what the files declare on the same registry the shop's PHP add-ons use. From
then on the shop calls the extension's functions as it would call a PHP provider: at checkout for a quote, from an
order's screen for a label, on a REST request for a route.

An extension cannot reach PHP, files, the database or WordPress. Everything it does goes through host functions,
each of which names the permission it needs and counts against a budget. A manager grants the permissions when
enabling; the static check refuses a script that calls what its `needs` does not declare.

## 2. The shape of a file

```
# A comment runs to the end of the line.

extension "greeter" {
  name: "Greeter"
  version: "1.0.0"
  needs: { orders: read }
}

settings {
  greeting: text { label: "Greeting", required: true, help: "Shown before the name" }
}

fn greet(name) {
  return settings.get("greeting") ~ ", " ~ name
}
```

Three kinds of thing stand at the top level:

- **The `extension` block**, once per package: its id, name, version and `needs`.
- **Declarations** such as `settings`, `payment_gateway "id" { … }`, `route GET "/x" { … } fn(request) { … }`,
  `object "ticket" { … }`, `on order_placed fn(event) { … }`. Each says what the extension adds. The catalogue
  lists them all with their functions.
- **Top-level functions** `fn name(args) { … }`: the extension's own helpers, callable from every handler and from
  the console on the Extensions screen.

Inside a declaration's braces, values are **declarative**: a bare word is a kind or a choice, not a variable.
`kind: text`, `needs: { orders: read }`, `state: choice { of: ["open", "closed"] }`, `who: public`. A
declaration's own properties stand one per line without commas. In a map or list written as a value (`fields: { … }`,
`needs: { … }`, `of: [ … ]`) the end of a line separates entries as a comma does, so entries on their own lines
need no commas and entries on one line do. A `fn` inside a declaration is a handler the shop calls; its body is
ordinary code.

Identifiers are letters, digits and underscores, starting with a letter or underscore. The keywords `let fn if
elif else for in while return break continue try catch fail true false null and or not is` cannot name a
variable or parameter.

## 3. Statements

A statement ends at the end of its line. Blocks are braces. There are no semicolons.

```
fn statements(items, limit) {
  let total = money("0")               # declare; a name must be declared before it is assigned
  let count = 0
  for item in items {                  # over a list: the values
    if item.skip { continue }
    total = total + item.price
    count = count + 1
    if count >= limit { break }
  }
  for key, value in { a: 1, b: 2 } {   # over a map: key and value
    count = count + value
  }
  while count > 100 { count = count - 1 }
  if total > money("50") {
    return "big"
  } elif total > money("10") {
    return "medium"
  } else {
    return "small"
  }
}
```

- `let x = …` declares. Declaring the same name twice in one scope is an error; assigning an undeclared name is
  a finding at check time. A loop variable is declared by its loop.
- `x.y = …`, `x["k"] = …` and `x[0] = …` assign into a map or list a variable holds. Values are copied on
  assignment; a function that changes its parameter changes nothing outside.
- `return` with no value returns null. A function that runs off its end returns null.
- `break` and `continue` belong in a loop. `fail` is a statement and also a value: `x ?? fail refused("none")`
  (see §7).
- `assert test` and `assert test, "message"` fail with kind `assertion` when the test is false, naming the line.
  They are for an extension's own tests (§16) and for guarding what must hold.
- `fn(x) { … }` as an expression is a closure. It sees the variables around it at the time it is made.

```
fn closures(base) {
  let add = fn(x) { return x + base }
  let twice = fn(f, x) { return f(f(x)) }
  return [add(1), twice(add, 1), [1, 2]|map(add)]
}
```

## 4. Expressions

The expression grammar is Twig's, so templates and scripts read the same way.

| Form                                                        | Meaning                                                                         |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `a.b`, `a["b"]`, `a[0]`                                     | a member of a map, an item of a list, a character of a string; null when absent |
| `a ~ b`                                                     | text concatenation; numbers and money become text first                         |
| `a + b`, `a - b`, `a * b`, `a / b`, `a // b`, `a % b`, `-a` | arithmetic; `//` is whole division                                              |
| `a == b`, `a != b`, `a < b`, `<=`, `>`, `>=`                | comparison                                                                      |
| `a and b`, `a or b`, `not a`                                | logic, short-circuit                                                            |
| `a in b`, `a not in b`                                      | membership in a list or map (by value), or a substring of a string              |
| `a starts with b`, `a ends with b`                          | text prefix and suffix                                                          |
| `a ?? b`                                                    | `a` unless it is null                                                           |
| `a ? b : c`, `a ?: c`                                       | choice; the short form gives `a` when it is truthy                              |
| `a..b`                                                      | a list of whole numbers from `a` to `b`, at most 10 000                         |
| `a\|filter(args)`                                           | a filter (§5)                                                                   |
| `a is test`, `a is not test`                                | a test (§5)                                                                     |
| `f(args)`, `ns.function(args)`                              | a call: a closure or top-level function, or a host function                     |

Precedence, lowest first: `or`, `and`, comparisons and `in`/`starts with`/`ends with`, `..`, `+ -`, `~`, `* / // %`,
then `??` (which binds tighter than anything). So `"n=" ~ 1 + 2` is `"n=3"`, as in Twig.

### Values

| Kind    | Written                      | Notes                                                                     |
| ------- | ---------------------------- | ------------------------------------------------------------------------- |
| null    | `null`                       | also what a missing key or a function without `return` gives              |
| boolean | `true`, `false`              |                                                                           |
| number  | `3`, `2.5`                   | a string that looks like a number counts in arithmetic                    |
| money   | `money("19.90")`, `money(5)` | exact to four decimals; shop currency unless the host says otherwise      |
| string  | `"text"`, `'raw'`            | double quotes know `\n \t \r \" \\ \uXXXX`; single quotes know no escapes |
| list    | `[1, 2, 3]`                  | a trailing comma is allowed; over several lines, line ends separate items |
| map     | `{ a: 1, "b c": 2 }`         | keys are bare words or strings; order is kept                             |
| closure | `fn(x) { return x }`         |                                                                           |

Money arithmetic is exact: money plus money, money times a number, money divided by a number; money plus a
number is an error, and money compares only with money. `str(amount)` writes it with four decimals,
`amount|round(2)` rounds, `amount|number(2)` formats it for a person. A string from a settings form or an API
becomes money with `money(text)`.

**Truthiness.** null, false, zero, an empty string, an empty list or map and zero money are false; everything
else is true. `if x`, `x ?: y` and `x|default(y)` all use it. `x ?? y` tests only for null.

**Equality.** `==` compares numbers by value, money by amount, and everything else exactly. `"1" == 1` is false.

**Member access.** `a.b` on a map is `a["b"]`; on a list, `a[0]`; on a string, a character. A missing key gives
null, never an error, so `order.shipping_address.city` is null when there is no shipping address and
`order.shipping_address.city ?? order.billing.city` reads as intended.

## 5. Filters and tests

Filters take a value with `|` and sometimes arguments. The catalogue lists their names; this is what they do.

| Filter                                  | On              | Gives                                                           |
| --------------------------------------- | --------------- | --------------------------------------------------------------- |
| `abs`                                   | number, money   | without its sign                                                |
| `capitalize`, `lower`, `upper`, `title` | text            | the usual cases                                                 |
| `default(v)`                            | anything        | the value when truthy, else `v`                                 |
| `first`, `last`                         | list, text      | the first or last item or character; null for an empty list     |
| `join(sep)`                             | list            | the items as text with the separator                            |
| `json`                                  | anything        | as JSON text                                                    |
| `keys`, `values`                        | map             | its keys or values as a list                                    |
| `length`                                | list, map, text | how many; same as `len(x)`                                      |
| `map(fn)`                               | list, map       | each item through the closure                                   |
| `merge(other)`                          | list, map       | the two together; a list appends, a map overrides by key        |
| `number(decimals, point, thousands)`    | number, money   | formatted, `1234.5\|number(2, ",", ".")` → `1.234,50`           |
| `replace({ from: to })`                 | text            | each `from` replaced by its `to`                                |
| `reverse`                               | list, text      | backwards                                                       |
| `round(decimals)`                       | number, money   | rounded                                                         |
| `slice(start, length)`                  | list, text      | a part; `length` may be left out                                |
| `sort`, `sort(fn)`                      | list, map       | ascending, or by a closure returning negative, zero or positive |
| `split(sep)`                            | text            | a list; an empty separator splits into characters               |
| `trim`, `trim(chars)`                   | text            | without surrounding whitespace or the given characters          |

Tests: `x is defined` (not null), `empty` (not truthy), `null`, `number`, `string`, `money`, `list`, `map`,
`iterable` (list or map), `even`, `odd`. `is not` negates.

```
fn filters(order) {
  let names = order.lines|map(fn(l) { return l.name })|sort|join(", ")
  let big = order.lines|sort(fn(a, b) { return b.quantity - a.quantity })|first
  return {
    names: names,
    biggest: big.name ?? "none",
    total: order.total|number(2),
    paid: order.paid_at is defined,
    postcode: order.billing.postcode|trim|upper,
  }
}
```

## 6. Settings

```
settings {
  api_key: secret  { label: "API key", required: true }
  mode:    choice  { label: "Mode", of: { live: "Live", test: "Test" }, required: true }
  markup:  number  { label: "Markup %", help: "0 for none" }
  notify:  boolean { label: "Email the shop on every refund" }
  prefix:  text    { label: "Reference prefix" }
}
```

Kinds: `text`, `secret`, `boolean`, `number`, `choice` (with `of`, a list or a map of value → label). Each takes
`label`, `required` and `help`. The manager fills them on the Extensions screen; a secret is sealed in the
database and shown as dots. A script reads them with `settings.get("api_key")`. Provider handlers also receive
the whole map as their last parameter (`fn quote(shipment, settings)`), and a provider's own `settings { … }`
block inside its declaration adds settings that only it sees. A provider whose required settings are empty is
not offered at all, so a script need not check for them.

## 7. Failing, and what the shop does about it

`fail` ends the run with one of three outcomes. Which one you choose tells the shop how to react.

```
fn checked(answer) {
  if answer.status >= 500 { fail unavailable("The provider answered " ~ answer.status) }
  if answer.status == 422 { fail refused(answer.json.message, answer.json) }
  if answer.status != 200 { fail error("Unexpected answer", { status: answer.status }) }
  return answer.json
}
```

| Failure               | Meaning                                  | The shop                                                                   |
| --------------------- | ---------------------------------------- | -------------------------------------------------------------------------- |
| `unavailable(msg)`    | the provider cannot be reached right now | shows the method as unavailable or retries the job later; not counted      |
| `refused(msg, data?)` | a plain answer to whoever asked          | shows the message: a 400 on a route, a refusal in the console; not counted |
| `error(msg, data?)`   | a problem of the extension's             | logs it under the extension and counts it                                  |
| `fail "text"`         | same as `error`                          |                                                                            |
| `assert test, msg?`   | an `assertion`: the test did not hold    | a failed test in the editor; an error in a handler                          |

`fail` is also a value, so a missing thing can fail where it is read:

```
fn rate_of(zone) { return settings.get("rates")[zone] ?? fail refused("No rate for " ~ zone) }
```

Anything else that goes wrong is also an error: dividing by zero, adding money to a list, calling an unknown
member of `shop`, a budget used up. Ten errors in a row stop the extension: its providers disappear, its routes
answer 503, Home says so, and a manager enables it again after reading the problem on the Extensions screen.
One success resets the count.

`try … catch e { … }` catches a failure or an error. `e` is a map `{ kind, message, data }`, with `kind` one of
`unavailable`, `refused`, `error` or `assertion`. A budget used up cannot be caught.

```
fn safely(number) {
  try {
    return shop.orders.find(number).total
  } catch e {
    return e.kind == "error" ? money("0") : null
  }
}
```

**Budgets.** One run has 100 000 steps (every loop iteration and call is a step), 2 seconds, 16 MB of strings and
lists, 200 calls, 5 HTTP calls and 20 object queries. These are per handler call, not per request, and they are
ample for anything an extension should do inline. Work that needs more belongs in a job (§12).

## 8. Providers

A provider declaration names the contract and holds the handlers the shop calls. Each handler receives maps with
snake_case keys and money as money; it answers with a constructor from the matching namespace, so the shop gets
well-formed values. A handler the contract does not require may be left out.

### Payment gateways

```
payment_gateway "payfast" {
  name: "Pay with PayFast"
  settings { merchant: text { label: "Merchant id", required: true } }

  fn start(session, settings) {
    let answer = http.post("https://api.payfast.example/sessions", {
      json: { amount: str(session.amount), currency: session.currency, reference: session.order_number,
              return_url: session.return_url, cancel_url: session.cancel_url, notify_url: session.notification_url },
      headers: { Authorization: "Bearer " ~ settings.api_key },
    })
    if answer.status != 201 { fail unavailable("PayFast did not open a session") }
    return payment.redirect(answer.json.url, answer.json.id)
  }
  fn reference(back) { return back.query.session_id }
  fn returned(reference, back, settings) {
    if back.cancelled { return payment.cancelled(reference) }
    return payment.pending(reference)
  }
  fn notified(notification, settings) {
    let event = notification.json
    if event == null or event.type != "payment.succeeded" { return null }
    return payment.completed(event.session, event.transaction, money(event.amount), event.currency)
  }
  fn check(reference, transaction_id, settings) {
    let answer = http.get("https://api.payfast.example/sessions/" ~ reference, { headers: { Authorization: "Bearer " ~ settings.api_key } })
    if answer.json.status == "paid" { return payment.completed(reference, answer.json.transaction) }
    if answer.json.status == "failed" { return payment.failed(reference) }
    return payment.pending(reference)
  }
  fn refund(request, settings) {
    let answer = http.post("https://api.payfast.example/refunds", { json: { transaction: request.transaction_id, amount: str(request.amount) } })
    if answer.status != 200 { fail refused("PayFast refused the refund: " ~ answer.json.message) }
    return payment.refunded(request.reference, answer.json.id, request.amount)
  }
}
```

| Handler                                      | Receives                                                                                                                                                                                                                                                           | Answers                                                                        |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| `start(session, settings)`                   | `session`: payment_id, order_number, amount, currency, return_url, cancel_url, notification_url, items[{name, sku, quantity, unit_amount}], breakdown{item_total, tax, shipping, discount, handling}, ship_to{name, street, city, postcode, country, state}, email | `payment.redirect(url, reference)` or `payment.offline()`                      |
| `not_ready(settings, currency, decimals)`    | the settings, the shop's currency and its decimals                                                                                                                                                                                                                 | a message when the gateway cannot be offered, else null                        |
| `reference(back)`                            | `back`: query (the return URL's parameters), cancelled                                                                                                                                                                                                             | the payment's reference, or null when the return is not this gateway's         |
| `returned(reference, back, settings)`        | as above                                                                                                                                                                                                                                                           | a `payment.*` outcome                                                          |
| `notified(notification, settings)`           | `notification`: headers (lower-case names), body, json (when the body is JSON), query                                                                                                                                                                              | a `payment.*` outcome, or null to ignore it                                    |
| `check(reference, transaction_id, settings)` | what the shop knows                                                                                                                                                                                                                                                | a `payment.*` outcome                                                          |
| `refund(request, settings)`                  | `request`: id, order_number, reference, transaction_id, amount, currency, reason                                                                                                                                                                                   | `payment.refunded(…)`, `payment.refund_pending(…)` or `payment.refund_done(…)` |

Outcomes: `payment.completed(reference, transaction_id, amount?, currency?)`, `pending`, `failed`, `cancelled`,
`held`, `disputed`. A gateway with a `refund` handler is a refunding gateway; without it, the order screen offers
no refund button. `fail unavailable` from `start` tells the shopper to try another method.

### Shipping methods and couriers

```
shipping_method "post" {
  name: "The Post"
  fn quote(shipment, settings) {
    if shipment.country != "BG" { return [] }
    let price = shipment.weight > 2 ? money("8.90") : money("4.90")
    return [
      shipping.rate("standard", "Standard post", price, 3),
      shipping.rate("office", "To a post office", price - money("1"), 3, true),
    ]
  }
  fn pickup_points(query, settings) {
    return [shipping.pickup_point("1000-1", "Sofia Central", "2 General Gurko St", "8–20")]
  }
  fn create_shipment(request, settings) {
    return shipping.booked("BG" ~ request.reference, "https://track.example/" ~ request.reference)
  }
  fn track(number, settings) { return shipping.tracking("on_the_way", "Left the sorting centre") }
}
```

| Handler                              | Receives                                                                                                                                                                                                          | Answers                                                                                                                             |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `quote(shipment, settings)`          | `shipment`: country, state, postcode, items[{quantity, weight, length, width, height, name}], subtotal, currency, weight_unit, dimension_unit, weight (the total)                                                 | a list of `shipping.rate(code, name, price, delivery_days?, to_pickup_point?)`; `[]` when the method does not serve the destination |
| `is_ready(settings)`                 | the settings                                                                                                                                                                                                      | whether the method may be offered; without it, filled required settings suffice                                                     |
| `pickup_points(query, settings)`     | `query`: service, country, postcode, city                                                                                                                                                                         | a list of `shipping.pickup_point(id, name, address, hours?)`                                                                        |
| `create_shipment(request, settings)` | `request`: reference, service (the rate's code), recipient_name, phone, email, street, city, postcode, country, state, pickup_point_id, contents (a shipment as above), cash_on_delivery, declared_value, parcels | `shipping.booked(number, tracking_url?, parcels?)`                                                                                  |
| `label(number, settings)`            | the booked number                                                                                                                                                                                                 | `shipping.label(content, mime_type?)`, the file's bytes                                                                             |
| `cancel_shipment(number, settings)`  | the booked number                                                                                                                                                                                                 | anything; a failure says why not                                                                                                    |
| `track(number, settings)`            | the booked number                                                                                                                                                                                                 | `shipping.tracking(status, description?)`, status `booked`, `on_the_way`, `delivered` or `returned`                                 |

A method with `create_shipment` is a courier: the order screen gets Book, Label, Track and Cancel buttons, each
offered only when its handler exists. A plain method has `quote` alone.

### Rules, fees and adjusters

A parameter named like a namespace (`cart`, `fee`, `price`, `settings`) hides it inside that function; the check
says so when the function then calls the namespace. Name the cart `basket` when you need `cart.refuse`.

```
cart_rule "no_mixing" {
  fn check(basket) {
    let frozen = basket.lines|map(fn(l) { return l.sku starts with "FRZ-" })
    if true in frozen and false in frozen { return [cart.refuse("Frozen goods ship on their own; order them separately.")] }
    return []
  }
}

order_fee "cod" {
  fn fee(query) {
    if query.payment_method != "cod" { return null }
    return fee.of("Cash on delivery", money("2.50"), true)
  }
}

price_adjuster "trade" {
  fn adjust(query) {
    if query.customer_id == null or query.quantity < 10 { return null }
    return query.price * 0.9
  }
}

order_adjuster "loyalty" {
  fn adjust(query) {
    if query.customer_id == null { return [] }
    return [price.adjustment("Loyalty", money("-3"), null, "returning customer")]
  }
}
```

`query.fields` are the answers given so far to checkout's questions (the extension's own `checkout_field`s and the
shop's forms), by field id, as text: a ticked box is `"1"`, one left unticked is absent. The checkout prices the
order again whenever a box is ticked or a choice made (not at every text answer: the totals would redraw at each
field), so a `checkout_field "use_points" { kind: checkbox }` switches a discount on: `if (query.fields.use_points ?? "") == "1" { … }`. An order the shop edits brings its own answers.
What the adjuster took off is on the placed order's lines, under `adjustments` (§8, "The order map"), with the
adjuster's id as `source`: `on order_paid` can then settle what was spent.

| Declaration             | Receives                                                                                                | Answers                                                                                           |
| ----------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `cart_rule.check`       | the cart: customer_id, currency, lines[{id, product_id, variation_id, sku, name, quantity, unit_price}] | a list of `cart.refuse(message)` and `cart.refuse_line(line_id, message)`; empty or null for none |
| `order_fee.fee`         | `query`: items, subtotal, shipping, shipping_option, payment_method, country, customer_id, currency     | `fee.of(label, amount, taxable?, tax_group_id?, as_line?)` or null                                |
| `price_adjuster.adjust` | `query`: product_id, variation_id, quantity, customer_id, price, regular_price, currency                | the new price (money or text), or null to leave it                                                |
| `order_adjuster.adjust` | `query`: lines, currency, customer_id, email, order_number, fields                                      | a list of `price.adjustment(label, amount, line?, reason?)`, negative to take off                 |

### Checkout fields, order actions, columns and filters

```
checkout_field "vat_number" {
  label: "VAT number"
  kind: text
  section: billing
  required: false
  help: "For an invoice to a company"
  longest: 20
  fn check(answer, context) {
    if answer != "" and not (answer starts with "BG") { return "A Bulgarian VAT number starts with BG." }
    return true
  }
}

order_action "resend_label" {
  label: "Send the label again"
  fn available(order) { return order.shipping != null and order.status == "processing" }
  fn run(order) {
    jobs.push("resend", { order: order.number })
    return "The label is on its way."
  }
}

order_column "vat" {
  label: "VAT number"
  fn value(order) { return order.fields.vat_number ?? "" }
}

order_filter "needs_invoice" {
  label: "Needs an invoice"
  fn find(text) { return [] }
}
```

- `checkout_field`: `kind` is `text`, `textarea`, `checkbox` or `choice` (with `choices`), `section` is
  `billing`, `shipping` or `order`. `check(answer, context)` answers true or null to accept, or the message to
  show. The answer lands on the order under `fields.<id>`.
- `order_action`: `available(order)` decides whether the button shows; `run(order)` does the work and answers
  the message to show. `order` is the order summary (§8, "The order map").
- `order_column` and `product_column`: `value(row)` answers the text of the cell in exports.
- `order_filter` and `product_filter`: a filter of the admin lists. An extension puts records under it with
  `shop.orders.mark(number, "needs_invoice")` (needs `orders: write`) and takes them out with `unmark`; `find(text)`
  answers ids for a search under the filter, `[]` for none.

**The order map**, as `order_action`, `order_column` and `shop.orders.find` give it: number, status, placed_at,
paid_at, email, customer_id, billing{first_name, last_name, street, city, postcode, country, state, phone},
shipping_address (the same or null), lines[{product_id, variation_id, sku, name, quantity, unit_price, total,
tax, personalisation, type, add_on_type, adjustments[{kind, source, label, amount, scope, reason}]}], subtotal,
discount, shipping_total, tax, total, refunded, currency, payment, transaction_id, shipping, note, fields, fees.
A line's `adjustments` are the changes to its price after its unit price, as the order keeps them: `kind` is
`coupon`, `automatic`, `manual`, `add_on` or `adjuster`; `source` the coupon's code, the extension's or
adjuster's id, or the shop manager's user id; `amount` is money, below zero for a discount; `scope` is `line` for
the line's own or `order` for its share of a change to the whole order.

### Home, assistant tools, product blocks and mailing lists

```
home "tickets" {
  fn tiles() { return [home.tile("Open tickets", str(objects.ticket.count({ where: { state: "open" } })), null, "/wp-admin/admin.php?page=orillacart-extensions")] }
  fn needs_you() {
    let overdue = objects.ticket.count({ where: { state: "open", "due <": time.today() } })
    return overdue > 0 ? [home.line(overdue ~ " tickets are overdue", null, "warning")] : []
  }
}

assistant_tool "open_ticket" {
  title: "Open a support ticket"
  description: "Records a ticket for the shop's team"
  input: { subject: text, priority: number }
  read_only: false
  risk: writing
  fn run(arguments, call) {
    return objects.ticket.create({ subject: arguments.subject, priority: arguments.priority ?? 1, state: "open" })
  }
}

product_block "lead_time" {
  title: "Lead time"
  description: "How long the product takes to ship"
  fn render(product, settings) {
    let days = fields.get("product", product.id, "lead_time")
    return days == null ? "" : "<p class=\"lead-time\">Ships in " ~ html.escape(str(days)) ~ " days</p>"
  }
}

mailing_list "newsletter" {
  name: "Our newsletter"
  fn subscribe(subscriber) { http.post("https://mail.example/subscribe", { json: { email: subscriber.email, name: subscriber.name } }) }
  fn unsubscribe(email) { http.post("https://mail.example/unsubscribe", { json: { email: email } }) }
  fn forget(email) { http.post("https://mail.example/forget", { json: { email: email } }) }
}
```

- `home.tiles()` answers `home.tile(label, value, detail?, url?)` items; `needs_you()` answers
  `home.line(text, url?, tone?)` items with tone `attention`, `warning` or `info`.
- An `assistant_tool` becomes a tool of the shop's assistant and MCP server. `input` names the arguments and
  their kinds; `read_only` and `risk` (`reading`, `writing`, `deleting`, `opening`) tell the assistant how
  careful to be. `run(arguments, call)` gets the arguments and `call: { user_id, key_name }`; a `fail refused`
  is shown to the assistant as the tool's refusal.
- A `product_block` is a block the editor can place on product pages. `render(product, settings)` answers HTML,
  which the shop cleans (tags and attributes a post may hold) before printing. Escape text with `html.escape`.
- A `mailing_list` is a list the newsletter sign-up can subscribe to. `subscriber` has `email` and `name`.

## 9. Routes and pages

```
route GET "/tickets" { who: manager } fn(request) {
  return objects.ticket.query({ where: { state: request.query.state ?? "open" }, limit: 50 })
}

route POST "/tickets/{id}/close" { who: manager } fn(request) {
  let ticket = objects.ticket.update(num(request.params.id), { state: "closed" })
  if ticket == null { return respond.error(404, "No such ticket") }
  return respond.json(ticket)
}

route GET "/status" { who: public } fn(request) {
  return { open: objects.ticket.count({ where: { state: "open" } }) }
}

page "/support" { template: "support.html.twig" } fn(request) {
  return { tickets: objects.ticket.query({ where: { state: "open" }, limit: 10 }), who: request.user }
}
```

A route lives at `/wp-json/orillacart/v1/x/<extension>/<path>` and runs through the shop's own REST pipeline:
authorization, the REST nonce for browsers, API keys and OAuth for agents, a rate limit of 120 requests a minute,
and the change log. `who` is `public`, `account` (a logged-in customer) or `manager` (the default). A manager route is also one of the
assistant's tools, `x_<extension>_<method>_<path>` (`x_payfast_post_refunds`), unless it says `tool: false`; `tool:
"name"` names it, `title`, `description`, `input` (a JSON schema of the body) and `risk` describe it, and without
`input` the tool takes `params`, `query` and `body` maps. The handler gets the request map a REST call would give
it, as the manager who holds the key. Path
parameters are `{name}`.

The handler receives one map: `method`, `path`, `params` (from the path), `query`, `body` (the JSON body as a
map, or null), `headers` (lower-case names) and `user: { id, logged_in, manager }`. It answers with a map or
list, sent as JSON, or with one of `respond.json(data, status?)`, `respond.error(status, message)`,
`respond.redirect(url, status?)` and `respond.html(template, data?, status?)`. Because WordPress's REST server
answers JSON, `respond.html` travels as `{ "html": "…" }` for the caller to insert. A stopped extension's routes
answer 503 with a plain message. The same routes become the assistant's tools when declared as such (PLAN 331).

A `page` is rendered on the **Extension page** block: the editor places the block on a WordPress page and gives
it the path; the handler gets a request as above with the page's query string, and its map is the template's
data. The template is one of the extension's own (§10).

## 10. Templates and slots

An extension's templates are files under `templates/` in the package, addressed as
`x/<extension>/<file>`. They are Twig, sandboxed: the tags `if for set include extends block macro import from
with apply autoescape`, the storefront's filters and functions (`__`, `_x`, `_n`, `_nx`, `slot`, `date`, `range`, the price
helpers), no raw PHP objects. A template that compiles against the sandbox passes the static check.

```
slot "product_after_details" { template: "note.html.twig" } fn(context) {
  let days = fields.get("product", context.arguments[0].id, "lead_time")
  return { days: days }
}

template_data "storefront/product.html.twig" fn(context) {
  return { badge: context.product.stock > 10 ? "In stock" : "Few left" }
}
```

- `slot "<name>" { template } fn(context)`: the template prints where the storefront's template calls
  `slot('<name>')`. The handler gets `{ slot, arguments }`, where `arguments` is what the storefront passed, and
  whatever it answers is added to the template's context. The handler may be left out. The slots and what each
  passes:

  | Slot                                                                                                 | `context.arguments`                                                                                                      |
  | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
  | `product_before_form`, `product_after_form`, `product_after_details`                                 | `[product_id]`                                                                                                           |
  | `cart_before`, `cart_after_totals`, `cart_after`                                                     | `[]`                                                                                                                     |
  | `checkout_before_details`, `checkout_after_details`, `checkout_after_billing`, `checkout_after_shipping`, `checkout_before_place_order` | `[]`                                                                                                                     |
  | `account_after`                                                                                      | `[section, customer]`: the section shown (`orders`, `order`, `downloads`, `addresses`, `emails`, `wishlist`, `tax-exemptions`) and the customer viewing, `{ id, email, first_name, last_name }` |
  | `receipt_after`                                                                                      | `[order_number]`                                                                                                         |
  | `email_after_order`                                                                                  | `[order_number, for_shop]`: `for_shop` is true in the shop's own copy of the email                                       |

- **Calling your routes from a template.** `api_url('x/<extension>/<path>')` is the full URL of one of the
  extension's routes (§9), and the page's script offers `window.orillacart.api(method, path, body)`, the shop's
  own client: it sends the REST nonce, asks for a fresh one when the page's has expired, and answers
  `{ ok, status, data }` or `{ ok, status, code, message, violations }`. A customer's account page (the
  `account_after` slot) is not cached, so a `who: account` route works from it:

  ```twig
  <button type="button" data-use-points>{{ __('Use my points') }}</button>
  <script>
  document.querySelector('[data-use-points]').addEventListener('click', async () => {
    const result = await window.orillacart.api('POST', '/x/loyalty/points/use', { points: 100 });
    alert(result.ok ? result.data.message : result.message);
  });
  </script>
  ```

  `api_nonce()` is the nonce itself, for a call made another way (null for a visitor, whose page may be
  cached: let the client ask for one).
- `template_data "<template>" fn(context)`: adds to the context of one of the shop's own templates, under
  `ext.<extension>`; in the template, `{{ ext.greeter.badge }}`.
- With the permission `templates: override`, a file at `templates/storefront/product.html.twig` in the package
  replaces the shop's template of that name for the site. The theme's override still wins over the extension's.

## 11. Objects and fields

```
object "ticket" {
  fields: {
    subject:  text { longest: 120, required: true },
    body:     text,
    priority: number { default: 1 },
    state:    choice { of: ["open", "closed"], default: "open" },
    due:      date,
    tags:     list,
    order:    order,
    customer: customer,
  }
  index: [state, priority, due]
}

fields on product  { lead_time: number, note: text }
fields on order    { courier_ref: text }
fields on customer { tier: choice { of: ["bronze", "silver", "gold"] } }

fn open(subject, order) {
  return objects.ticket.create({ subject: subject, order: order, due: time.add(time.today(), "3 days") })
}
fn overdue() {
  return objects.ticket.query({ where: { state: "open", "due <": time.today() }, order_by: "due", limit: 100 })
}
fn next_page(after) { return objects.ticket.query({ order_by: "-id", limit: 50, after: after }) }
fn close(id) { return objects.ticket.update(id, { state: "closed" }) }
fn lead(product_id, days) { fields.set("product", product_id, "lead_time", days) }
```

An `object` declares a kind of record of the extension's own. Field kinds: `text` (option `longest`, 2000 by
default), `number`, `money`, `boolean`, `date` (`"2026-12-01"`), `datetime`, `choice` (with `of`), `list`,
`map`, and the references `product`, `order`, `customer` and `object` (another kind of the extension's). Each
takes `required` and `default`. A record always has `id`, `created_at` and `updated_at`.

`objects.<type>.create(data)` answers the record with its id; `find(id)`, `update(id, data)` and `delete(id)` do
what they say; `count({ where })` counts. `query({ where, order_by, limit, after })` filters on the fields in
`index`, the references and `id` only; a filter on anything else is refused, since it would scan. A `where` key
is a field name, or a field name, a space and one of `= != < <= > >=`: `{ "priority >=": 5 }`. `order_by` is an
indexed field, `id`, `created_at` or `updated_at`, with a leading `-` for descending. `limit` is 1 to 200, 50
by default; `after` is the last id of the previous page, for the next one. A reference field holds the id, and a
record whose `customer` is erased under a personal data request goes with the customer.

`fields on product|order|customer` adds fields to the shop's own records. `fields.get(kind, id, field)`,
`fields.set(kind, id, field, value)` (null clears) and `fields.of(kind, id)` read and write them; `kind` is
`"product"`, `"order"` or `"customer"` and `id` the product's id, the order's number or the customer's id. On
the order they are also in `order.fields` for templates and columns.

The shop shows and uses the fields without a line of script for it:

- **The screens.** The product editor, the order and the customer screen have an "Extensions" section with one
  block per extension, built from the declaration: a `text` is an input, a `choice` a select, a `boolean` a
  checkbox, a `date` a date picker, a `list` one item per line, a `map` JSON. A field's options name it:
  `lead_time: number { label: "Lead time", help: "Days to make it", required: true }`. One saying `readonly:
  true` is shown but written by the scripts only. Each block has its own Save; what is saved is checked as
  `fields.set` checks it.
- **The REST views.** `GET /products/{id}`, `/orders/{id}` and `/customers/{id}` carry the fields under
  `extensions.<extension>`, every declared field with its default where nothing is set. `GET
  /record-fields/products|orders|customers` lists what is declared; `PUT /products/{id}/extensions/<extension>`
  (and the same under orders and customers) saves `{ fields: { lead_time: 3 } }`. The assistant has the latter
  as the tool `save_record_fields`.
- **Templates.** Under `ext.<extension>.product` on the product page (`ext.lead_times.product.lead_time`),
  `ext.<extension>.order` in an order's emails and invoice, `ext.<extension>.customer` where a customer is shown.
- **Exports and imports.** A field on a product or an order is a column of the export, `x_<extension>_<field>`,
  named by its label, chosen on the export screen as any other; a product's column imports too, an empty cell
  clearing the field and `yes`/`no` for a boolean. `column: false` leaves a field out of the files.
- **Search facets.** A product field saying `filter: true` goes into the search index as if it were an attribute
  named by its label: a search filter picks "Material" as it picks "Size", on either engine, and shoppers filter
  the catalogue by it. A `list` gives one value per item, a `boolean` yes or no.

The records and field values are the extension's: uninstalling asks for an export first and then deletes them.
`GET /extensions/<id>/data` exports them at any time.

## 12. Background work

```
on order_placed fn(event) {
  let order = shop.orders.find(event.order)
  if order.total > money("500") { jobs.push("review", { order: order.number }) }
}

job "review" fn(payload) {
  shop.orders.mark(payload.order, "needs_review")
}

every "daily" fn() {
  for ticket in objects.ticket.query({ where: { state: "open", "due <": time.today() }, limit: 200 }) {
    objects.ticket.update(ticket.id, { tags: (ticket.tags ?? [])|merge(["overdue"]) })
  }
}
```

- `on <event> fn(event)` runs after one of the shop's events, as a job in the `extensions` lane, never in the
  request that caused it. `event` has `event` (the name), `arguments` (the hook's arguments, in this order) and
  the first argument under a guessable name: `order` for `order_*`, `product` for `product_*` and `stock_*`,
  `cart` for `cart_*`. The events and their arguments:

  | Event                                                            | `event.arguments`                                                                                                    |
  | ---------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
  | `order_placed`, `order_paid`, `order_changed`, `order_deleted`   | `[order_number]`                                                                                                     |
  | `order_status_changed`                                           | `[order_number, new_status, previous_status]`                                                                        |
  | `order_refunded`                                                 | `[order_number, amount, in_full]`: the amount as decimal text, and whether the order is now refunded in full         |
  | `order_note_added`                                               | `[order_number, note, for_customer]`                                                                                 |
  | `order_needs_attention`                                          | `[order_number, reason]`                                                                                             |
  | `product_changed`                                                | `[product_id]`                                                                                                       |
  | `stock_changed`                                                  | `[item, before, after]`: the item as `"product:12"` or `"variation:34"`, how many could be sold before and after (null: stock did not limit it) |
  | `cart_item_added`                                                | `[product_id, variation_id, quantity_added, customer_id]` (null for no variation, or a guest)                         |
  | `cart_item_changed`                                              | `[product_id, variation_id, new_quantity, previous_quantity, customer_id]`                                           |
  | `cart_item_removed`                                              | `[product_id, variation_id, quantity, customer_id]`                                                                  |
  | `catalog_items_removed`                                          | `[product_ids, variation_ids, option_ids]`                                                                           |
  | `price_rules_changed`                                            | `[scopes]`: before and after the change, each `{ groups, products, categories, brands }` (empty lists for all)       |
  | `tax_rates_changed`                                              | `[tax_group_id]`                                                                                                     |
  | `settings_changed`                                               | `[settings_now, settings_before]`                                                                                    |

  The reference (`GET /script-reference`, the assistant's `extensions_reference` resource) carries the same under
  `events` and `slots`, and what each handler receives and answers under `handlers`; this guide is `GET
  /script-guide` and the `extensions_guide` resource.
- `job "<name>" fn(payload)` runs when the extension calls `jobs.push("<name>", payload, at?)`, with `at` a
  moment or `"30 minutes"` from now. A job that fails `unavailable` is retried; an error counts.
- `every "hourly|twicedaily|daily|weekly" fn()` runs on the shop's schedule, once per period, by whichever
  worker comes first.

Handlers in the background get the same budgets. Long work is split: a schedule that pushes one job per record
beats one handler that walks them all.

## 13. Calling out

```
fn fetch_rate(settings) {
  let answer = http.get("https://api.rates.example/v1/today", {
    headers: { Authorization: "Bearer " ~ settings.api_key, Accept: "application/json" },
    timeout: 3,
  })
  if answer.status != 200 { fail unavailable("Rates answered " ~ answer.status) }
  return money(str(answer.json.eur))
}
```

`http.get|post|put|patch|delete(url, options)` calls a host the extension declared under `needs.http` and the
manager granted, over HTTPS only. Options: `json` (a value sent as JSON), `body` (text), `form` (a map sent as a
form), `headers`, `timeout` (seconds, at most 5). The answer is `{ status, headers, body, json }`, with `json`
the parsed body when it is JSON and the body cut at 1 MB. A host that does not answer is an `unavailable`
failure. Five HTTP calls per run.

`json.parse(text)` and `json.text(value)` convert; `time.now()`, `time.today()`, `time.parse(text)`,
`time.add(moment, "2 hours")`, `time.diff(from, to)` and `time.format(moment, "d.m.Y")` work in the shop's
timezone and write moments as ISO 8601 text.

## 14. Reading the shop

| Function                                                  | Needs                      | Gives                           |
| --------------------------------------------------------- | -------------------------- | ------------------------------- |
| `shop.orders.find(number)`                                | `orders: read`             | the order map (§8), or null     |
| `shop.orders.mark(number, filter)`, `unmark`, `is_marked` | `orders: write` / `read`   | the extension's order filters   |
| `shop.products.mark(id, filter)`, `unmark`, `is_marked`   | `products: write` / `read` | the extension's product filters |
| `settings.get(key)`                                       |                            | one of the extension's settings |
| `fields.*`, `objects.*`                                   |                            | the extension's own data (§11)  |

Writing to the shop's own records is deliberately narrow: an extension marks and annotates, it does not change
prices, stock or statuses behind the shop's back. What it needs to change it does through the contracts in §8,
where the shop stays in charge.

## 15. Words in other languages

On a site in several languages (Polylang or WPML), an extension's words are translated as the shop's own are:
in the shop's translations table, edited in the Translations section of the extension's screen, by an agent with
`save_package_translations`, or by WPML's translators, each extension being one of its string packages.

```
fn greeting(name) {
  return __("Hello %s")|replace({"%s": name})
}

fn reminder(count) {
  return _n("%d ticket waits", "%d tickets wait", count)|replace({"%d": count})
}

fn posted() {
  return _x("Post", "a verb") ~ " (" ~ language() ~ ")"
}
```

- Mark what a script shows people with `__("words")`, `_x("words", "context")` (the same words meaning
  something else elsewhere), `_n("one", "many", count)` and `_nx("one", "many", count, "context")`. The words
  must be written in the call: `__(name)` is refused by the check, since nothing could list such words for a
  translator. Put values in after translating, with `|replace`, so the translator sees the whole sentence.
- Templates mark theirs the same way: `{{ __("Thanks") }}`, `{{ _n("%d day", "%d days", days)|replace({"%d":
  days}) }}`. In an extension's templates, and its overrides of the shop's, these read the extension's
  translations first.
- What the declarations say to people needs nothing: the extension's `name`; a setting's `label`, `help` and
  choices; a provider's `name`; a column's, filter's and order action's `label`; a checkout field's `label`,
  `help` and choices; a field's `label` and `help`; a product block's `title` and `description`. An assistant
  tool's and a route's `title` and `description` are for a model and stay as written; a field's facet in the
  search keeps its declared label in every language.
- Which language: the one the shop shows words in, the page's, the email reader's, the manager's in the admin.
  `language()` gives it as a locale (`"de_DE"`). In the site's default language the words stand as written.
- A word the extension has no translation of takes the shop's own translation of the same words, so
  `__("Free shipping")` needs nothing in a language the shop is translated into.
- A plural has as many forms as the language: two in German, three in Polish, six in Arabic. The screen shows a
  field for each; a form left empty takes the nearest filled one before it.
- A word is its text: the same words in the same context are one word wherever they stand, and changing the
  English makes a new word to translate. An export carries the translations beside the files.

## 16. Working on an extension

- **The editor** highlights the language, completes host functions after a dot with their arity, summary and
  permission, filters after `|`, tests after `is`, keywords, declaration kinds at the start of a line and the names
  the file declares, and shows a function's summary on hover. As you type, the static check runs over the files
  as edited and underlines each finding where it is; the notice says whether the scripts compile as edited.
- **Check.** Saving a file runs the static check; the findings show beside the editor with line and column, and
  the extension cannot be enabled until they are gone. The check refuses unknown names and functions, wrong
  argument counts, unknown filters and tests, a host function the `needs` do not cover, a handler a contract
  requires that is missing, and templates that do not compile.
- **Console.** The Extensions screen runs any top-level function with arguments you type, under the granted
  permissions, and shows the result or the failure plus the budget used. Write small helpers (`api(...)`,
  `site_of(...)`) and try them there before wiring them into handlers.
- **Versions.** Every save, delete, import and restore keeps the files as they stood; the last thirty show on the
  screen with what changed, and any can be restored. An enabled extension keeps running what it was enabled with
  until it is enabled again, so a restore is safe to try.
- **Recent runs.** Each handler call and console run is logged with how it ended and the budget it used, kept
  as long as the shop keeps its history; the screen shows the latest.
- **Overrides.** A `templates/<name>` file that replaces one of the shop's templates says so, compares against
  the shop's own on request, and is marked stale once a release changes the original, so the change can be
  brought over.
- **Tests.** An extension declares its own tests and runs them from the Console's *Run tests* button, from VS
  Code, from `POST /extensions/<id>/test` or from `wp orillacart script test file.ocs`, enabled or not. See below.
- **Enable.** A dialog lists the permissions the `needs` ask for; granting them enables the extension. Changing
  `needs` later asks again.
- **Export and import.** An extension travels as a zip of its files, with an `extension.json` naming it and
  carrying its translations: Export downloads one, Import installs one (or the JSON file of before), as do
  `wp orillacart extension export <id>` and `wp orillacart extension install file.zip`. A zip you make yourself
  needs only the files, at its root or in one folder; the `extension` block names the extension, and files that
  are not an extension's (a LICENSE, `package.json`) are left out and named. A new extension comes in as a draft;
  one with the same id gets its files replaced and keeps a version of the change. Records and field values are
  exported separately from `/extensions/<id>/data`.
- **In VS Code.** The extension under `tools/vscode` (see its README) highlights `.ocs` files and runs a
  language server that proxies the site: the same check as you type, the same completion and hover, through an
  API key. Its pull and push commands move an extension's files between a folder named after the id and the
  site; enabling stays in the admin.
- **From the command line.** `wp orillacart script check file.ocs` and `wp orillacart script run file.ocs
function arg…` compile and run a file against a site without installing it; `wp orillacart script functions`
  lists the host functions the site offers.
- **Agents.** The assistant's `extension_*` tools install, write, check, run, enable and export extensions with
  the same rules, and the MCP resource `orillacart://extensions/reference` is the catalogue.

### Tests

```
fn quote(weight) {
  let answer = http.post("https://api.courier.test/quote", { json: { weight: weight, key: settings.get("api_key") } })
  if answer.status != 200 { fail unavailable("Courier answered " ~ answer.status) }
  return money(answer.json.price)
}

test "quotes a rate" {
  http: { "POST https://api.courier.test/quote": { status: 200, json: { price: "5.00" } } }
  settings: { api_key: "test-key" }
  now: "2026-11-07 10:00:00"
} fn() {
  let rate = quote(2)
  assert rate == money("5.00"), "the rate is " ~ rate
}

test "a provider that is down is unavailable" {
  http: { "https://api.courier.test/*": { status: 503, body: "maintenance" } }
} fn() {
  try { quote(1) } catch e { assert e.kind == "unavailable" }
}
```

A `test "name" { … } fn() { … }` runs its body with everything the `needs` ask for granted, whether or not the
extension is enabled, and reports the first failure with its message, file and line. Inside a test:

- `http.*` answer from the test's `http` map and nothing leaves the shop. A key is `"METHOD url"`, `"url"` for any
  method, or a prefix ending in `*`. The answer is `{ status, json | body, headers }`, status 200 when absent. A
  request the map does not answer fails the test and says what key to add.
- `time.*` stand at `now` when given, so dates in the output are stable.
- `settings.get` reads the test's `settings` over the extension's saved ones.
- `jobs.push` records the job instead of queuing it; the result lists what was pushed.
- `objects.*` and `fields.*` work on the shop's database inside a transaction rolled back after the test, so a test
  may create records and read them back and leaves none behind.
- Everything else, `shop.*` included, is the real shop, read-only by nature.

`assert test, message?` is the check; `fail`, an error or a budget used up also fail the test. The result lists the
requests made and the budget used. The editor runs the tests over the files as they are on screen, saved or not,
and the VS Code command sends the folder's files the same way.

## 17. A complete example

A courier integration, the smallest useful version. The provider's endpoints are illustrative.

```
extension "dpd" {
  name: "DPD"
  version: "0.1.0"
  needs: { http: ["api.dpd.example"] }
}

settings {
  username:  text   { label: "DPD user name", required: true }
  password:  secret { label: "DPD password", required: true }
  sender_id: text   { label: "Sender client id", required: true }
  markup:    number { label: "Added to every quote", help: "In the shop's currency; 0 for none" }
}

shipping_method "dpd" {
  name: "DPD"

  fn quote(shipment, settings) {
    if shipment.country != "BG" { return [] }
    let answer = api("calculate", {
      sender: { clientId: settings.sender_id },
      recipient: { privatePerson: true, addressLocation: { siteId: site_of(shipment.postcode) } },
      service: { serviceIds: [2005, 2002] },
      content: { parcelsCount: len(shipment.items), totalWeight: shipment.weight },
    }, settings)
    let rates = []
    for calc in answer.calculations {
      if calc.error { continue }
      let price = money(str(calc.price.total)) + money(str(settings.markup ?? 0))
      let to_office = calc.serviceId == 2002
      rates = rates|merge([shipping.rate(str(calc.serviceId), to_office ? "DPD to an office" : "DPD to the door", price, 1, to_office)])
    }
    return rates
  }

  fn pickup_points(query, settings) {
    let answer = api("location/office", { siteId: site_of(query.postcode) }, settings)
    return answer.offices|map(fn(o) { return shipping.pickup_point(str(o.id), o.name, o.address.fullAddressString, o.workingTimeFrom ~ "–" ~ o.workingTimeTo) })
  }

  fn create_shipment(request, settings) {
    let answer = api("shipment", {
      sender: { clientId: settings.sender_id },
      recipient: {
        privatePerson: true,
        clientName: request.recipient_name,
        phone1: { number: request.phone },
        email: request.email,
        pickupOfficeId: request.pickup_point_id,
        address: request.pickup_point_id ? null : { siteId: site_of(request.postcode), streetName: request.street },
      },
      service: {
        serviceId: num(request.service),
        additionalServices: request.cash_on_delivery ? { cod: { amount: str(request.cash_on_delivery) } } : null,
      },
      content: { parcelsCount: request.parcels|length, totalWeight: request.contents.weight, contents: "Order " ~ request.reference },
      ref1: request.reference,
    }, settings)
    if answer.error { fail refused(answer.error.message) }
    return shipping.booked(answer.id, "https://www.dpd.example/track?n=" ~ answer.id, answer.parcels|map(fn(p) { return p.id }))
  }

  fn label(number, settings) {
    let answer = http.post("https://api.dpd.example/v1/print", {
      json: { userName: settings.username, password: settings.password, paperSize: "A6", parcels: [{ parcel: { id: number } }] },
    })
    if answer.status != 200 { fail unavailable("DPD could not print the label") }
    return shipping.label(answer.body, "application/pdf")
  }

  fn cancel_shipment(number, settings) {
    api("shipment/cancel", { shipmentId: number, comment: "Cancelled by the shop" }, settings)
    return true
  }

  fn track(number, settings) {
    let answer = api("track", { parcels: [{ id: number }] }, settings)
    let last = answer.parcels|first
    if last == null { return shipping.tracking("booked") }
    let status = last.operations|last
    if status.operationCode == "-14" { return shipping.tracking("delivered", status.description) }
    if status.operationCode == "-10" { return shipping.tracking("returned", status.description) }
    return shipping.tracking("on_the_way", status.description)
  }
}

# One call to the courier's JSON API, with the credentials added and errors turned into the shop's failures.
fn api(path, body, settings) {
  let answer = http.post("https://api.dpd.example/v1/" ~ path, {
    json: body|merge({ userName: settings.username, password: settings.password }),
    timeout: 5,
  })
  if answer.status >= 500 { fail unavailable("DPD answered " ~ answer.status) }
  if answer.status != 200 { fail error("DPD refused the request", answer.json) }
  return answer.json
}

# The courier wants its own site id for a town; the shop knows the postcode.
fn site_of(postcode) {
  let sites = { "1": 68134, "4": 56784, "9": 10135, "8": 7079, "5": 72624 }
  let site = sites[postcode|slice(0, 1)]
  if site == null { fail refused("No DPD site for postcode " ~ postcode) }
  return site
}
```
