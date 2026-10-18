# Running index workers

Changes to products, stock and prices reach the storefront's search index through a queue. Out of the box,
WordPress empties it: right after a request that changed something, and every minute through WP-Cron. A busy
shop, or one with a big catalog, runs workers instead: long-running processes that take from the queue as soon as
something is in it. Run as many as you need; each takes different products (the queue claims with
`FOR UPDATE SKIP LOCKED`), so adding workers builds more at once.

While workers run (they tell the shop every 30 seconds), WP-Cron and the end of requests leave the queue to them.
`wp orillacart index status` and the Search card in the shop settings show how many are running.

## The command

```
wp orillacart index work --loop [--batch=100] [--sleep=3] [--max-jobs=N] [--max-time=SECONDS] [--memory=256]
```

- `--loop` keeps it running; it waits `--sleep` seconds when the queue is empty.
- It stops cleanly when it built `--max-jobs` products, ran `--max-time` seconds, used more than `--memory`
  megabytes, or was asked to restart; the supervisor then starts a fresh one. Restarting every hour or so keeps
  memory in check.
- On SIGTERM, SIGINT or SIGQUIT it finishes its batch and stops, where PHP has the `pcntl` extension. Without it,
  the signal stops it at once: the products it held are taken again by another worker after five minutes.
- `wp orillacart index restart` asks every worker to finish its batch and stop. Run it after deploying a new
  version, so the workers start again on the new code.

## Supervisor

```ini
[program:orillacart-index]
command=wp orillacart index work --loop --max-time=3600 --path=/var/www/html
user=www-data
numprocs=3
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=60
stdout_logfile=/var/log/orillacart-index.log
redirect_stderr=true
```

`numprocs` is how many workers run.

## systemd

```ini
# /etc/systemd/system/orillacart-index@.service
[Unit]
Description=OrillaCart index worker %i
After=network.target mysql.service

[Service]
User=www-data
ExecStart=/usr/local/bin/wp orillacart index work --loop --max-time=3600 --path=/var/www/html
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Start three with `systemctl enable --now orillacart-index@{1..3}`.
