<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PBB - HQ API v1</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        :root {
            --docs-bg: #07111e;
            --docs-surface: #0a1628;
            --docs-surface-soft: #0d1c31;
            --docs-border: rgba(120, 157, 212, 0.18);
            --docs-border-strong: rgba(120, 157, 212, 0.28);
            --docs-ink: #e8eef8;
            --docs-muted: #9fb1ca;
            --docs-accent: #3fa6ff;
            --docs-accent-soft: rgba(63, 166, 255, 0.14);
            --docs-success: #6fd685;
        }

        html {
            background:
                radial-gradient(circle at top left, rgba(34, 82, 150, 0.24), transparent 24%),
                linear-gradient(180deg, #091220 0%, #07111e 52%, #081427 100%);
        }

        body {
            margin: 0;
            background: transparent;
            color: var(--docs-ink);
            font-family: system-ui, sans-serif;
        }

        .site-shell {
            display: grid;
            grid-template-rows: 64px minmax(0, 1fr);
            min-height: 100vh;
        }

        .site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 14px;
            border-bottom: 1px solid var(--docs-border);
            background: rgba(18, 28, 47, 0.82);
            backdrop-filter: blur(16px);
            color: var(--docs-ink);
        }

        .site-brand {
            color: var(--docs-ink);
            text-decoration: none;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .site-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
            flex-wrap: wrap;
        }

        .site-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 14px;
            border: 1px solid var(--docs-border-strong);
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.92);
            color: var(--docs-ink);
            text-decoration: none;
            font-size: 14px;
            line-height: 1;
            transition: border-color 0.16s ease, transform 0.16s ease, background 0.16s ease;
        }

        .site-link:hover,
        .site-link:focus-visible {
            border-color: rgba(120, 157, 212, 0.42);
            transform: translateY(-1px);
        }

        .site-link.is-active {
            background: rgba(63, 166, 255, 0.12);
            border-color: rgba(63, 166, 255, 0.42);
            color: #eaf4ff;
        }

        .site-page {
            min-height: 0;
            padding: 22px 22px 28px;
        }

        #swagger-ui .wrapper {
            max-width: none;
            padding: 0;
        }

        #swagger-ui .scheme-container,
        #swagger-ui .information-container,
        #swagger-ui .opblock-tag-section,
        #swagger-ui .models,
        #swagger-ui .responses-inner,
        #swagger-ui .opblock .opblock-section-header,
        #swagger-ui .opblock .opblock-section-request-body,
        #swagger-ui .auth-wrapper,
        #swagger-ui .response-col_status,
        #swagger-ui .response-col_description,
        #swagger-ui .tab li {
            color: var(--docs-ink);
        }

        #swagger-ui .scheme-container {
            margin: 0 0 18px;
            padding: 14px 18px;
            background: var(--docs-surface);
            border: 1px solid var(--docs-border);
            border-radius: 18px;
            box-shadow: none;
        }

        #swagger-ui .information-container {
            margin: 0 0 18px;
            padding: 20px 22px;
            background: linear-gradient(180deg, rgba(9, 18, 33, 0.98), rgba(8, 17, 29, 0.96));
            border: 1px solid var(--docs-border);
            border-radius: 22px;
        }

        #swagger-ui .info {
            margin: 0;
        }

        #swagger-ui .info .title,
        #swagger-ui .info h1,
        #swagger-ui .info h2,
        #swagger-ui .info h3,
        #swagger-ui .info h4,
        #swagger-ui .info p,
        #swagger-ui .info li,
        #swagger-ui .opblock-tag,
        #swagger-ui .opblock-summary-description,
        #swagger-ui .parameter__name,
        #swagger-ui .parameter__type,
        #swagger-ui .parameter__deprecated,
        #swagger-ui .model-title,
        #swagger-ui .model,
        #swagger-ui .model-box,
        #swagger-ui table thead tr td,
        #swagger-ui table thead tr th,
        #swagger-ui table tbody tr td,
        #swagger-ui .response-col_links,
        #swagger-ui .responses-table .response-control-media-type__title,
        #swagger-ui .responses-table .responses-inner h4,
        #swagger-ui .responses-table .responses-inner h5,
        #swagger-ui .opblock-description-wrapper p,
        #swagger-ui .opblock-external-docs-wrapper p,
        #swagger-ui .opblock-title_normal p,
        #swagger-ui .markdown p,
        #swagger-ui .markdown li,
        #swagger-ui label,
        #swagger-ui small {
            color: var(--docs-ink);
        }

        #swagger-ui .info .description,
        #swagger-ui .opblock-description-wrapper,
        #swagger-ui .parameter__name.required span,
        #swagger-ui .parameter__extension,
        #swagger-ui .parameter__in,
        #swagger-ui .model-toggle:after,
        #swagger-ui .prop-type,
        #swagger-ui .prop-format,
        #swagger-ui .response-col_description__inner p,
        #swagger-ui .renderedMarkdown p,
        #swagger-ui .renderedMarkdown li {
            color: var(--docs-muted);
        }

        #swagger-ui .info a,
        #swagger-ui .opblock a,
        #swagger-ui .link,
        #swagger-ui .tab li button.tablinks {
            color: var(--docs-accent);
        }

        #swagger-ui .info .title small,
        #swagger-ui .info .title small pre {
            background: var(--docs-success);
            color: #092113;
            border-radius: 999px;
        }

        #swagger-ui .opblock-tag {
            margin: 0 0 12px;
            padding: 18px 18px 12px;
            border-bottom: 1px solid var(--docs-border);
            background: transparent;
        }

        #swagger-ui .opblock-tag-section {
            margin: 0 0 18px;
            background: rgba(8, 17, 29, 0.84);
            border: 1px solid var(--docs-border);
            border-radius: 20px;
            overflow: hidden;
        }

        #swagger-ui .opblock {
            margin: 0 14px 12px;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: none;
            border-width: 1px;
        }

        #swagger-ui .opblock.opblock-get {
            background: rgba(18, 39, 66, 0.66);
            border-color: rgba(89, 167, 255, 0.42);
        }

        #swagger-ui .opblock.opblock-get .opblock-summary {
            border-color: rgba(89, 167, 255, 0.24);
        }

        #swagger-ui .opblock .opblock-summary-method {
            border-radius: 10px;
            background: #4d9cf0;
        }

        #swagger-ui .opblock .opblock-summary-path,
        #swagger-ui .opblock .opblock-summary-description {
            color: var(--docs-ink);
        }

        #swagger-ui .opblock .opblock-summary {
            background: transparent;
        }

        #swagger-ui .opblock-body pre,
        #swagger-ui .highlight-code,
        #swagger-ui .microlight,
        #swagger-ui .model-box,
        #swagger-ui section.models,
        #swagger-ui .responses-inner,
        #swagger-ui .response-col_description__inner,
        #swagger-ui textarea,
        #swagger-ui input[type="text"],
        #swagger-ui input[type="password"],
        #swagger-ui input[type="search"],
        #swagger-ui select {
            background: var(--docs-surface-soft);
            color: var(--docs-ink);
            border-color: var(--docs-border);
        }

        #swagger-ui .models {
            border: 1px solid var(--docs-border);
            border-radius: 18px;
            background: rgba(8, 17, 29, 0.84);
            overflow: hidden;
        }

        #swagger-ui .models h4,
        #swagger-ui .models h5,
        #swagger-ui .models-control {
            color: var(--docs-ink);
        }

        #swagger-ui .model-container,
        #swagger-ui .model-box-control {
            background: transparent;
            border-color: var(--docs-border);
        }

        #swagger-ui .btn,
        #swagger-ui .button,
        #swagger-ui button {
            border-radius: 10px;
            border-color: var(--docs-border-strong);
            box-shadow: none;
        }

        #swagger-ui .btn.authorize {
            color: var(--docs-success);
            border-color: rgba(111, 214, 133, 0.48);
            background: rgba(111, 214, 133, 0.08);
        }

        #swagger-ui .btn.authorize:hover,
        #swagger-ui .btn.authorize:focus-visible,
        #swagger-ui .download-url-wrapper .download-url-button:hover,
        #swagger-ui .download-url-wrapper .download-url-button:focus-visible,
        #swagger-ui .btn.execute:hover,
        #swagger-ui .btn.execute:focus-visible {
            filter: brightness(1.08);
        }

        #swagger-ui .auth-wrapper .authorize svg,
        #swagger-ui .auth-wrapper .authorize path,
        #swagger-ui .auth-wrapper .authorize polygon,
        #swagger-ui .opblock-summary-control svg,
        #swagger-ui .opblock-summary-control path,
        #swagger-ui .opblock-summary-control polygon,
        #swagger-ui .authorization__btn svg,
        #swagger-ui .authorization__btn path,
        #swagger-ui .authorization__btn polygon,
        #swagger-ui .model-toggle svg,
        #swagger-ui .model-toggle path,
        #swagger-ui .model-toggle polygon,
        #swagger-ui .expand-operation svg,
        #swagger-ui .expand-operation path,
        #swagger-ui .expand-operation polygon,
        #swagger-ui .models-control svg,
        #swagger-ui .models-control path,
        #swagger-ui .models-control polygon,
        #swagger-ui .scheme-container svg,
        #swagger-ui .scheme-container path,
        #swagger-ui .scheme-container polygon {
            fill: currentColor !important;
            stroke: currentColor !important;
        }

        #swagger-ui .authorization__btn,
        #swagger-ui .opblock-summary-control,
        #swagger-ui .model-toggle,
        #swagger-ui .expand-operation,
        #swagger-ui .models-control {
            color: var(--docs-ink) !important;
        }

        #swagger-ui .authorization__btn,
        #swagger-ui .opblock-summary-control,
        #swagger-ui .model-toggle {
            background: transparent;
        }

        #swagger-ui .opblock-summary-control svg,
        #swagger-ui .authorization__btn svg,
        #swagger-ui .model-toggle svg {
            width: 18px;
            height: 18px;
            filter: brightness(1.85);
        }

        #swagger-ui .download-url-wrapper input[type="text"] {
            border-radius: 10px 0 0 10px;
        }

        #swagger-ui .download-url-wrapper .download-url-button,
        #swagger-ui .btn.execute {
            border-radius: 0 10px 10px 0;
            background: var(--docs-success);
            border-color: var(--docs-success);
            color: #092113;
            font-weight: 700;
        }

        #swagger-ui .servers > label,
        #swagger-ui .servers-title {
            color: var(--docs-muted);
        }

        #swagger-ui .dialog-ux {
            border: 1px solid var(--docs-border);
            border-radius: 18px;
            background: var(--docs-surface);
            box-shadow: 0 28px 70px rgba(0, 0, 0, 0.45);
            overflow: hidden;
            color: var(--docs-ink);
        }

        #swagger-ui .dialog-ux .modal-ux {
            background: transparent;
            border: 0;
        }

        #swagger-ui .dialog-ux .modal-ux-header,
        #swagger-ui .dialog-ux .modal-ux-content,
        #swagger-ui .dialog-ux .modal-ux {
            background: var(--docs-surface);
            color: var(--docs-ink);
        }

        #swagger-ui .dialog-ux .modal-ux-header {
            border-bottom: 1px solid var(--docs-border);
        }

        #swagger-ui .dialog-ux .modal-ux-header .close-modal {
            color: var(--docs-ink);
            opacity: 0.9;
        }

        #swagger-ui .dialog-ux .modal-ux-header h3,
        #swagger-ui .dialog-ux .modal-ux-content h4,
        #swagger-ui .dialog-ux .modal-ux-content p,
        #swagger-ui .dialog-ux .modal-ux-content label,
        #swagger-ui .dialog-ux .modal-ux-content code,
        #swagger-ui .dialog-ux .modal-ux-content .auth__description,
        #swagger-ui .dialog-ux .modal-ux-content .auth-container h4,
        #swagger-ui .dialog-ux .modal-ux-content .auth-container p,
        #swagger-ui .dialog-ux .modal-ux-content .auth-container .scope-def {
            color: var(--docs-ink);
        }

        #swagger-ui .dialog-ux .modal-ux-content small,
        #swagger-ui .dialog-ux .modal-ux-content .auth__description p,
        #swagger-ui .dialog-ux .modal-ux-content .auth-container .markdown p {
            color: var(--docs-muted);
        }

        #swagger-ui .dialog-ux .auth-container,
        #swagger-ui .dialog-ux .scope-def {
            border-color: var(--docs-border);
        }

        #swagger-ui .dialog-ux .auth-container input[type="text"],
        #swagger-ui .dialog-ux .auth-container input[type="password"],
        #swagger-ui .dialog-ux .auth-container input[type="search"],
        #swagger-ui .dialog-ux .auth-container textarea {
            width: 100%;
        }

        #swagger-ui .dialog-ux .modal-ux-content input[type="text"],
        #swagger-ui .dialog-ux .modal-ux-content input[type="password"] {
            background: var(--docs-surface-soft);
            color: var(--docs-ink);
            border: 1px solid var(--docs-border-strong);
            box-shadow: none;
        }

        #swagger-ui .dialog-ux .modal-ux-content input::placeholder,
        #swagger-ui .dialog-ux .modal-ux-content textarea::placeholder {
            color: rgba(159, 177, 202, 0.78);
        }

        #swagger-ui .dialog-ux .btn-done,
        #swagger-ui .dialog-ux .btn.modal-btn.auth {
            border-radius: 10px;
            box-shadow: none;
        }

        #swagger-ui .dialog-ux .btn.modal-btn.auth {
            color: var(--docs-success);
            border-color: rgba(111, 214, 133, 0.48);
            background: rgba(111, 214, 133, 0.08);
        }

        #swagger-ui .dialog-ux .btn-done {
            color: var(--docs-ink);
            border-color: var(--docs-border-strong);
            background: rgba(120, 157, 212, 0.08);
        }

        #swagger-ui .dialog-ux .btn.modal-btn.auth:hover,
        #swagger-ui .dialog-ux .btn.modal-btn.auth:focus-visible,
        #swagger-ui .dialog-ux .btn-done:hover,
        #swagger-ui .dialog-ux .btn-done:focus-visible {
            filter: brightness(1.08);
        }

        #swagger-ui .dialog-ux .close-modal {
            color: var(--docs-ink);
        }

        #swagger-ui .topbar,
        #swagger-ui .swagger-ui .topbar {
            display: none !important;
        }

        #swagger-ui svg {
            fill: currentColor;
        }
    </style>
</head>
<body>
    @php($user = auth()->user())
    <div class="site-shell">
        <header class="site-header">
            <a class="site-brand" href="/">PBB - HQ</a>
            <nav class="site-nav" aria-label="Main navigation">
                <a class="site-link" href="/">Home</a>
                <a class="site-link is-active" href="/api/v1" aria-current="page">API v1</a>
                @if ($user)
                    <a class="site-link" href="/geodata">GeoData</a>
                    <a class="site-link" href="/hubs">Hubs</a>
                    @if ($user->role === 'admin')
                        <a class="site-link" href="/users">Users</a>
                    @endif
                @endif
            </nav>
        </header>
        <main class="site-page">
            <div id="swagger-ui"></div>
        </main>
    </div>

    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: '/openapi/hubs.yaml',
            dom_id: '#swagger-ui',
            deepLinking: true,
            docExpansion: 'list',
            presets: [
                SwaggerUIBundle.presets.apis,
            ],
            layout: 'BaseLayout',
        });
    </script>
</body>
</html>
