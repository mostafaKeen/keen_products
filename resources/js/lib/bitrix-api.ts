export async function bitrixFetch<T = Record<string, unknown>>(
    url: string,
    jwt: string,
    body?: unknown,
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'POST',
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        Authorization: `Bearer ${jwt}`,
    };

    const init: RequestInit = { method, headers };

    if (body !== undefined && method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const res = await fetch(url, init);
    const data = await res.json().catch(() => ({}));

    if (!res.ok) {
        const message = (data && (data.message || data.error)) || `Request failed with status ${res.status}`;
        throw Object.assign(new Error(String(message)), {
            status: res.status,
            payload: data,
        });
    }

    return data as T;
}

declare global {
    interface Window {
        BX24?: {
            init: (cb: () => void) => void;
            installFinish: () => void;
            fitWindow: () => void;
            resizeWindow: (width: number, height: number) => void;
        };
    }
}

export function loadBx24Script(src = 'https://api.bitrix24.com/api/v1/'): Promise<boolean> {
    if (typeof window === 'undefined') {
        return Promise.resolve(false);
    }
    if (window.BX24) {
        return Promise.resolve(true);
    }
    // Outside an iframe (e.g. direct browser preview), don't block on Bitrix24 JS SDK.
    if (window.parent === window) {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve(Boolean(window.BX24));
        script.onerror = () => resolve(false);
        document.head.appendChild(script);
    });
}
