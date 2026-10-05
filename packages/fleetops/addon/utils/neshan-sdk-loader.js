const NESHAN_SDK_URL = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js';
const NESHAN_CSS_URL = 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css';

let loadPromise;

export default function loadNeshanSdk() {
    if (typeof document === 'undefined') {
        return Promise.reject(new Error('Neshan SDK requires a browser environment.'));
    }

    if (window.L?.Map && document.querySelector(`script[src="${NESHAN_SDK_URL}"][data-loaded="true"]`)) {
        return Promise.resolve(window.L);
    }

    if (loadPromise) {
        return loadPromise;
    }

    if (!document.querySelector(`link[href="${NESHAN_CSS_URL}"]`)) {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = NESHAN_CSS_URL;
        stylesheet.dataset.fleetopsNeshan = 'true';
        document.head.appendChild(stylesheet);
    }

    loadPromise = new Promise((resolve, reject) => {
        let script = document.querySelector(`script[src="${NESHAN_SDK_URL}"]`);
        if (!script) {
            script = document.createElement('script');
            script.src = NESHAN_SDK_URL;
            script.async = true;
            script.dataset.fleetopsNeshan = 'true';
            document.head.appendChild(script);
        }

        const loaded = () => {
            script.dataset.loaded = 'true';
            window.leaflet = window.L;
            resolve(window.L);
        };
        const failed = () => {
            loadPromise = null;
            reject(new Error('Unable to load the Neshan Leaflet SDK.'));
        };

        script.addEventListener('load', loaded, { once: true });
        script.addEventListener('error', failed, { once: true });
    });

    return loadPromise;
}

export { NESHAN_CSS_URL, NESHAN_SDK_URL };
