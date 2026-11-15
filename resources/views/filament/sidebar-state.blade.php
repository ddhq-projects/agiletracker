{{-- Filament 5.11 persists a null "collapsed groups" list, while its sidebar
     bindings call `.includes()` on it and log an Alpine error on every page.
     Seed a real array before Alpine boots so the sidebar starts clean. --}}
<script>
    (() => {
        try {
            const key = 'filament:sidebar:collapsed_groups';
            const stored = localStorage.getItem(key);

            if (stored === null || stored === 'null' || stored === 'undefined') {
                localStorage.setItem(key, '[]');
            }
        } catch (error) {
            // Storage may be unavailable (private mode); the panel still works.
        }
    })();
</script>

{{-- The sidebar is fully collapsed on desktop: hidden until the topbar button
     opens it. The preference lives in localStorage, so it is collapsed once per
     browser; after that the user's own open/close choice wins on every visit. --}}
<script>
    (() => {
        try {
            const seedKey = 'filament:sidebar:collapsed_default_seeded';

            if (localStorage.getItem(seedKey) === null) {
                localStorage.setItem('filament:sidebar:open', 'false');
                localStorage.setItem('filament:sidebar:desktop_open', 'false');
                localStorage.setItem(seedKey, '1');
            }
        } catch (error) {
            // Storage may be unavailable (private mode); the panel still works.
        }
    })();
</script>
