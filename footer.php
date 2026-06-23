</div><!-- /main-container -->

<footer style="text-align:center; padding:30px; color:var(--text-muted); font-size:0.9rem;">
    &copy; <?= date("Y"); ?> SafeDoc. Developed by
    <a href="#" style="color:var(--primary); font-weight:600; text-decoration:none;">Adhitya Biju, Annmary Cyriac, Hema Niju, Shelna Subash</a>.
</footer>

<script>
    // ── Theme ──
    const htmlEl    = document.documentElement;
    const themeIcon = document.getElementById('themeIcon');
    const saved     = localStorage.getItem('sd_theme') || 'dark';
    htmlEl.setAttribute('data-theme', saved);
    updateIcon(saved);

    function toggleTheme() {
        const next = htmlEl.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        htmlEl.setAttribute('data-theme', next);
        localStorage.setItem('sd_theme', next);
        updateIcon(next);
    }
    function updateIcon(t) {
        if (themeIcon) themeIcon.innerText = t === 'dark' ? '☀️' : '🌙';
    }

    // ── Mobile Menu ──
    function toggleMobileMenu() {
        const menu = document.getElementById('mobileMenu');
        const btn  = document.getElementById('hamburgerBtn');
        const open = menu.classList.toggle('open');
        btn.classList.toggle('open', open);
    }
    document.addEventListener('click', function(e) {
        const menu = document.getElementById('mobileMenu');
        const btn  = document.getElementById('hamburgerBtn');
        if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) {
            menu.classList.remove('open');
            btn.classList.remove('open');
        }
    });

    // ── Security ──
    document.addEventListener('contextmenu', e => e.preventDefault());
</script>
</body>
</html>
