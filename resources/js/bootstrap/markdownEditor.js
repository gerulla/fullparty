import { config, XSSPlugin } from 'md-editor-v3'
import 'md-editor-v3/lib/style.css'

config({
    markdownItPlugins(plugins) {
        return [...plugins, { type: 'xss', plugin: XSSPlugin, options: {} }]
    },
})
