import { createApp, h } from 'vue';
import { ApiReference } from '@scalar/api-reference';
import '@scalar/api-reference/style.css';
import '@fontsource/geist/400.css';
import '@fontsource/geist/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import './style.css';

createApp({
    render: () => h(ApiReference, { configuration: {
        url: '/openapi.json',
        theme: 'none',
        withDefaultFonts: false,
        layout: 'modern',
        darkMode: true,
        persistAuth: false,
        telemetry: false,
        agent: { disabled: true },
        mcp: { disabled: true },
        showDeveloperTools: 'never',
        defaultHttpClient: { targetKey: 'shell', clientKey: 'curl' },
        hideClientButton: true,
        customCss: ':root { --scalar-font: Geist, sans-serif; --scalar-font-code: "IBM Plex Mono", monospace; }',
    } }),
}).mount('#api-reference');
