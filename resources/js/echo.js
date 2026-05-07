import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const key = import.meta.env.VITE_REVERB_APP_KEY

if (key) {
	window.Pusher = Pusher

	window.Echo = new Echo({
		broadcaster: 'reverb',
		key,
		wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
		wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
		wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
		forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
		enabledTransports: ['ws', 'wss'],
	})
}
