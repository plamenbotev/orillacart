# 29 · Reports

Status: built (2026-10-18), asked for as "reports and graphs in the admin so an admin can visualize the
performance and do some analysis … use the best approach".

## Approach

Reports never read the orders. Each order is projected into fact tables by a background job (`reports.order`,
lane `reports`) whenever it is placed, paid, edited, refunded or deleted; the job writes the order's rows again
from scratch, so the facts never drift. Queries read one fact table each through a `(counted, currency, day)`
index, so a report over a year of a busy shop stays quick, and placing orders never waits for reporting.

- `report_orders`: one row per order: its day on the shop's calendar (site time zone), status, whether it counts
  (processing, shipped, completed, refunded), customer key (account, or guest email), country, payment, shipping,
  items, gross, discount, shipping, tax and total.
- `report_order_lines`: product, variation, quantity and net (line total less its discount).
- `report_order_coupons`: code (an automatic discount by its description) and amount.
- `report_refunds`: money given back, on the day it went back, not the order's day.

`wp orillacart reports rebuild` (or "Count every order again" on the screen) walks every order a thousand at a time
through the job queue; the migration queues one for the orders placed before the reports. A rebuild is needed after
changing the site's time zone.

## Figures

Net sales = gross − discounts − refunds. Total sales = what customers paid − refunds. Average order = what customers
paid ÷ orders. A new customer's order is their first paid one (by account, or by email for guests); the query asks
the `(customer_key, placed_at)` index. Figures are in the shop's currency; orders paid in others are left out and
named. Categories are the products' current ones; a product in two categories counts in both.

## Screen (Shop → Reports)

Period presets and chosen days, compared with the days before or the same days a year before, grouped by day, week
or month (by default what suits the period's length). Tabs: Overview (figures with change, a chart of net sales,
orders or total sales against the compared period, and shares by payment method, country and shipping), Products
(by net sales or items sold), Categories, Coupons, Customers (new and returning over time, who spent the most). Every
report downloads as CSV, and the address keeps the report and period, so it can be bookmarked or sent on. Charts
use Recharts.
