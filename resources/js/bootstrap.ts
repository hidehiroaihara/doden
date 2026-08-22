import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// サブディレクトリ公開（/dev など）でもAPIが正しく解決されるようにbaseURLを設定
const basePath = (import.meta.env.VITE_BASE_PATH as string | undefined) ?? '';
if (basePath) {
    window.axios.defaults.baseURL = basePath;
}
