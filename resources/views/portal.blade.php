<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/png" href="/mediflow-logo.png" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="description" content="MediSphere is building connected healthcare technology for patients and healthcare facilities in Sokoto and beyond." />
    @if (app()->environment('production'))
      <meta name="robots" content="index, follow" />
    @else
      <meta name="robots" content="noindex, nofollow" />
    @endif
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="MediSphere" />
    <meta property="og:title" content="MediSphere | Connected Healthcare for Sokoto and Beyond" />
    <meta property="og:description" content="Where every medical record connects. MediSphere is building connected healthcare technology for patients and facilities in Sokoto and beyond." />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="MediSphere | Connected Healthcare for Sokoto and Beyond" />
    <meta name="twitter:description" content="MediSphere is building connected healthcare technology for patients and facilities in Sokoto and beyond." />
    <meta name="theme-color" content="#f5f8f2" />
    <title>MediSphere | Connected Healthcare for Sokoto and Beyond</title>
    <style>
      .portal-bootstrap { min-height: 100vh; display: grid; place-items: center; background: #f5f8f5; color: #204638; font-family: system-ui, sans-serif; }
      .portal-bootstrap-content { display: grid; justify-items: center; gap: 1.25rem; padding: 2rem; }
      .portal-bootstrap-spinner { display: block; width: 3rem; height: 3rem; box-sizing: border-box; border: 4px solid #d5e6d8; border-top-color: #337653; border-radius: 50%; animation: portal-bootstrap-spin .8s linear infinite; }
      @keyframes portal-bootstrap-spin { to { transform: rotate(360deg); } }
      @media (prefers-reduced-motion: reduce) { .portal-bootstrap-spinner { animation: none; } }
    </style>
    @viteReactRefresh
    @vite('resources/js/portal/main.tsx')
  </head>
  <body>
    <div
      id="root"
      data-public-environment="{{ app()->environment('production') ? 'production' : 'non-production' }}"
      data-portal-access="{{ app()->environment(['local', 'testing']) && config('platform.synthetic_management_enabled') === true ? 'synthetic' : 'disabled' }}"
    >
      <main class="portal-bootstrap">
        <div class="portal-bootstrap-content" role="status">
          <span class="portal-bootstrap-spinner" aria-hidden="true"></span>
          <strong>Loading MediSphere...</strong>
        </div>
        <noscript>JavaScript is required to use MediSphere.</noscript>
      </main>
    </div>
  </body>
</html>
