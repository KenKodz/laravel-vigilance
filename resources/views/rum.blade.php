@if (config('vigilance.rum.enabled', false))
<script>window.__vigilanceRumEndpoint = @json(url(config('vigilance.path', 'vigilance').'/rum'));</script>
<script>
@verbatim
(function () {
    if (!('PerformanceObserver' in window) || typeof navigator.sendBeacon !== 'function') return;
    var endpoint = window.__vigilanceRumEndpoint;
    if (!endpoint) return;

    var metrics = {}, errors = [], sent = false;

    function observe(type, cb, opts) {
        try {
            var o = new PerformanceObserver(function (list) { list.getEntries().forEach(cb); });
            var config = { type: type, buffered: true };
            if (opts) { for (var k in opts) config[k] = opts[k]; }
            o.observe(config);
        } catch (e) {}
    }

    try {
        var nav = performance.getEntriesByType('navigation')[0];
        if (nav && nav.responseStart) metrics.ttfb = Math.round(nav.responseStart);
    } catch (e) {}

    observe('paint', function (e) { if (e.name === 'first-contentful-paint') metrics.fcp = Math.round(e.startTime); });
    observe('largest-contentful-paint', function (e) { metrics.lcp = Math.round(e.renderTime || e.loadTime || e.startTime); });

    var cls = 0;
    observe('layout-shift', function (e) { if (!e.hadRecentInput) { cls += e.value; metrics.cls = Math.round(cls * 1000); } });

    var inp = 0;
    observe('event', function (e) { if (e.interactionId) { inp = Math.max(inp, Math.round(e.duration)); metrics.inp = inp; } }, { durationThreshold: 40 });

    window.addEventListener('error', function (e) {
        if (errors.length < 5) errors.push({
            message: String(e.message || 'Error'),
            source: (e.filename || '') + ':' + (e.lineno || 0),
            stack: (e.error && e.error.stack) ? String(e.error.stack) : ''
        });
    });
    window.addEventListener('unhandledrejection', function (e) {
        var r = e.reason || {};
        if (errors.length < 5) errors.push({
            message: 'Unhandled rejection: ' + String((r && r.message) || r),
            stack: (r && r.stack) ? String(r.stack) : ''
        });
    });

    function flush() {
        if (sent) return;
        sent = true;
        var m = [];
        for (var k in metrics) { if (metrics.hasOwnProperty(k)) m.push({ name: k, value: metrics[k] }); }
        if (!m.length && !errors.length) return;
        try {
            navigator.sendBeacon(endpoint, new Blob(
                [JSON.stringify({ page: location.pathname || '/', metrics: m, errors: errors })],
                { type: 'application/json' }
            ));
        } catch (e) {}
    }

    addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
    addEventListener('pagehide', flush);
})();
@endverbatim
</script>
@endif
