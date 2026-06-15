# Vigilance observability suite

Beyond jobs, the [worker supervisor](https://anousss007.github.io/laravel-vigilance/),
the [APM aggregates](apm.md) and [request tracing](tracing.md), Vigilance ships a
full front-to-back observability suite — error tracking, per-route and front-end
performance, SLOs with error budgets, alerting depth with incident tracking,
custom business metrics and a trace-correlated log explorer.

Every layer follows the same production-first posture as the rest of the package:
captured cheaply, flushed after the response, sampled and bounded, driver-agnostic
and wrapped so monitoring can never break the host app.

| Feature | Page | Default |
|---|---|---|
| [Issues — unified error tracking](#issues--unified-error-tracking) | `/vigilance/issues` | on |
| [Per-route performance](#per-route-performance) | `/vigilance/routes` | on |
| [Real User Monitoring (RUM)](#real-user-monitoring-rum) | `/vigilance/vitals` | off |
| [SLOs & error budgets](#slos--error-budgets) | `/vigilance/slos` | off (until you add one) |
| [Alerting depth & incidents](#alerting-depth--incidents) | `/vigilance/incidents` | on |
| [Custom business metrics](#custom-business-metrics) | `/vigilance/custom-metrics` | on |
| [Log explorer](#log-explorer) | `/vigilance/logs` | off |

---

## Issues — unified error tracking

Every reported exception is fingerprinted (Sentry-style) and collapsed into a
grouped **Issues** inbox with an occurrence count, a 7-day sparkline, a
stack-trace sample and request context. Unlike plain failure grouping, Issues
spans **every layer**:

| Source | Where it comes from |
|---|---|
| `request` | HTTP request exceptions (the exception handler `reportable` hook) |
| `reported` | `Vigilance::report($e)` / manually surfaced exceptions |
| `job` | queued-job failures (from the run capture) |
| `command` | console-command failures |
| `browser` | uncaught front-end errors posted by the [RUM](#real-user-monitoring-rum) beacon |

Open an issue to see the stacktrace, the request/user context and the
occurrence timeline. Each group can be **assigned**, **prioritised**,
**acknowledged**, **muted** for a window, **resolved** / reopened, and (for job
groups) the failed jobs **retried** in bulk.

```dotenv
# config/vigilance.php → 'issues'
VIGILANCE_ISSUES_SAMPLE=1.0          # keep 100% of grouped exceptions
VIGILANCE_ISSUES_CAPTURE_INPUT=false # off by default; redacted when on
```

Ignore noisy exception classes under `issues.except`. Failures you care about
are usually worth keeping in full, so only drop `sample_rate` below 1.0 under
extreme exception volume.

---

## Per-route performance

The `Requests` APM recorder samples **all** requests and rolls them up per route,
so the **Routes** page shows throughput, error rate, **Apdex** and exact latency
**percentiles (p50 / p95 / p99)** — computed from the raw request durations, not
estimated. This is distinct from the `SlowRequests` recorder, which only keeps
requests over its threshold.

```dotenv
VIGILANCE_APM_ROUTES=true            # the per-route recorder
VIGILANCE_APM_ROUTES_SAMPLE=1        # fraction of requests sampled
VIGILANCE_APM_APDEX_MS=300           # the Apdex satisfied-latency target (ms)
```

Apdex scores each request `satisfied` (≤ T), `tolerating` (≤ 4T) or `frustrated`,
giving you a single 0–1 satisfaction number per route alongside the percentiles.

---

## Real User Monitoring (RUM)

RUM collects **Core Web Vitals** (LCP, INP, CLS, FCP, TTFB) and uncaught
front-end errors from real visitors via a tiny beacon, surfaced on the **Web
Vitals** page with p75 ratings (good / needs-improvement / poor). It is **off by
default** — it adds a public ingest endpoint and a front-end script.

Enable it and drop the Blade directive into your layout `<head>`:

```dotenv
VIGILANCE_RUM=true
VIGILANCE_RUM_THROTTLE=120,1   # requests,minutes for the public ingest endpoint
VIGILANCE_RUM_JS_ERRORS=true   # also capture uncaught JS errors → Issues (browser)
```

```blade
<head>
    {{-- … --}}
    @vigilanceRum
</head>
```

The beacon uses `navigator.sendBeacon` and a `PerformanceObserver`, posts to a
public, **throttled and strictly-validated** endpoint (outside the dashboard auth
gate, because browsers post without it), and renders nothing when RUM is off.
Browser errors land in the [Issues](#issues--unified-error-tracking) inbox as
source `browser`.

---

## SLOs & error budgets

Track availability / latency objectives against an **error budget** with a
short-window **burn-rate** alert. Each SLO is evaluated against the global HTTP
request telemetry. The **SLOs** page shows target vs. current SLI, the remaining
error budget, the burn rate and a status (`healthy` / `at-risk` / `breaching`).
Off until you define one:

```php
// config/vigilance.php
'slos' => [
    'availability' => ['name' => 'API availability', 'sli' => 'success_rate', 'target' => 99.9, 'window_days' => 7],
    'page-speed'   => ['name' => 'Page speed',       'sli' => 'latency',      'target' => 95.0, 'window_days' => 7],
],
```

- `success_rate` — `1 − (5xx ÷ total)` over the window.
- `latency` — the Apdex score over the window.

The `slo_burn` alert rule fires when the 1-hour burn rate exceeds
`alerts.rules.slo_burn.burn_rate` (default 2×), so you hear about a budget you'll
exhaust *before* it's gone. Windows clamp to APM retention (≤ 7 days).

---

## Alerting depth & incidents

Vigilance evaluates rule-based alerts at `vigilance:snapshot` time — queue
backlog, failure-rate, exception spikes, slow-request rate, **overdue/failed
scheduled tasks** (a dead-man's-switch) and SLO burn rate — each throttled per
key. Beyond mail and Slack, alerts route to **Discord**, **Microsoft Teams** and
any number of **generic webhooks** (PagerDuty, Opsgenie, …), straight from
`.env`:

```dotenv
VIGILANCE_ALERT_EMAILS=ops@example.com,cto@example.com
VIGILANCE_SLACK_WEBHOOK=https://hooks.slack.com/services/…
VIGILANCE_DISCORD_WEBHOOK=https://discord.com/api/webhooks/…
VIGILANCE_TEAMS_WEBHOOK=https://outlook.office.com/webhook/…
VIGILANCE_ALERT_WEBHOOKS=https://events.pagerduty.com/…,https://api.opsgenie.com/…
```

Fired alerts are persisted as **incidents** — opened on first fire, refreshed on
recurrence, and **auto-resolved** once the alert stops recurring for
`alerts.incident_resolve_after` throttle windows. The **Incidents** page is a
timeline of open / resolved incidents with level, occurrence count and **MTTR**.

```php
// config/vigilance.php → 'alerts'
'incidents' => true,
'incident_resolve_after' => 3,
'rules' => [
    'scheduled_task_late' => ['enabled' => true],   // dead-man's-switch
    'slo_burn'            => ['enabled' => true, 'burn_rate' => 2.0],
    // … queue_long_wait, error_rate, exception_spike, slow_request_rate
],
```

---

## Custom business metrics

Record any business number — signups, orders, cart value, active users — with a
one-line API, and it shows up on the **Custom Metrics** page as a value with a
sparkline over a selectable window (1h / 24h / 7d), auto-discovered (no
registration):

```php
use Vigilance\Vigilance;

Vigilance::increment('signups');           // a counter (sum + count)
Vigilance::increment('orders', 3);
Vigilance::gauge('cart_value', $cart->total());   // a gauge (avg / peak / min)
Vigilance::gauge('active_users', $online);
```

Counters aggregate as a running total; gauges keep average, peak and min over the
window. Both are stored on the same bounded APM telemetry tables as everything
else, so retention and trimming are automatic.

---

## Log explorer

Capture application log records (`Log::info()`, `Log::error()`, …) into a
searchable explorer and **correlate each line to the trace that emitted it**, so
you can pivot from a slow or failed trace straight to its logs (and back). Off by
default — it writes a row per qualifying log line.

```dotenv
VIGILANCE_LOGS=true
VIGILANCE_LOGS_LEVEL=debug      # minimum severity captured (raise to warning/error)
VIGILANCE_LOGS_SAMPLE=1.0       # fraction of qualifying lines kept
VIGILANCE_LOGS_RETENTION="72 hours"
```

Like tracing, it is engineered to stay cheap: records are buffered in memory and
flushed in **one batched insert after the response is sent** (zero request
latency), secrets in context are redacted by key name, and the buffer is dropped
on Octane request reset so nothing leaks across requests. The **Logs** page
filters by minimum level, channel and message text; a trace's detail page shows
the logs emitted inside it, and every log row links back to its trace.

`vigilance:prune` trims the log table on the same short window as traces.
