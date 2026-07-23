import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import ui from '@nuxt/ui/vite'
import path from 'node:path'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/planner/app.js'],
            refresh: true,
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
                        root: 'rounded-none bg-elevated'
                    }
                },
                input: {
                    slots: {
                        base: 'rounded-none bg-neutral-950 placeholder:text-neutral-600 ring-neutral-800',
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
                          'rounded-none',
                          'cursor-pointer'
                      ]
                  }
                },
                textarea: {
                    slots: {
                        base: [
                            'rounded-none'
                        ]
                    }
                },
                selectMenu: {
                    slots: {
                        base: 'rounded-none bg-neutral-950 placeholder:text-neutral-600 border-neutral-800',
                        leadingIcon: 'text-neutral-600',
                    }
                },
                select: {
                    slots: {
                        base: 'rounded-none bg-neutral-950 placeholder:text-neutral-600 ring-neutral-800',
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
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    resolve: {
        alias: {
            'ziggy-js': path.resolve('vendor/tightenco/ziggy'),
        },
    },
});
