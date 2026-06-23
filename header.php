<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="https://img.icons8.com/ios-filled/50/10b981/print.png">
    <title>SafeDoc | Pro Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-grad-1: #f1f5f9; --bg-grad-2: #cbd5e1;
            --text-main: #0f172a; --text-muted: #475569;
            --glass-bg: rgba(255,255,255,0.95); --glass-border: rgba(0,0,0,0.15);
            --primary: #059669; --primary-glow: rgba(5,150,105,0.2);
            --accent: #2563eb; --input-bg: rgba(0,0,0,0.04);
            --danger: #ef4444; --warning: #f59e0b;
            --nav-h: 80px;
        }
        [data-theme="dark"] {
            --bg-grad-1: #0f172a; --bg-grad-2: #020617;
            --text-main: #f8fafc; --text-muted: #94a3b8;
            --glass-bg: rgba(30,41,59,0.7); --glass-border: rgba(255,255,255,0.1);
            --primary: #10b981; --primary-glow: rgba(16,185,129,0.25);
            --accent: #3b82f6; --input-bg: rgba(255,255,255,0.05);
        }

        * { box-sizing:border-box; font-family:'Outfit',sans-serif; transition:background-color 0.4s,color 0.4s,border-color 0.4s; }
        body { margin:0; min-height:100vh; background:linear-gradient(135deg,var(--bg-grad-1),var(--bg-grad-2)); color:var(--text-main); display:flex; flex-direction:column; }

        .glass-panel { background:var(--glass-bg); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border:1px solid var(--glass-border); border-radius:24px; box-shadow:0 20px 40px rgba(0,0,0,0.08); }

        /* ── NAVBAR ── */
        .navbar {
            display:flex; justify-content:space-between; align-items:center;
            padding:0 40px; margin:16px 20px; height:var(--nav-h);
            position:sticky; top:16px; z-index:1000;
        }
        .nav-logo {
            font-size:1.6rem; font-weight:800;
            background:linear-gradient(90deg,var(--primary),var(--accent));
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
            text-decoration:none; white-space:nowrap;
        }
        .nav-logo span { font-size:0.75rem; font-weight:600; color:var(--text-muted); -webkit-text-fill-color:var(--text-muted); display:block; margin-top:-4px; letter-spacing:1px; }

        .nav-controls { display:flex; gap:12px; align-items:center; }
        .nav-user-pill {
            display:flex; align-items:center; gap:8px;
            background:var(--input-bg); border:1px solid var(--glass-border);
            border-radius:30px; padding:6px 14px 6px 8px;
            font-weight:600; font-size:0.9rem; white-space:nowrap;
        }
        .nav-avatar {
            width:30px; height:30px; border-radius:50%;
            background:linear-gradient(135deg,var(--primary),var(--accent));
            display:flex; align-items:center; justify-content:center;
            font-size:0.8rem; font-weight:800; color:#fff; flex-shrink:0;
        }

        .btn-glow {
            background:var(--primary); color:#fff !important;
            font-weight:600; padding:10px 22px; border-radius:12px;
            border:none; cursor:pointer; transition:0.3s;
            text-decoration:none; display:inline-flex; align-items:center; gap:6px;
            text-align:center; box-shadow:0 4px 15px var(--primary-glow);
            text-shadow:0 1px 2px rgba(0,0,0,0.25); white-space:nowrap; font-size:0.95rem;
        }
        .btn-glow:hover { transform:translateY(-2px); filter:brightness(1.1); }

        .btn-nav {
            background:transparent; color:var(--text-main); font-weight:600;
            padding:9px 18px; border-radius:12px; border:1.5px solid var(--glass-border);
            cursor:pointer; text-decoration:none; transition:0.3s;
            display:inline-flex; align-items:center; gap:6px;
            font-size:0.9rem; white-space:nowrap;
        }
        .btn-nav:hover { background:var(--input-bg); border-color:var(--primary); color:var(--primary); }
        .btn-nav.active { background:var(--primary-glow); border-color:var(--primary); color:var(--primary); }
        .btn-nav.danger  { border-color:rgba(239,68,68,0.4); color:var(--danger); }
        .btn-nav.danger:hover { background:rgba(239,68,68,0.1); }
        .btn-nav.admin-btn { border-color:rgba(168,85,247,0.5); color:#a855f7; }
        .btn-nav.admin-btn:hover { background:rgba(168,85,247,0.1); }

        .btn-outline { background:transparent; color:var(--text-main); font-weight:600; padding:10px 24px; border-radius:12px; border:2px solid var(--glass-border); cursor:pointer; text-decoration:none; transition:0.3s; }
        .btn-outline:hover { background:var(--input-bg); }

        .theme-switch-btn {
            background:var(--glass-bg); border:1px solid var(--glass-border);
            border-radius:50%; width:40px; height:40px; font-size:1.1rem;
            display:flex; align-items:center; justify-content:center;
            cursor:pointer; transition:0.3s; color:var(--text-main); flex-shrink:0;
        }
        .theme-switch-btn:hover { transform:scale(1.1) rotate(15deg); background:var(--input-bg); }

        /* ── HAMBURGER ── */
        .hamburger {
            display:none; flex-direction:column; gap:5px; cursor:pointer;
            padding:8px; border-radius:10px; border:1px solid var(--glass-border);
            background:var(--glass-bg);
        }
        .hamburger span { display:block; width:22px; height:2px; background:var(--text-main); border-radius:2px; transition:0.3s; }
        .hamburger.open span:nth-child(1) { transform:rotate(45deg) translate(5px,5px); }
        .hamburger.open span:nth-child(2) { opacity:0; }
        .hamburger.open span:nth-child(3) { transform:rotate(-45deg) translate(5px,-5px); }

        /* ── MOBILE MENU ── */
        .mobile-menu {
            display:none; position:fixed; top:calc(var(--nav-h) + 32px); left:0; right:0;
            z-index:999; padding:0 20px;
        }
        .mobile-menu.open { display:block; }
        .mobile-menu-inner {
            background:var(--glass-bg); backdrop-filter:blur(20px);
            border:1px solid var(--glass-border); border-radius:20px;
            padding:20px; display:flex; flex-direction:column; gap:10px;
            box-shadow:0 20px 40px rgba(0,0,0,0.15);
        }
        .mobile-menu a, .mobile-menu button {
            display:flex; align-items:center; gap:10px;
            padding:12px 16px; border-radius:12px; font-weight:600;
            color:var(--text-main); text-decoration:none; font-size:1rem;
            border:none; background:transparent; cursor:pointer; width:100%; text-align:left;
            transition:0.2s;
        }
        .mobile-menu a:hover, .mobile-menu button:hover { background:var(--input-bg); }
        .mobile-menu a.active { background:var(--primary-glow); color:var(--primary); }
        .mobile-menu .m-divider { height:1px; background:var(--glass-border); margin:4px 0; }

        @media (max-width: 900px) {
            .navbar { padding:0 20px; }
            .nav-controls { display:none; }
            .hamburger { display:flex; }
        }
        @media (min-width: 901px) {
            .mobile-menu { display:none !important; }
        }

        .main-container { flex:1; padding:40px; max-width:1200px; margin:0 auto; width:100%; }

        @media (max-width: 600px) {
            .main-container { padding:20px 15px; }
            .navbar { margin:10px; }
        }
    </style>
</head>
<body>

<?php
$current_page = basename($_SERVER['PHP_SELF']);
function navActive($page) {
    global $current_page;
    return $current_page === $page ? ' active' : '';
}
$user_initial = '';
if (isLoggedIn() && isset($_SESSION['name'])) {
    $user_initial = strtoupper(substr($_SESSION['name'], 0, 1));
}
?>

<nav class="navbar glass-panel">
    <a href="index.php" class="nav-logo">
        SafeDoc
        <span>Secure Print Portal</span>
    </a>

    <div class="nav-controls">
        <?php if(isLoggedIn()): ?>
            <div class="nav-user-pill">
                <div class="nav-avatar"><?= htmlspecialchars($user_initial) ?></div>
                <?= htmlspecialchars($_SESSION['name']) ?>
                <?php if(isOwner()): ?>
                    <span style="font-size:0.7rem;background:rgba(16,185,129,0.2);color:var(--primary);padding:2px 8px;border-radius:20px;">SHOP</span>
                <?php elseif(isAdmin()): ?>
                    <span style="font-size:0.7rem;background:rgba(168,85,247,0.2);color:#a855f7;padding:2px 8px;border-radius:20px;">ADMIN</span>
                <?php endif; ?>
            </div>

            <?php if(isAdmin()): ?>
                <a href="admin_dashboard.php" class="btn-nav admin-btn<?= navActive('admin_dashboard.php') ?>">🛡️ Admin</a>
            <?php elseif(isOwner()): ?>
                <a href="owner_dashboard.php" class="btn-nav<?= navActive('owner_dashboard.php') ?>">🖨️ Dashboard</a>
            <?php else: ?>
                <a href="map.php" class="btn-nav<?= navActive('map.php') ?>" style="border-color:rgba(59,130,246,0.4);color:var(--accent);">📍 Find Printers</a>
                <a href="customer_dashboard.php" class="btn-nav<?= navActive('customer_dashboard.php') ?>">📂 My Docs</a>
            <?php endif; ?>

            <a href="logout.php" class="btn-nav danger">🚪 Logout</a>
        <?php else: ?>
            <a href="admin_auth.php" class="btn-nav" style="border-color:rgba(168,85,247,0.35); color:#a855f7; font-size:0.82rem; padding:7px 14px;" title="Admin Portal">🛡️</a>
            <a href="auth.php" class="btn-glow">Get Started →</a>
        <?php endif; ?>

        <button class="theme-switch-btn" onclick="toggleTheme()" id="themeIcon" title="Toggle Theme">🌙</button>
        <button class="hamburger" id="hamburgerBtn" onclick="toggleMobileMenu()" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
    <div class="mobile-menu-inner">
        <?php if(isLoggedIn()): ?>
            <div style="padding:8px 16px; color:var(--text-muted); font-size:0.85rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">
                👤 <?= htmlspecialchars($_SESSION['name']) ?>
            </div>
            <div class="m-divider"></div>

            <?php if(isAdmin()): ?>
                <a href="admin_dashboard.php" class="<?= $current_page==='admin_dashboard.php'?'active':'' ?>">🛡️ Admin Panel</a>
            <?php elseif(isOwner()): ?>
                <a href="owner_dashboard.php" class="<?= $current_page==='owner_dashboard.php'?'active':'' ?>">🖨️ Owner Dashboard</a>
            <?php else: ?>
                <a href="map.php" class="<?= $current_page==='map.php'?'active':'' ?>">📍 Find Printers</a>
                <a href="customer_dashboard.php" class="<?= $current_page==='customer_dashboard.php'?'active':'' ?>">📂 My Documents</a>
            <?php endif; ?>

            <div class="m-divider"></div>
            <a href="logout.php" style="color:var(--danger);">🚪 Logout</a>
        <?php else: ?>
            <a href="auth.php">🔐 Sign In / Register</a>
            <a href="admin_auth.php" style="color:#a855f7;">🛡️ Admin Portal</a>
        <?php endif; ?>
    </div>
</div>

<div class="main-container">
