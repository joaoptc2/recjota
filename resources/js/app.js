import './bootstrap';

/*
 * Tema claro/escuro (Seção 9.3). Preferência gravada no localStorage; sem
 * preferência, segue o sistema operacional.
 */
const THEME_KEY = 'recjota:theme';

function applyTheme(theme) {
    const dark = theme === 'dark'
        || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
}

window.toggleTheme = () => {
    const current = localStorage.getItem(THEME_KEY) ?? 'system';
    const next = current === 'dark' ? 'light' : 'dark';

    localStorage.setItem(THEME_KEY, next);
    applyTheme(next);
};

applyTheme(localStorage.getItem(THEME_KEY) ?? 'system');

/*
 * Google Picker (Seção 7.2). O escopo drive.file só mostra ao sistema o que
 * a pessoa escolher aqui. O token vem do servidor (rota protegida pela
 * Policy); o Picker é carregado sob demanda do CDN do Google, sem npm.
 *
 * Atenção: nada aqui devolve Promise para o Google — o Picker é aberto no
 * callback de carregamento, como a documentação pede.
 */
const GOOGLE_API_JS = 'https://apis.google.com/js/api.js';

function loadGoogleApi() {
    return new Promise((resolve, reject) => {
        if (window.gapi) {
            resolve(window.gapi);

            return;
        }

        const script = document.createElement('script');
        script.src = GOOGLE_API_JS;
        script.async = true;
        script.onload = () => resolve(window.gapi);
        script.onerror = () => reject(new Error('Não foi possível carregar o seletor do Google. Verifique a conexão e tente de novo.'));
        document.head.appendChild(script);
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('googlePicker', ({ tokenUrl, onPick }) => ({
        loading: false,
        error: '',

        async openPicker() {
            this.loading = true;
            this.error = '';

            try {
                const resposta = await fetch(tokenUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const dados = await resposta.json();

                if (!resposta.ok) {
                    throw new Error(dados.erro ?? 'Não foi possível obter acesso ao Google Drive. Reconecte a conta.');
                }

                const gapi = await loadGoogleApi();

                gapi.load('picker', {
                    callback: () => {
                        this.loading = false;
                        this.show(dados);
                    },
                    onerror: () => {
                        this.loading = false;
                        this.error = 'O seletor do Google não carregou. Tente de novo em instantes.';
                    },
                });
            } catch (e) {
                this.loading = false;
                this.error = e.message;
            }
        },

        show({ access_token, api_key, app_id }) {
            const google = window.google;
            const view = new google.picker.DocsView(google.picker.ViewId.DOCS_IMAGES_AND_VIDEOS)
                .setIncludeFolders(true)
                .setSelectFolderEnabled(false);

            const builder = new google.picker.PickerBuilder()
                .setOAuthToken(access_token)
                .setLocale('pt-BR')
                .addView(view)
                .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
                .setCallback((data) => {
                    if (data.action !== google.picker.Action.PICKED) {
                        return;
                    }

                    (data.docs ?? []).forEach((doc) => onPick(doc.id));
                });

            if (api_key) {
                builder.setDeveloperKey(api_key);
            }

            if (app_id) {
                builder.setAppId(app_id);
            }

            builder.build().setVisible(true);
        },
    }));
});
