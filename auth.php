<?php
// auth.php
require_once 'config.php';

if (isLoggedIn()) { header("Location: index.php"); exit; }

$error = ''; $success = '';
$active_tab = 'login';

// Admin secret code (change this in production!)
define('ADMIN_SECRET_CODE', 'SAFEDOC_ADMIN_2024');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['register'])) {
        $active_tab = 'register';
        $name     = $conn->real_escape_string($_POST['name']);
        $email    = $conn->real_escape_string($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
        $role     = $conn->real_escape_string($_POST['role']);

        // Validate role
        if (!in_array($role, ['customer', 'owner', 'admin'])) { $error = "Invalid role."; }
        else {
            // Admin requires secret code
            if ($role === 'admin') {
                $secret = $_POST['admin_code'] ?? '';
                if ($secret !== ADMIN_SECRET_CODE) { $error = "Invalid admin secret code."; }
            }

            if (!$error) {
                $address = isset($_POST['address']) ? $conn->real_escape_string($_POST['address']) : '';
                $lat = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : 'NULL';
                $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : 'NULL';

                // Owners need admin approval; customers and admins are auto-approved
                $is_approved = ($role === 'owner') ? 0 : 1;

                $check = $conn->query("SELECT id FROM sd_users WHERE email='$email'");
                if ($check->num_rows > 0) {
                    $error = "Email already exists! Please login.";
                } else {
                    $sql = "INSERT INTO sd_users (name, email, password, role, address, latitude, longitude, shop_status, is_approved)
                            VALUES ('$name', '$email', '$password', '$role', '$address', $lat, $lng, 'Offline', $is_approved)";
                    if ($conn->query($sql)) {
                        if ($role === 'owner') {
                            $success = "Registration submitted! Your Shop Owner account is pending admin approval. You'll be able to log in once approved.";
                        } else {
                            $success = "Registration successful! You can now login.";
                        }
                        $active_tab = 'login';
                    } else {
                        $error = "Error: " . $conn->error;
                    }
                }
            }
        }
    }

    elseif (isset($_POST['login'])) {
        $active_tab = 'login';
        $email    = $conn->real_escape_string($_POST['email']);
        $password = $_POST['password'];

        $res = $conn->query("SELECT * FROM sd_users WHERE email='$email'");
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                // Check approval for owners
                if ($user['role'] === 'owner' && !$user['is_approved']) {
                    $error = "⏳ Your Shop Owner account is awaiting admin approval. Please check back later.";
                } else {
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['name']        = $user['name'];
                    $_SESSION['role']        = $user['role'];
                    $_SESSION['is_approved'] = $user['is_approved'];
                    header("Location: index.php"); exit;
                }
            } else { $error = "Invalid password."; }
        } else { $error = "No account found with that email."; }
    }
}

require_once 'header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    @keyframes fadeInUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
    @keyframes float    { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }

    .auth-wrapper { max-width: 500px; margin: 4vh auto 50px; width: 100%; }

    .tab-switcher {
        display:flex; background:var(--input-bg); border:1px solid var(--glass-border);
        border-radius:16px; padding:6px; margin-bottom:30px;
    }
    .tab-btn {
        flex:1; padding:12px; border:none; background:transparent; color:var(--text-muted);
        font-weight:600; font-size:1rem; border-radius:12px; cursor:pointer; transition:0.3s;
    }
    .tab-btn.active { background:var(--glass-bg); color:var(--primary); box-shadow:0 4px 15px rgba(0,0,0,0.05); }

    .glass-input { width:100%; padding:14px 15px; margin-bottom:16px; background:var(--input-bg); border:1px solid var(--glass-border); border-radius:12px; color:var(--text-main); font-size:1rem; transition:all 0.3s; }
    .glass-input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 15px var(--primary-glow); }
    .glass-input option { background:var(--bg-grad-1); color:var(--text-main); }

    .auth-card { padding:45px 40px; width:100%; display:none; }
    .auth-card.active-card { display:block; animation:fadeInUp 0.5s cubic-bezier(0.2,0.8,0.2,1) forwards; }

    .floating-icon { font-size:3rem; text-align:center; margin-bottom:12px; animation:float 4s ease-in-out infinite; }
    .msg-box { padding:14px; border-radius:10px; margin-bottom:18px; font-weight:600; text-align:center; animation:fadeInUp 0.4s; }

    #owner-fields, #admin-fields { display:none; margin-bottom:5px; animation:fadeInUp 0.4s forwards; }
    #reg-map { height:240px; width:100%; border-radius:12px; border:1px solid var(--glass-border); margin-bottom:12px; z-index:1; }

    .role-cards { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px; }
    .role-card {
        border:2px solid var(--glass-border); border-radius:14px; padding:14px 10px;
        text-align:center; cursor:pointer; transition:0.3s; background:var(--input-bg);
    }
    .role-card:hover { border-color:var(--primary); }
    .role-card.selected { border-color:var(--primary); background:var(--primary-glow); }
    .role-card .rc-icon { font-size:2rem; display:block; margin-bottom:6px; }
    .role-card .rc-label { font-weight:700; font-size:0.9rem; }
    .role-card .rc-sub { color:var(--text-muted); font-size:0.75rem; margin-top:3px; }
    .pending-badge {
        background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.4); color:#f59e0b;
        border-radius:12px; padding:12px 16px; font-size:0.9rem; font-weight:600; margin-bottom:16px; text-align:center;
    }

    @media(max-width:480px){.auth-card{padding:30px 20px;} .role-cards{grid-template-columns:1fr;}}
</style>

<div class="auth-wrapper">

    <?php if($error):  ?><div class="msg-box" style="background:rgba(239,68,68,0.2);color:#ef4444;border:1px solid #ef4444;"><?= $error ?></div><?php endif; ?>
    <?php if($success): ?><div class="msg-box" style="background:rgba(16,185,129,0.2);color:var(--primary);border:1px solid var(--primary);"><?= $success ?></div><?php endif; ?>

    <div class="tab-switcher">
        <button class="tab-btn <?= $active_tab=='login'?'active':'' ?>" id="btn-login" onclick="switchTab('login')">🔐 Sign In</button>
        <button class="tab-btn <?= $active_tab=='register'?'active':'' ?>" id="btn-register" onclick="switchTab('register')">✨ Register</button>
    </div>

    <!-- LOGIN CARD -->
    <div class="glass-panel auth-card <?= $active_tab=='login'?'active-card':'' ?>" id="card-login">
        <div class="floating-icon">🔐</div>
        <h2 style="margin-top:0; color:var(--primary); font-size:2rem; text-align:center;">Welcome Back</h2>
        <p style="color:var(--text-muted); margin-bottom:30px; text-align:center;">Securely access your SafeDoc portal.</p>
        <form method="POST">
            <input type="email" name="email" class="glass-input" placeholder="Email Address" required>
            <input type="password" name="password" class="glass-input" placeholder="Password" required>
            <button type="submit" name="login" class="btn-glow" style="width:100%; font-size:1.1rem; padding:14px; margin-top:8px; justify-content:center;">Sign In →</button>
        </form>
    </div>

    <!-- REGISTER CARD -->
    <div class="glass-panel auth-card <?= $active_tab=='register'?'active-card':'' ?>" id="card-register">
        <div class="floating-icon">✨</div>
        <h2 style="margin-top:0; color:var(--accent); font-size:2rem; text-align:center;">Join SafeDoc</h2>
        <p style="color:var(--text-muted); margin-bottom:25px; text-align:center;">Create your secure account.</p>

        <form method="POST">
            <input type="text" name="name" class="glass-input" placeholder="Full Name (or Shop Name)" required>
            <input type="email" name="email" class="glass-input" placeholder="Email Address" required>
            <input type="password" name="password" class="glass-input" placeholder="Create Strong Password" required>

            <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:10px;">Select Account Type:</label>
            <div class="role-cards">
                <div class="role-card" id="rc-customer" onclick="selectRole('customer')">
                    <span class="rc-icon">📄</span>
                    <div class="rc-label">Customer</div>
                    <div class="rc-sub">Upload & track documents</div>
                </div>
                <div class="role-card" id="rc-owner" onclick="selectRole('owner')">
                    <span class="rc-icon">🖨️</span>
                    <div class="rc-label">Shop Owner</div>
                    <div class="rc-sub">Manage print jobs</div>
                </div>
            </div>
            <input type="hidden" name="role" id="role-input" value="">

            <!-- Owner Info (pending notice) -->
            <div id="owner-pending-notice" style="display:none;" class="pending-badge">
                ⏳ Shop owner accounts require admin approval. You can register now and log in once approved.
            </div>

            <!-- Owner Fields -->
            <div id="owner-fields">
                <textarea name="address" class="glass-input" placeholder="Shop Address (e.g., Block 4, Tech Park)" rows="2"></textarea>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <p style="color:var(--text-muted); font-size:0.82rem; margin:0;">Click map or drag pin to set location:</p>
                    <button type="button" id="reg-locate-btn" onclick="useMyLocationReg()" style="font-size:0.78rem; padding:6px 12px; background:var(--glass-bg); border:1px solid var(--primary); color:var(--primary); border-radius:8px; cursor:pointer; font-weight:600;">📍 My Location</button>
                </div>
                <div id="reg-map"></div>
                <div id="reg-coords" style="font-size:0.78rem; color:var(--text-muted); margin-bottom:10px; text-align:right;"></div>
                <input type="hidden" name="latitude"  id="lat-input">
                <input type="hidden" name="longitude" id="lng-input">
            </div>

            <!-- Admin Secret -->
            <div id="admin-fields">
                <div class="pending-badge" style="border-color:rgba(168,85,247,0.4); color:#a855f7; background:rgba(168,85,247,0.1);">
                    🛡️ Admin accounts require a secret authorization code.
                </div>
                <input type="password" name="admin_code" id="admin_code" class="glass-input" placeholder="Admin Secret Code">
            </div>

            <button type="submit" name="register" id="reg-btn" class="btn-glow" style="width:100%; font-size:1.05rem; padding:14px; margin-top:8px; justify-content:center; display:none;">
                Create Account
            </button>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.auth-card').forEach(c => c.classList.remove('active-card'));
    document.getElementById('btn-'+tab).classList.add('active');
    document.getElementById('card-'+tab).classList.add('active-card');
    if (tab === 'register' && map) setTimeout(() => map.invalidateSize(), 100);
}

let selectedRole = '', map, marker;

function selectRole(role) {
    selectedRole = role;
    document.getElementById('role-input').value = role;
    document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('rc-'+role)?.classList.add('selected');
    document.getElementById('owner-fields').style.display = 'none';
    document.getElementById('owner-pending-notice').style.display = 'none';
    document.getElementById('admin-fields').style.display = 'none';
    document.getElementById('admin_code').required = false;
    document.getElementById('reg-btn').style.display = 'block';

    if (role === 'owner') {
        document.getElementById('owner-pending-notice').style.display = 'block';
        document.getElementById('owner-fields').style.display = 'block';
        if (!map) setTimeout(initRegMap, 100); else map.invalidateSize();
    } else if (role === 'admin') {
        document.getElementById('admin-fields').style.display = 'block';
        document.getElementById('admin_code').required = true;
    }
}

function initRegMap() {
    const defLat = 12.9716, defLng = 77.5946;
    map = L.map('reg-map').setView([defLat, defLng], 12);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png').addTo(map);
    marker = L.marker([defLat, defLng], {draggable:true}).addTo(map);
    setRegMarker(defLat, defLng);
    marker.on('dragend', e => { const p=e.target.getLatLng(); setRegMarker(p.lat, p.lng); });
    map.on('click', e => { marker.setLatLng(e.latlng); setRegMarker(e.latlng.lat, e.latlng.lng); });
    if ("geolocation" in navigator) {
        navigator.geolocation.getCurrentPosition(pos => {
            map.setView([pos.coords.latitude, pos.coords.longitude], 15);
            setRegMarker(pos.coords.latitude, pos.coords.longitude);
            marker.setLatLng([pos.coords.latitude, pos.coords.longitude]);
        });
    }
}

function setRegMarker(lat, lng) {
    document.getElementById('lat-input').value = lat;
    document.getElementById('lng-input').value = lng;
    document.getElementById('reg-coords').textContent = '📌 ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    if (marker) marker.setLatLng([lat, lng]);
}

function useMyLocationReg() {
    if (!("geolocation" in navigator)) { alert("Geolocation not supported."); return; }
    const btn = document.getElementById('reg-locate-btn');
    btn.textContent = "⏳ Locating..."; btn.disabled = true;
    navigator.geolocation.getCurrentPosition(
        pos => {
            if (!map) return;
            map.setView([pos.coords.latitude, pos.coords.longitude], 17);
            marker.setLatLng([pos.coords.latitude, pos.coords.longitude]);
            setRegMarker(pos.coords.latitude, pos.coords.longitude);
            btn.textContent = "✅ Set!"; btn.disabled = false;
            setTimeout(() => { btn.textContent = "📍 My Location"; }, 2500);
        },
        err => { alert("Could not get location: " + err.message); btn.textContent = "📍 My Location"; btn.disabled = false; }
    );
}
</script>

<!-- Discreet Admin Access -->
<div style="text-align:center; margin-top:6px; padding-bottom:10px;">
    <a href="admin_auth.php" style="color:var(--text-muted); font-size:0.78rem; text-decoration:none; opacity:0.45; transition:0.2s; letter-spacing:0.5px;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.45'">
        🛡️ Admin Portal
    </a>
</div>

<?php require_once 'footer.php'; ?>
