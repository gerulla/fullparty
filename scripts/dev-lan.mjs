import { existsSync } from 'node:fs';
import { isIPv4 } from 'node:net';
import { networkInterfaces } from 'node:os';
import { fileURLToPath } from 'node:url';
import concurrently from 'concurrently';
import { loadEnv } from 'vite';

process.chdir(fileURLToPath(new URL('../', import.meta.url)));

const env = loadEnv('development', process.cwd(), '');
if (env.APP_ENV !== 'local') {
    throw new Error('LAN preview is only available with APP_ENV=local.');
}
if (existsSync(env.APP_CONFIG_CACHE || 'bootstrap/cache/config.php')) {
    throw new Error('Run php artisan config:clear locally before starting the LAN preview.');
}

const addresses = [...new Set(Object.values(networkInterfaces()).flat()
    .filter((entry) => entry && !entry.internal && entry.family === 'IPv4')
    .map((entry) => entry.address)
    .filter((address) => /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(address)))];
const host = process.argv[2] || (addresses.length === 1 ? addresses[0] : undefined);

if (!host || !isIPv4(host) || !addresses.includes(host) || process.argv.length > 3) {
    throw new Error(`Choose this computer's private LAN address: npm run dev:lan -- <IP>\nAvailable: ${addresses.join(', ') || 'none'}`);
}

const appUrl = `http://${host}:8000`;
const previewEnv = {
    ...process.env,
    FULLPARTY_LAN_PREVIEW: 'true',
    FULLPARTY_LAN_HOST: host,
    FULLPARTY_LAN_ORIGINAL_ORIGIN: new URL(env.APP_URL).origin,
    FULLPARTY_LAN_REVERB_TARGET: `${env.REVERB_SCHEME || 'http'}://${env.REVERB_HOST || '127.0.0.1'}:${env.REVERB_PORT || '8080'}`,
    APP_URL: appUrl,
    ASSET_URL: '',
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    VITE_REVERB_HOST: host,
    VITE_REVERB_PORT: '5174',
    VITE_REVERB_SCHEME: 'http',
};

console.log(`\nFullParty LAN preview: ${appUrl}/en/home`);
console.log('Use the same trusted network on your phone. Sign in separately with email/password.');
console.log('Social login callbacks still use your configured Herd URLs.');
console.log('Keep the normal Herd queue/Reverb stack running. Ctrl+C stops the LAN preview.\n');

const { result } = concurrently([
    {
        name: 'lan-web',
        command: `php artisan serve --host=${host} --port=8000 --no-reload`,
        env: previewEnv,
    },
    {
        name: 'lan-vite',
        command: 'node node_modules/vite/bin/vite.js --mode development',
        env: previewEnv,
    },
], {
    prefix: 'name',
    prefixColors: ['blue', 'yellow'],
    killOthersOn: ['success', 'failure'],
});

result.catch(() => { process.exitCode = 1; });
