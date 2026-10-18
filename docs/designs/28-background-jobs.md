# 28 · Background jobs, with an overview for the shop

Status: proposed 2026-10-18, waiting for approval.

## Why

Item 21 built a queue for one kind of work, search indexing: claimed with `FOR UPDATE SKIP LOCKED`, run by
WP-Cron, the end of requests or long-running workers, with retries and a heartbeat. Other work belongs off the
shopper's request too, and today runs inside it:

- **Order emails**: every status change sends mail synchronously, so a slow SMTP server slows checkout, and a
  mail that fails is logged and lost.
- **Shipment tracking**: the courier is asked hourly from cron, with no retry or record.
- **Later**: webhooks to other systems, exports, image work, abandoned-cart reminders.

The shop also needs to see this work: what is waiting, what failed and why, whether workers run, and to retry or
drop what failed. Laravel's queue with Horizon is the model.

## The job system (Shared)

One queue for all background work, in `jobs`:

| column | |
|---|---|
| id | |
| queue | a lane: `default`, `search`, `mail`; workers can serve some lanes only |
| type | what to do: `search.index`, `mail.order` |
| unique_key | one pending job per key and type (a product to index): a second push only counts up its version |
| payload | JSON the handler reads: `{"order": 1042, "email": "order_received"}` |
| version, available_at, attempts, locked_until, locked_by | as the index queue has them |
| last_error, created_at, finished_at | |

- **Handlers**: one class per type implements `JobHandler` (`type()`, `handle(list<Job>)`, batching when it can,
  as indexing does), found by the container like scheduled tasks.
- **Pushing**: `Jobs::push(type, payload, uniqueKey?, at?, queue?)` from application services. Emails become a
  job pushed after the order's transaction commits, with three attempts and a growing delay between them.
- **Retries**: each type has its attempts and back-off; a job that used them up is **failed**, kept in
  `failed_jobs` with its payload and error until the shop retries or deletes it.
- **Done jobs** are deleted; per type and hour, a counter row (`job_stats`: done, failed, average run time) keeps
  the history for charts, trimmed after 30 days.
- **Runners**: the same as the index has now, for every lane: WP-Cron every minute, the end of a request that
  pushed something, and `wp orillacart jobs work --loop [--queue=search,mail] [--max-jobs …]` with
  `jobs restart`, `jobs status`, `jobs retry <id|all>`, `jobs forget <id|all>`.
- **Workers** record their heartbeat with what they serve, the job they are on, how many they did and their
  memory, so the overview lists them.

The search index moves onto it: its queue becomes the `search.index` type with the product as unique key, so the
index code keeps its behaviour and gains the overview for free.

## The overview (Shop → Background jobs)

A React screen, refreshed every few seconds:

- **Now**: per lane, how many wait, are due, are running, and the oldest waiting one's age; workers running, each
  with its lanes, current job, done count, memory and last sign of life; a warning when nothing runs the queue
  (no worker, and WP-Cron last ran more than five minutes ago).
- **Failed**: a list with type, what it was for (order #1042, product "Desk Lamp"), the error and when; retry or
  delete one or all; the payload on demand.
- **History**: done and failed per hour for the last day and per day for the last month, per type, and average
  run time.
- **Waiting later**: jobs scheduled ahead, such as sale boundaries.
- The search settings card keeps its short status and links here.

Permissions: shop managers (`manage_orillacart`).

## Not in scope

Priorities within a lane, job chains and batches of different types, and a public API for add-ons to push jobs
(it can be added to the extension API later).

## Questions for you

1. Should emails move onto jobs now, together with the indexing, or only the system and the overview first?
2. Keep done jobs only as hourly counts (small, fast), or keep each done job for a while (say 7 days) to look
   them up one by one, as Horizon's "completed jobs" does?
