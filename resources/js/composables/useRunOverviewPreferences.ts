import { ref, watch } from 'vue';

const COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

function useBooleanPreferenceCookie(name: string) {
    const read = () => {
        if (typeof document === 'undefined') return false;
        try {
            return document.cookie.split(';').some(cookie => cookie.trim() === `${name}=1`);
        } catch {
            return false;
        }
    };
    const enabled = ref(read());

    watch(enabled, value => {
        if (typeof document === 'undefined') return;
        try {
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = `${name}=${value ? '1' : '0'}; Path=/; Max-Age=${COOKIE_MAX_AGE}; SameSite=Lax${secure}`;
        } catch {
            // Keep the toggle usable when the browser blocks preference cookies.
        }
    }, { flush: 'sync' });

    return enabled;
}

export function useRunOverviewPreferences() {
    return {
        plainDpsEnabled: useBooleanPreferenceCookie('fullparty_overview_plain_dps'),
        numberedSecondaryPartiesEnabled: useBooleanPreferenceCookie('fullparty_overview_numbered_parties'),
        allianceProgressEnabled: useBooleanPreferenceCookie('fullparty_overview_alliance_progress'),
    };
}
