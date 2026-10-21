import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import ui from '@nuxt/ui/vite'
import { readFileSync } from 'node:fs';
import path from 'node:path'

const scalarLicense = readFileSync(new URL('./public/licenses/scalar.txt', import.meta.url), 'utf8').trim();
const lanHost = process.env.FULLPARTY_LAN_PREVIEW === 'true' ? process.env.FULLPARTY_LAN_HOST : undefined;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/planner/app.js', 'resources/calculator/app.js', 'resources/api-docs/app.js'],
            refresh: true,
            detectTls: lanHost ? false : 'fullparty.test',
            ...(lanHost ? { hotFile: 'storage/framework/vite-lan.hot' } : {}),
        }),
        tailwindcss(),
        vue(),
        ui({
            router: 'inertia',
            theme: {
                colors: ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'neutral', 'brand']
            },
            ui: {
                colors: {
                    primary: 'brand',
                    brand: 'brand',
                    neutral: 'neutral'
                },
                card: {
                    slots: {
                        root: 'bg-elevated'
                    }
                },
                input: {
                    slots: {
                        base: ' bg-neutral-950 placeholder:text-neutral-600 ring-neutral-800',
                        leadingIcon: 'text-neutral-600'
                    },
                    // variants: {
                    //     variant: {
                    //         outline: 'border-red-500'
                    //     }
                    // }
                },
                button: {
                  slots: {
                      base: [
                          'rounded-sm',
                          'cursor-pointer'
                      ]
                  }
                },
                textarea: {
                    slots: {
                        base: [
                            ''
                        ]
                    }
                },
                selectMenu: {
                    slots: {
                        base: ' bg-neutral-950 placeholder:text-neutral-600 border-neutral-800',
                        leadingIcon: 'text-neutral-600',
                    }
                },
                select: {
                    slots: {
                        base: ' bg-neutral-950 placeholder:text-neutral-600 ring-neutral-800',
                        leadingIcon: 'text-neutral-600'
                    }
                },
                modal: {
                    variants: {
                        overlay: {
                            true: {
                                overlay: 'bg-black/50'
                            }
                        }
                    }
                }
            }
        })
    ],
    build: {
        rolldownOptions: {
            output: {
                // Keep the full notice in every chunk containing Scalar, including lazy-loaded chunks.
                postBanner: (chunk) => chunk.moduleIds.some((id) => id.replaceAll('\\', '/').includes('/node_modules/@scalar/'))
                    ? `/*! Scalar components license; also available at /licenses/scalar.txt\n\n${scalarLicense}\n*/`
                    : '',
            },
        },
    },
    ...(lanHost ? { cacheDir: 'node_modules/.vite-lan' } : {}),
    server: {
        ...(lanHost ? {
            host: lanHost,
            port: 5174,
            strictPort: true,
            https: false,
            origin: `http://${lanHost}:5174`,
            hmr: { host: lanHost, protocol: 'ws', clientPort: 5174 },
            proxy: {
                '/app/': {
                    target: process.env.FULLPARTY_LAN_REVERB_TARGET,
                    ws: true,
                    headers: { Origin: process.env.FULLPARTY_LAN_ORIGINAL_ORIGIN },
                },
            },
        } : {}),
        allowedHosts: [
            'fullparty.test',
            'plan.fullparty.test',
            'math.fullparty.test',
            'api.fullparty.test',
        ],
        cors: lanHost ? { origin: `http://${lanHost}:8000` } : true,
        watch: {
            ignored: [
                '**/storage/framework/views/**',
                '**/public/CalculatorData/**',
                'public/CalculatorData/**',
            ],
        },
    },
    optimizeDeps: {
        // Nuxt UI is excluded from prebundling. Include its editor imports alongside
        // our extensions so every plugin uses the same ProseMirror registry.
        include: [
            '@tiptap/core',
            '@tiptap/pm/model',
            '@tiptap/pm/state',
            '@tiptap/vue-3',
            '@tiptap/vue-3/menus',
            '@tiptap/starter-kit',
            '@tiptap/extension-code',
            '@tiptap/extension-horizontal-rule',
            '@tiptap/extension-image',
            '@tiptap/extension-mention',
            '@tiptap/extension-placeholder',
            '@tiptap/markdown',
            '@tiptap/extension-highlight',
            '@tiptap/extension-list',
            '@tiptap/extension-subscript',
            '@tiptap/extension-superscript',
            '@tiptap/extension-table',
            '@tiptap/extension-text-align',
            '@tiptap/extension-text-style',
        ],
    },
    resolve: {
        dedupe: [
            '@tiptap/core', '@tiptap/pm', '@tiptap/vue-3',
            'prosemirror-model', 'prosemirror-state', 'prosemirror-transform', 'prosemirror-view',
        ],
        alias: {
            'ziggy-js': path.resolve('vendor/tightenco/ziggy'),
        },
    },
});
