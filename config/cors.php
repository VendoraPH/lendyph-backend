<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Published from the framework default purely to replace `allowed_origins:
| ['*']`. Everything else here is the stock shape.
|
| This is the ONE config in this repo whose value is not uniform across the
| five deployments: each tenant has its own frontend host, so the origin list
| is set per box via CORS_ALLOWED_ORIGINS. See .env.example.
|
| Worth being honest about the stakes: no tenant frontend actually depends on
| this. The browser talks to the same-origin `/api/proxy` path and Next.js
| rewrites it server-side (lendyph-frontend/next.config.ts), so requests reach
| Laravel with no Origin header at all and HandleCors never engages. Closing
| the wildcard is hygiene for anything else that might call the API from a
| browser — it is not load-bearing for the apps.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * array_filter, not a bare explode. php-cors treats a single-element list
     * as "one static origin" (CorsService::isSingleOriginAllowed), so an unset
     * CORS_ALLOWED_ORIGINS would yield [''] — count 1 — and stamp a literal
     * empty `Access-Control-Allow-Origin:` header on every response. Filtering
     * to an empty array instead falls to the dynamic branch, which emits no
     * header at all.
     *
     * Keep entries exact and fully qualified: php-cors silently promotes any
     * value containing '*' to a wildcard pattern, so a typo like
     * `https://*.lendyph.com` would widen the policy without erroring.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * Nothing reads a response header cross-origin. Blob downloads go through
     * the same-origin Next proxy and file links are window.open navigations,
     * not XHR.
     */
    'exposed_headers' => [],

    'max_age' => 86400,

    /*
     * Auth is a Bearer token from localStorage; the axios client never sets
     * withCredentials, and SANCTUM_STATEFUL_DOMAINS is unset on every box, so
     * no frontend origin is ever stateful. This must stay false — `true`
     * combined with a wildcard is illegal, and false is what allows the clean
     * single-origin header below.
     */
    'supports_credentials' => false,

];
