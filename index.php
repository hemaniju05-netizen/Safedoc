<?php
// index.php
require_once 'config.php';

if (isLoggedIn()) {
    if (isAdmin())  { header("Location: admin_dashboard.php"); exit; }
    if (isOwner())  { header("Location: owner_dashboard.php"); exit; }
    header("Location: customer_dashboard.php"); exit;
}

require_once 'header.php';
?>

<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:65vh; text-align:center;">
    <div class="glass-panel" style="padding:60px 40px; max-width:820px; width:100%;">
        <div style="font-size:4rem; margin-bottom:20px; animation:float 4s ease-in-out infinite;">🖨️</div>
        <h1 style="font-size:3rem; margin-bottom:20px; line-height:1.2;">
            Welcome to <br>
            <span style="background:linear-gradient(90deg,var(--primary),var(--accent)); -webkit-background-clip:text; -webkit-text-fill-color:transparent;">SafeDoc Portal</span>
        </h1>
        <p style="font-size:1.15rem; color:var(--text-muted); margin-bottom:40px; max-width:600px; margin-left:auto; margin-right:auto; line-height:1.7;">
            The next-generation document printing solution. Upload files securely with strict end-to-end privacy and auto-deletion after 1 hour.
        </p>
        <div style="display:flex; gap:20px; justify-content:center; flex-wrap:wrap;">
            <a href="auth.php" class="btn-glow" style="font-size:1.1rem; padding:14px 40px;">Get Started →</a>
            <a href="map.php" class="btn-outline" style="font-size:1.1rem; padding:14px 40px;" onclick="window.location='auth.php'; return false;">📍 Find Printers</a>
        </div>

        <div style="display:flex; gap:30px; justify-content:center; margin-top:50px; flex-wrap:wrap;">
            <div style="text-align:center; padding:20px; flex:1; min-width:140px;">
                <div style="font-size:2rem;">🔒</div>
                <div style="font-weight:700; margin-top:8px;">End-to-End Privacy</div>
                <div style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">Files auto-deleted after 1 hour</div>
            </div>
            <div style="text-align:center; padding:20px; flex:1; min-width:140px;">
                <div style="font-size:2rem;">📍</div>
                <div style="font-weight:700; margin-top:8px;">Location-Based Search</div>
                <div style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">Find printers near you instantly</div>
            </div>
            <div style="text-align:center; padding:20px; flex:1; min-width:140px;">
                <div style="font-size:2rem;">🛡️</div>
                <div style="font-weight:700; margin-top:8px;">Verified Shops</div>
                <div style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">All shops admin-approved</div>
            </div>
        </div>
    </div>
</div>

<style>@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}</style>

<!-- Discreet Admin Access Link -->
<div style="text-align:center; margin-top:10px; padding-bottom:20px;">
    <a href="admin_auth.php" style="color:var(--text-muted); font-size:0.78rem; text-decoration:none; opacity:0.5; transition:0.2s; letter-spacing:0.5px;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'">
        🛡️ Admin Portal
    </a>
</div>

<?php require_once 'footer.php'; ?>
