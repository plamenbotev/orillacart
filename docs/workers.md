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
  the signal stops it at once: the jobs it held are taken again by another worker after five minutes, and count as
  an attempt.
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
sites added or removed every minute. Each site sees the worker on its jobs screen, so its WP-Cron leaves the lanes to
it, and `wp orillacart jobs restart` on any site stops it. Run it from a site that has the shop: the main site when
the shop is on for the whole network.

```
# A cron line, every minute: what is due on every shop, then it stops.
* * * * * wp orillacart jobs work --network --path=/var/www/html

# Or a worker, under Supervisor or systemd as above.
wp orillacart jobs work --network --loop --max-time=3600 --path=/var/www/html
```

Without `--network` a worker serves the site `--url` names; several of those (`--url` each) give a busy shop
workers of its own beside a network worker.

After an update, each shop upgrades its own tables on its first request. The coordinating site (the main site, or
the first site with the shop when the main site has none) also queues an `upgrades` job for every other shop still
behind, so a quiet site is upgraded within minutes of the coordinator's next request, not on its own next visit. A
worker or a cron line for the coordinator does not wait for that request either. The network admin shows how many
sites are left, which could not be upgraded and why, with a button to try them again. `wp orillacart migrate
--network` upgrades every shop at once.
