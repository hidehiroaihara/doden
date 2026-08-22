import '../css/app.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// 数値入力: キーボードの上下矢印・ホイールで値が変わらないようにする（全画面共通）
if (typeof document !== 'undefined') {
    const isNumberInput = (el: EventTarget | null): el is HTMLInputElement =>
        el instanceof HTMLInputElement && el.type === 'number';

    document.addEventListener(
        'keydown',
        (e) => {
            if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && isNumberInput(e.target)) {
                e.preventDefault();
            }
        },
        true,
    );

    document.addEventListener(
        'wheel',
        (e) => {
            if (isNumberInput(document.activeElement)) {
                (document.activeElement as HTMLInputElement).blur();
            }
        },
        { passive: true },
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
