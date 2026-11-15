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
