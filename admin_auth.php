<?php
// admin_auth.php — SafeDoc Admin Portal (Single Admin, Secret Code + Password)
require_once 'config.php';

if (isLoggedIn()) {
    if (isAdmin()) { header("Location: admin_dashboard.php"); exit; }
    header("Location: index.php"); exit;
}

// ── Single hardcoded admin secret (change this in production) ────────────────
define('ADMIN_SECRET_CODE', 'SAFEDOC_ADMIN_2026');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email      = $conn->real_escape_string(trim($_POST['email']));
    $password   = $_POST['password'];
    $secret_in  = trim($_POST['secret_code'] ?? '');

    // 1. Check secret code first
    if ($secret_in !== ADMIN_SECRET_CODE) {
        $error = "Invalid authorization code. Access denied.";
    } else {
        $res = $conn->query("SELECT * FROM sd_users WHERE email='$email' AND role='admin'");
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['name']        = $user['name'];
                $_SESSION['role']        = $user['role'];
                $_SESSION['is_approved'] = 1;
                header("Location: admin_dashboard.php"); exit;
            } else {
                $error = "Incorrect password.";
            }
        } else {
            $error = "No admin account found with that email.";
        }
    }
}

require_once 'header.php';
?>
<style>
@keyframes fadeInUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0   rgba(168,85,247,0.4); }
    70%  { box-shadow: 0 0 0 20px rgba(168,85,247,0); }
    100% { box-shadow: 0 0 0 0   rgba(168,85,247,0); }
}
@keyframes shimmer {
    0%   { background-position: -200% center; }
    100% { background-position:  200% center; }
}

.admin-wrapper {
    max-width: 460px;
    margin: 5vh auto 60px;
    width: 100%;
    animation: fadeInUp 0.5s ease-out;
}

.admin-badge { text-align: center; margin-bottom: 28px; }
.admin-badge-icon {
    width: 76px; height: 76px;
    border-radius: 50%;
    background: linear-gradient(135deg, #7c3aed, #a855f7);
    display: flex; align-items: center; justify-content: center;
    font-size: 2.2rem; margin: 0 auto 16px;
    animation: pulse-ring 2.5s ease-out infinite;
    box-shadow: 0 8px 24px rgba(168,85,247,0.35);
}
.admin-badge-title {
    font-size: 1.05rem; font-weight: 700; letter-spacing: 3px;
    text-transform: uppercase;
    background: linear-gradient(90deg, #a855f7, #7c3aed, #a855f7);
    background-size: 200% auto;
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    animation: shimmer 3s linear infinite;
}
.admin-badge-sub { color: var(--text-muted); font-size: 0.88rem; margin-top: 6px; }

.admin-input {
    width:100%; padding:13px 15px; margin-bottom:14px;
    background:var(--input-bg); border:1.5px solid var(--glass-border);
    border-radius:12px; color:var(--text-main); font-size:0.97rem;
    transition:0.3s; box-sizing:border-box; font-family:inherit;
}
.admin-input:focus { outline:none; border-color:#a855f7; box-shadow:0 0 0 3px rgba(168,85,247,0.15); }

.secret-wrap { position:relative; margin-bottom:14px; }
.secret-wrap .admin-input { padding-left:42px; margin-bottom:0; border-color:rgba(168,85,247,0.45); background:rgba(168,85,247,0.05); }
.secret-wrap::before { content:'🔑'; position:absolute; left:13px; top:50%; transform:translateY(-50%); font-size:1rem; z-index:1; }

.btn-admin {
    width:100%; padding:14px; border:none; border-radius:12px; cursor:pointer;
    font-size:1.05rem; font-weight:700; color:#fff; font-family:inherit;
    background:linear-gradient(135deg,#7c3aed,#a855f7);
    box-shadow:0 6px 20px rgba(124,58,237,0.4);
    transition:0.3s; margin-top:6px;
}
.btn-admin:hover { transform:translateY(-2px); filter:brightness(1.1); }

.msg-box { padding:13px 16px; border-radius:10px; margin-bottom:18px; font-weight:600; text-align:center; font-size:0.92rem; }

.security-note {
    background:rgba(168,85,247,0.06); border:1px solid rgba(168,85,247,0.2);
    border-radius:10px; padding:12px 14px;
    font-size:0.82rem; color:var(--text-muted); margin-bottom:20px; line-height:1.55;
}

.show-code-row { display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.82rem; color:var(--text-muted); margin-top:7px; margin-bottom:16px; }
.show-code-row input[type=checkbox] { accent-color:#a855f7; cursor:pointer; }

.divider { display:flex; align-items:center; gap:12px; margin:20px 0; }
.divider::before, .divider::after { content:''; flex:1; height:1px; background:var(--glass-border); }
.divider span { color:var(--text-muted); font-size:0.8rem; white-space:nowrap; }

.back-link { display:block; text-align:center; margin-top:22px; color:var(--text-muted); font-size:0.88rem; text-decoration:none; transition:0.2s; }
.back-link:hover { color:var(--primary); }

@media(max-width:480px){ .auth-card{ padding:28px 18px; } }
</style>

<div class="admin-wrapper">

    <!-- Badge -->
    <div class="admin-badge">
        <div class="admin-badge-icon">🛡️</div>
        <div class="admin-badge-title">Admin Portal</div>
        <div class="admin-badge-sub">SafeDoc Platform Management — Restricted Access</div>
    </div>

    <?php if($error): ?>
    <div class="msg-box" style="background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.4);">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="glass-panel" style="padding:40px 38px;">
        <h2 style="margin:0 0 5px; font-size:1.5rem; color:var(--text-main);">Administrator Sign In</h2>
        <p style="color:var(--text-muted); margin:0 0 26px; font-size:0.9rem;">Three credentials required for secure access.</p>

        <div class="security-note">
            🔒 This portal is restricted to authorised SafeDoc administrators only.
            All three fields — <strong>email</strong>, <strong>password</strong>, and the
            <strong>authorization code</strong> — must be correct to gain access.
        </div>

        <form method="POST">
            <input type="email" name="email" class="admin-input" placeholder="Admin Email Address" required autocomplete="email">
            <input type="password" name="password" id="passwordInput" class="admin-input" placeholder="Admin Password" required autocomplete="current-password">

            <label style="display:block; color:var(--text-muted); font-size:0.8rem; font-weight:700; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:7px;">Authorization Code</label>
            <div class="secret-wrap">
                <input type="password" name="secret_code" id="secretInput" class="admin-input" placeholder="Enter admin authorization code" required>
            </div>
            <label class="show-code-row">
                <input type="checkbox" onchange="toggleSecretVis(this)"> Show authorization code
            </label>

            <button type="submit" name="login" class="btn-admin">Sign In to Admin Panel →</button>
        </form>

        <div class="divider"><span>restricted access</span></div>
        <p style="text-align:center; color:var(--text-muted); font-size:0.8rem; margin:0;">
            One admin account per SafeDoc deployment.<br>Contact your system admin if you have lost access.
        </p>
    </div>

    <a href="index.php" class="back-link">← Back to SafeDoc Home</a>
</div>

<script>
function toggleSecretVis(cb) {
    document.getElementById('secretInput').type = cb.checked ? 'text' : 'password';
}
</script>

<?php require_once 'footer.php'; ?>
