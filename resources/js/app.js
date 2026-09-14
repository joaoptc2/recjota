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
