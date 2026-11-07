# 54. Sharded networks

Status: built (2026-11-06). PLAN items 310–311.

A SaaS on multisite spreads the sites' tables over several databases or servers with a database drop-in: HyperDB,
or LudicrousDB. The shop already keeps every site's tables, queue and migrations apart (designs 44 and later), and
Doctrine never opens a connection of its own inside WordPress: it borrows wpdb's handle, so a shop transaction holds
what WordPress writes in it. With a drop-in that handle is the wrong one.

## The problem

The drop-ins hold one open handle per server, in `$wpdb->dbhs`, and pick a server per query by parsing the table
name out of the SQL and asking the dataset map. `$wpdb->dbh` is only a trail: the server the last query used. Reusing
it sends a tenant's shop queries to whatever shard WordPress last touched.

## The answer

Both drop-ins expose the picking step: `db_connect()` resolves the server for a statement, opens it if needed, and
returns its handle without running anything. `WordPressConnection` asks it with a write-shaped statement against one
of the site's shop tables (`UPDATE wp_N_orillacart_orders SET id = id WHERE 0`): the table name selects the dataset,
the write selects its primary. The handle is pinned for the connection's life, which is the site kernel's, so a
transaction stays on one server and a read after a write is not sent to a replica. When the drop-in closes the
handle (idle too long in a network worker) it is no longer in `dbhs`, and the next call asks again. Without a
drop-in, wpdb's one handle as before.

The two drop-ins differ in one detail: HyperDB's `db_connect($query)` takes the statement first, LudicrousDB keeps
core's `db_connect($allow_bail, $query)`. The parameter name decides.

## What this gives a SaaS

The dataset map is the operator's. A database per tenant is the shape to recommend: map the prefix `wp_N_` to the
database `tenant_N`, keep the global tables (users, sites, network meta) on the global dataset as WordPress needs,
and every tenant operation (dump, restore, move, delete, limits) is a database operation. Table sharding (sites 1 to
500 on server A) works the same way; only the map differs.

A WordPress write inside a shop transaction still lands in it: the site's own tables (posts, options of site N) map
to the same dataset, so wpdb picks the same primary handle. Reads are the one thing to set: a `SELECT` by wpdb may
go to a replica and not see what the transaction wrote, so a tenant's dataset should send reads to its primary
(`send_reads_to_primaries` in LudicrousDB, a single server per dataset in HyperDB) or run without replicas.

The shop itself never reads from a replica: asking for the primary, as for a write, is what keeps a transaction on
one server and a read after a write correct. So every query the shop runs, for products, carts, orders and reports,
loads the tenant's primary, and replicas lighten only WordPress's own reads. A tenant primary is sized for the shop's
whole load. Serving some of the shop's reads from a replica would be a separate read-only connection path, chosen
query by query; it is not built and nothing here needs it.

## One dataset per site's shop

The drop-in resolves a dataset per table, so a map may split a site's tables. The shop allows that above one line:
its own tables of a site, with the site's `posts` and term tables, resolve to one dataset. The connection asks once
per site, for the orders table, and runs every query on that handle; a split below that line would run queries on a
server that does not hold the table. The line is where it is because an order's transaction spans several shop
tables, the foreign keys hold between them, and the reports join order lines to the site's term tables and read
product titles from its posts. Options, meta and the global tables are reached through the WordPress API, which
routes each query itself, and may live anywhere.

## What stays as it is

The per-site kernel, the per-site migrations with the network's walk, and the per-site queues served by
`jobs work --network`. They switch sites and build a kernel; the kernel asks for its handle.

## Proof

Unit tests for the connection with the shapes of core wpdb and of both drop-ins (`tests/Fixtures/Database`). The
real proof ran on the `oc-ms` network on 2026-11-06 with LudicrousDB and site 3's 92 tables moved to the database
`wp_t3`, mapped by prefix in `db-config.php`. Site 2 kept its `global__r` handle on `wp` while site 3's Doctrine
handle was `tenant3__w` on `wp_t3`; site 3's storefront served its own products; its worker ran its jobs; the
network's migration walk checked site 3 on the tenant database; and a network worker started on site 1, with the
global handle as wpdb's last, served site 3's eight index jobs on `wp_t3`. The proving ground keeps that setup.
