# Discarded patrols and retention

A ranger can throw a patrol away in the field — a false start, a test run, a
device left recording in a vehicle. It still uploads, with a **required reason**,
and arrives here as `status: discarded`.

What the module then does with it:

- **It is kept and fully viewable** — track, observations and photographs. A
  record that vanished from every screen would be indistinguishable from one the
  sync lost.
- **It is counted in nothing.** Not the KPIs, not the coverage figures, not the
  department board, not the charts, not the station ranking, and its track is not
  drawn on any coverage map. A discard says the effort did not happen as
  recorded.
- **It reads quietly**: a subdued row with a `discarded` pill, the ranger's
  reason beside it and its removal date on the row.
- **It is deleted after `discard_retention_days`** (default 90), photographs and
  previews included:

```console
bin/console patrol:purge-discarded --dry-run   # name the sweep before trusting it
bin/console patrol:purge-discarded             # idempotent; run it from cron
```

Unless it is **held for review** — a web-side action on the patrol's detail page,
gated by `patrols.record`, which stops the retention clock indefinitely. Nothing
on the phone can raise a hold, clear one, or shorten the window. Releasing a hold
resumes the clock from the ORIGINAL discard: a hold pauses the deletion, it does
not grant a fresh lifetime.

Every action a ranger takes on a patrol — rename, patrol-type change, discard —
also arrives as an **append-only event** and is rendered on the detail page's
history card, newest first. The event is the story; the patrol row is the current
truth, and both are written together. See §9A of the field API contract.
