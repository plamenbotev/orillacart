# Running workers

The shop does slow work in the background, as jobs in one queue: building the search index as products, stock and
prices change, sending order emails, and asking couriers where shipments are. Each kind of job waits in a lane:
`search` for the index, `mail` for emails, `shipping` for tracking.

Out of the box, WordPress runs the jobs: right after a request that pushed some, and every minute through WP-Cron,
for up to twenty seconds. A busy shop, or one with a big catalog, runs workers instead: long-running processes that
take jobs as soon as they are due. Run as many as you need; each takes different jobs (the queue claims with
`FOR UPDATE SKIP LOCKED`), so adding workers does more at once.

While workers serve a lane (each tells the shop every ten seconds), WP-Cron and the end of requests leave that lane
to them and run only the lanes no worker serves.

## The command

```
wp orillacart jobs work --loop [--queue=search,mail] [--batch=100] [--sleep=3] [--max-jobs=N] [--max-time=SECONDS] [--memory=256]
```

- `--loop` keeps it running; it waits `--sleep` seconds when nothing is due.
- `--queue` serves some lanes only, so a slow lane cannot hold up another: a worker or two for `mail`, more for
  `search` while the catalog is rebuilt. Without it, a worker serves every lane.
- It stops cleanly when it ran `--max-jobs` jobs, ran `--max-time` seconds, used more than `--memory` megabytes,
  or was asked to restart; the supervisor then starts a fresh one. Restarting every hour or so keeps memory in
  check.
- On SIGTERM, SIGINT or SIGQUIT it finishes its batch and stops, where PHP has the `pcntl` extension. Without it,
  the signal stops it at once: each job is settled as soon as it is done, so only the job it was on is taken
  again by another worker, once its hold runs out, and counts an attempt.
- A batch holds its jobs for as long as its handler says one of them needs (an email, a webhook delivery), and the
  hold is extended for the jobs still to run as each one finishes: a slow batch is not taken over by another
  worker while it goes on.
- `wp orillacart jobs restart` asks every worker to finish its batch and stop. Run it after deploying a new
  version, so the workers start again on the new code.

## Failures

A job that throws is tried again after a wait that grows with each attempt; its batch is run again job by job, so
only the bad job waits. An email the mail server refused is tried again after 1, 4, 16 and 64 minutes. After its
last attempt (five for the search index and emails) it is **failed**: kept with its error
for thirty days, until the shop retries or deletes it.

```
wp orillacart jobs status              # what waits in each lane, the workers, when WP-Cron last ran
wp orillacart jobs failed [--type=search.index]
wp orillacart jobs retry <id>... | --all
wp orillacart jobs forget <id>... | --all
```

Done jobs leave the queue; their counts per type and hour are kept for ninety days.

## Supervisor

```ini
[program:orillacart-jobs]
command=wp orillacart jobs work --loop --max-time=3600 --path=/var/www/html
user=www-data
numprocs=3
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=60
stdout_logfile=/var/log/orillacart-jobs.log
redirect_stderr=true
```

`numprocs` is how many workers run. For a lane of its own, add a second program with `--queue=mail`.

## systemd

```ini
# /etc/systemd/system/orillacart-jobs@.service
[Unit]
Description=OrillaCart worker %i
After=network.target mysql.service

[Service]
User=www-data
ExecStart=/usr/local/bin/wp orillacart jobs work --loop --max-time=3600 --path=/var/www/html
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Start three with `systemctl enable --now orillacart-jobs@{1..3}`.

## Multisite

Each site of a network has its own queue in its own tables. `--network` serves them all from one process: it takes a
batch from each site that runs the shop in turn, as that site (its tables, settings and language), and looks for
sites added or removed every minute. Run it from a site that has the shop: the main site when the shop is on for the
whole network. With `--loop`, each site sees the worker on its jobs screen, so its WP-Cron leaves the lanes to it,
and `wp orillacart jobs restart` on any site stops it; a cron line does not tell the sites, so their WP-Cron goes on
helping.

A busy site cannot keep the others waiting for long: each turn takes at most `--batch` jobs, then the next site's
turn comes; `--max-time`, `--max-jobs`, the memory limit and a stop signal are looked at after every turn. A site
whose turn fails (deleted meanwhile, a broken container) does not stop the worker: the failure is logged on that
site, which is left alone for a minute, and the others go on. A site whose tables are behind the update is left to
the network's walk (below) or its own first request, and served once it is up to date. The sites do share the process's time, though, so a slow batch on one (webhooks to a slow server, a big
import) delays the others until it ends. Give such a site workers of its own with `--url`, and leave it out of the
network's with `--except`.

```
# A cron line, every minute: what is due on every shop, then it stops. --max-time keeps a site that always has new
# jobs from keeping one run going while the next minute starts another.
* * * * * wp orillacart jobs work --network --max-time=55 --path=/var/www/html

# Or a worker, under Supervisor or systemd as above.
wp orillacart jobs work --network --loop --max-time=3600 --path=/var/www/html
```

A worker for the network is one process, so it runs with the plugins of the site it starts as: add-ons hook into
the shop's jobs (an ERP sync hears of each order, a gateway settles payments), and a site with other add-ons than
that one would have its jobs run without its own and with the other site's. So `--network` serves only the sites
whose plugins, apart from those on for the whole network, are the starting site's, and names the others on start
(and when they change) for workers of their own with `--url`. **Activate add-ons for the whole network** for one
worker to serve every shop; an add-on on for some shops only needs a worker per shop that has it.

Without `--network` a worker serves the site `--url` names. A busy shop with workers of its own, beside the
network's:

```
wp orillacart jobs work --url=https://busy.example.com --loop --max-time=3600 --path=/var/www/html
* * * * * wp orillacart jobs work --network --except=7 --max-time=55 --path=/var/www/html
```

After an update, each shop upgrades its own tables on its first request. The network also goes through the quiet
ones itself, not in any shop's queue, so no shop sees the others: short batches that go on from the last site done,
run by the main site's scheduled tasks and by a worker for the network between its rounds. A site that fails is tried
again a few times, then waits. The network admin shows how far it got, which shops could not be upgraded and why,
with a button to try them again. `wp orillacart migrate --network` upgrades every shop at once.

The main site runs this when the shop is on for the whole network. When the shop is on for some sites only, activate
the bundled **OrillaCart Network** plugin for the network: it runs only the network's side of updates on the main
site, not the shop. Without it, a worker for the network, WP-CLI and each shop's own visitors still bring them up to
date, and the shops' admin screens remind super admins of it.

## Under load

A request that pushed jobs runs up to twenty of them after its response, only on the queues it pushed to and only on
the queues no worker serves: a shopper's cheap push does not work the queue of imports or invoices, and with a worker
running, requests run none, and PHP's workers are free for shoppers. A shop under real traffic runs a
worker. It also runs a persistent object cache (Redis, Memcached): the storefront's listings then share their totals
and filter counts across requests for a minute, or until the search index changes (design 55); without one each
request counts for itself.

## Sharded networks

A network whose tables are spread over several databases or servers by a database drop-in (HyperDB, LudicrousDB)
works like any other: the shop keeps asking the drop-in, not `wp-config.php`, where this site's tables are. Doctrine
never opens a connection of its own inside WordPress. It asks the drop-in for the server that holds the site's shop
tables, as the drop-in would for a write to them, and keeps that handle for the site's kernel: a transaction stays
on one server, and a read after a write is not sent to a replica. When the drop-in closes the handle (idle too long
in a network worker), the next query asks again.

The dataset map is yours. A database per tenant is the simplest shape for a SaaS: map the prefix `wp_N_` to the
database of site N, and keep the global tables (users, sites, network meta) on the global dataset. With LudicrousDB:

```php
$wpdb->add_database(['host' => DB_HOST, 'user' => DB_USER, 'password' => DB_PASSWORD, 'name' => DB_NAME, 'dataset' => 'global']);
$wpdb->add_database(['host' => DB_HOST, 'user' => DB_USER, 'password' => DB_PASSWORD, 'name' => 'tenant_3', 'dataset' => 'tenant3']);
$wpdb->add_callback(static fn($query, $wpdb) => str_starts_with((string) $wpdb->table, 'wp_3_') ? 'tenant3' : 'global');
```

Two things to set on the drop-in's side. Reads: a `SELECT` WordPress runs inside a shop transaction may go to a
replica and not see what the transaction wrote, so send a tenant's reads to its primary (`send_reads_to_primaries`
in LudicrousDB) or give each dataset one server. Connections: a network worker opens one handle per tenant it
serves in a run; the drop-in's idle limit (`max_connections`) closes the quiet ones, and the shop asks again when it
comes back to that site.

What this costs: every query the shop runs itself, for products, carts, orders and reports, goes to the tenant's
primary. Replicas lighten only WordPress's own reads (posts, options, users). Size each tenant primary for the
shop's whole load. Offloading the shop's reads to a replica would need a separate read-only path, which is not built.

The dataset map may split a site's tables, with one rule: a site's shop tables (`wp_N_orillacart_*`), its `posts`
table and its term tables (`terms`, `term_taxonomy`, `term_relationships`) must resolve to one dataset. The shop
asks the drop-in once per site, for the orders table, and runs every query on that handle: a transaction spans
several shop tables, the schema's foreign keys hold between them, and the reports join order lines to the site's
terms and read product titles from its posts. The site's other WordPress tables (options, meta) and the global
tables may live anywhere, as the shop reaches them through the WordPress API over wpdb. A site's shop tables cannot
be extracted one by one to another database: the shop of a site moves whole.

The migrations' network walk and `jobs work --network` need nothing more: both switch sites and build the site's
kernel, and the kernel asks for its handle.
