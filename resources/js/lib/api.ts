import { translate as t, i18n } from './i18n';

export async function requestJson<T>(
    route: { url: string; method: string },
    body?: unknown,
): Promise<T> {
    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
    const response = await fetch(route.url, {
        method: route.method.toUpperCase(),
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Accept-Language': i18n.global.locale.value,
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '',
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    if (response.status === 204) return undefined as T;
    let data;
    try {
        data = await response.json();
    } catch {
        throw new Error(t('errors.unreadable'));
    }
    if (!response.ok) {
        if (response.status === 401) throw new Error(t('errors.signIn'));
        if (response.status === 419) throw new Error(t('errors.session'));
        throw new Error(
            Object.values(data.errors ?? {})
                .flat()
                .join(' ') ||
                data.message ||
                t('errors.request', { status: response.status }),
        );
    }
    return data as T;
}
