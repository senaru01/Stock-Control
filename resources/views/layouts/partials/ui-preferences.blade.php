    {{--
        UI preferences (theme, collapsed sidebar) live in localStorage and in a cookie. The cookie lets the
        server render them on <html>, so wire:navigate (which copies <html> attributes from the new page)
        keeps them; the script restores them before first paint and after every navigation.
    --}}
    <script>
        window.uiPreferences = {
            save(name, value) {
                try { localStorage.setItem(name, value) } catch (e) {}
                document.cookie = name + '=' + value + '; path=/; max-age=31536000; SameSite=Lax';
            },
            read(name) {
                try { return localStorage.getItem(name) } catch (e) { return null }
            },
            apply() {
                const theme = this.read('theme') === 'dark' ? 'dark' : 'light';
                const collapsed = this.read('sidebar_collapsed') === 'true' ? 'true' : 'false';
                document.documentElement.dataset.theme = theme;
                document.documentElement.dataset.sidebarCollapsed = collapsed;
                this.save('theme', theme);
                this.save('sidebar_collapsed', collapsed);
            },
        };

        try {
            const themeFromUrl = new URLSearchParams(location.search).get('theme');
            if (themeFromUrl === 'dark' || themeFromUrl === 'light') {
                localStorage.setItem('theme', themeFromUrl);
            }
            if (localStorage.getItem('sidebar_collapsed') === null && localStorage.getItem('sidebar-collapsed') !== null) {
                localStorage.setItem('sidebar_collapsed', localStorage.getItem('sidebar-collapsed'));
            }
        } catch (e) {}

        window.uiPreferences.apply();
        document.addEventListener('livewire:navigated', () => window.uiPreferences.apply());
    </script>
