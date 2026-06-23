<?php
// owner_dashboard.php
require_once 'config.php';

if (!isLoggedIn() || !isOwner()) { header("Location: index.php"); exit; }

$user_id    = $_SESSION['user_id'];
$target_dir = "uploads/";
$msg        = "";

// ── CHECK APPROVAL ──────────────────────────────────────────────────────────
$owner_row = $conn->query("SELECT * FROM sd_users WHERE id=$user_id")->fetch_assoc();
if (!$owner_row['is_approved']) {
    require_once 'header.php';
    echo "<div class='glass-panel' style='max-width:600px;margin:60px auto;padding:60px;text-align:center;'>
        <div style='font-size:4rem;margin-bottom:20px;'>⏳</div>
        <h2 style='color:#f59e0b;'>Awaiting Admin Approval</h2>
        <p style='color:var(--text-muted);font-size:1.05rem;'>Your Shop Owner account is pending review by our admin team. You'll be notified once approved.</p>
        <a href='logout.php' class='btn-glow' style='display:inline-block;margin-top:25px;background:#ef4444;'>Logout</a>
    </div>";
    require_once 'footer.php';
    exit;
}

// ── STATUS TOGGLE ────────────────────────────────────────────────────────────
if (isset($_POST['shop_status'])) {
    $new_status = $conn->real_escape_string($_POST['shop_status']);
    if (in_array($new_status, ['Available','Busy','Offline'])) {
        $conn->query("UPDATE sd_users SET shop_status='$new_status' WHERE id=$user_id");
        $owner_row['shop_status'] = $new_status;
    }
}

// ── SHOP HOURS UPDATE ────────────────────────────────────────────────────────
if (isset($_POST['update_hours'])) {
    $open_t  = $conn->real_escape_string($_POST['open_time']  ?? '');
    $close_t = $conn->real_escape_string($_POST['close_time'] ?? '');
    if ($open_t && $close_t) {
        $conn->query("UPDATE sd_users SET open_time='$open_t', close_time='$close_t' WHERE id=$user_id");
        $owner_row['open_time']  = $open_t;
        $owner_row['close_time'] = $close_t;
        $msg = "🕐 Shop hours updated!";

        // Auto-adjust status based on current time
        $now   = date('H:i:s');
        $is_open_hours = ($now >= $open_t && $now <= $close_t);
        $auto_status   = $is_open_hours ? 'Available' : 'Offline';
        $conn->query("UPDATE sd_users SET shop_status='$auto_status' WHERE id=$user_id");
        $owner_row['shop_status'] = $auto_status;
    } else {
        $msg = "⚠️ Please set both open and close times.";
    }
}

// ── LOCATION UPDATE ──────────────────────────────────────────────────────────
if (isset($_POST['update_location'])) {
    $new_lat  = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $new_lng  = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $new_addr = $conn->real_escape_string($_POST['address'] ?? '');
    if ($new_lat !== null && $new_lng !== null) {
        $conn->query("UPDATE sd_users SET latitude=$new_lat, longitude=$new_lng, address='$new_addr' WHERE id=$user_id");
        $owner_row['latitude']  = $new_lat;
        $owner_row['longitude'] = $new_lng;
        $owner_row['address']   = $new_addr;
        $msg = "📍 Shop location updated!";
    }
}

// ── PRICING UPDATE ────────────────────────────────────────────────────────────
if (isset($_POST['update_pricing'])) {
    $bw_price    = max(0.50, min(100.00, (float)($_POST['bw_price']    ?? 2.00)));
    $color_price = max(0.50, min(500.00, (float)($_POST['color_price'] ?? 10.00)));
    $conn->query("UPDATE sd_users SET bw_price=$bw_price, color_price=$color_price WHERE id=$user_id");
    $owner_data['bw_price']    = $bw_price;
    $owner_data['color_price'] = $color_price;
    $msg = "💰 Print pricing updated successfully!";
}

// ── MARK AS READY ────────────────────────────────────────────────────────────
if (isset($_POST['mark_ready'])) {
    $job_id = (int)$_POST['job_id'];
    $jrow   = $conn->query("SELECT p.*, u.name as cust_name, u.email FROM sd_print_jobs p JOIN sd_users u ON p.user_id=u.id WHERE p.id=$job_id AND p.shop_id=$user_id")->fetch_assoc();
    if ($jrow) {
        $conn->query("UPDATE sd_print_jobs SET status='ready' WHERE id=$job_id AND shop_id=$user_id");
        $pin = $jrow['pin_code'];
        if (!$pin) { $pin = generatePIN(); $conn->query("UPDATE sd_print_jobs SET pin_code='$pin' WHERE id=$job_id"); }
        sendEmail($jrow['email'], "Your document is ready for pickup! — SafeDoc", buildReadyEmail($jrow['cust_name'], $owner_row['name'], $pin, $jrow['original_name'] ?: $jrow['filename']));
        $msg = "🔔 Customer notified! Document marked Ready for Pickup.";
    }
}

// ── HANDED OVER & PIN VERIFICATION ──────────────────────────────────────────
if (isset($_POST['verify_pin_btn'])) {
    $job_id = (int)$_POST['job_id'];
    $entered_pin = $_POST['entered_pin'];
    // Fetch the job and its pin
    $check = $conn->query("SELECT filename, pin_code FROM sd_print_jobs WHERE id=$job_id AND shop_id=$user_id");
    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        
        if ($row['pin_code'] === $entered_pin) {
            // PIN is correct — proceed with deletion
            $fp = $target_dir . $row['filename'];
            if (file_exists($fp)) unlink($fp);
            $conn->query("UPDATE sd_print_jobs SET status='printed' WHERE id=$job_id");
            $msg = "✔ PIN Verified! Order complete and document securely deleted.";
        } else {
            $msg = "❌ Invalid PIN! Please check with the customer.";
        }
    }
}

// ── AUTO-CLEANUP ─────────────────────────────────────────────────────────────
$to_expire = $conn->query("SELECT id, filename FROM sd_print_jobs WHERE upload_time < NOW() - INTERVAL 1 HOUR AND status IN ('active','ready')");
if ($to_expire && $to_expire->num_rows > 0) {
    while($row = $to_expire->fetch_assoc()) {
        $fp = $target_dir . $row['filename'];
        if (file_exists($fp)) unlink($fp);
        $conn->query("UPDATE sd_print_jobs SET status='expired' WHERE id={$row['id']}");
    }
}

// ── FETCH FRESH DATA ─────────────────────────────────────────────────────────
$owner_data    = $conn->query("SELECT * FROM sd_users WHERE id=$user_id")->fetch_assoc();
$current_status = $owner_data['shop_status'];
$owner_lat      = $owner_data['latitude']  ?? 12.9716;
$owner_lng      = $owner_data['longitude'] ?? 77.5946;
$owner_addr     = htmlspecialchars($owner_data['address'] ?? 'Location not set');
$open_time      = $owner_data['open_time']  ?? '';
$close_time     = $owner_data['close_time'] ?? '';

// ── AUTO STATUS FROM HOURS ───────────────────────────────────────────────────
if ($open_time && $close_time && !isset($_POST['shop_status'])) {
    $now = date('H:i:s');
    $is_open_hours = ($now >= $open_time && $now <= $close_time);
    // Only auto-set if currently offline (don't override manual busy)
    if ($is_open_hours && $current_status === 'Offline') {
        $conn->query("UPDATE sd_users SET shop_status='Available' WHERE id=$user_id");
        $current_status = 'Available';
    } elseif (!$is_open_hours && $current_status === 'Available') {
        $conn->query("UPDATE sd_users SET shop_status='Offline' WHERE id=$user_id");
        $current_status = 'Offline';
    }
}

// ── STATS ────────────────────────────────────────────────────────────────────
$stats = $conn->query("SELECT COUNT(*) as total,
    SUM(CASE WHEN status IN('active','ready') THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status NOT IN('active','ready') THEN 1 ELSE 0 END) as past_count,
    SUM(CASE WHEN status='printed' THEN estimated_cost ELSE 0 END) as total_revenue
    FROM sd_print_jobs WHERE shop_id=$user_id")->fetch_assoc();
$total_files   = $stats['total']        ?? 0;
$active_files  = $stats['active_count'] ?? 0;
$past_files    = $stats['past_count']   ?? 0;
$total_revenue = number_format((float)($stats['total_revenue'] ?? 0), 2);

// ── REVENUE CHARTS ───────────────────────────────────────────────────────────
$rev_result = $conn->query("SELECT DATE(upload_time) as day, SUM(estimated_cost) as dr, COUNT(*) as dj FROM sd_print_jobs WHERE shop_id=$user_id AND status='printed' AND upload_time>=NOW()-INTERVAL 30 DAY GROUP BY DATE(upload_time) ORDER BY day ASC");
$rev_map = []; $jobs_map = [];
if ($rev_result) while($r=$rev_result->fetch_assoc()) { $rev_map[$r['day']]=(float)$r['dr']; $jobs_map[$r['day']]=(int)$r['dj']; }
$revenue_labels=[]; $revenue_values=[]; $jobs_values=[];
for($i=29;$i>=0;$i--){ $d=date('Y-m-d',strtotime("-$i days")); $revenue_labels[]=date('d M',strtotime($d)); $revenue_values[]=$rev_map[$d]??0; $jobs_values[]=$jobs_map[$d]??0; }
$monthly_result=$conn->query("SELECT DATE_FORMAT(upload_time,'%b %Y') as month,DATE_FORMAT(upload_time,'%Y-%m') as sk,SUM(estimated_cost) as mr FROM sd_print_jobs WHERE shop_id=$user_id AND status='printed' AND upload_time>=NOW()-INTERVAL 6 MONTH GROUP BY sk,month ORDER BY sk ASC");
$monthly_labels=[]; $monthly_values=[];
if($monthly_result) while($r=$monthly_result->fetch_assoc()){$monthly_labels[]=$r['month'];$monthly_values[]=(float)$r['mr'];}
$today_rev=$conn->query("SELECT SUM(estimated_cost) as rev,COUNT(*) as cnt FROM sd_print_jobs WHERE shop_id=$user_id AND status='printed' AND DATE(upload_time)=CURDATE()")->fetch_assoc();
$week_rev=$conn->query("SELECT SUM(estimated_cost) as rev FROM sd_print_jobs WHERE shop_id=$user_id AND status='printed' AND upload_time>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_assoc();
$month_rev=$conn->query("SELECT SUM(estimated_cost) as rev FROM sd_print_jobs WHERE shop_id=$user_id AND status='printed' AND MONTH(upload_time)=MONTH(NOW()) AND YEAR(upload_time)=YEAR(NOW())")->fetch_assoc();
$today_revenue=number_format((float)($today_rev['rev']??0),2);
$today_jobs=(int)($today_rev['cnt']??0);
$week_revenue=number_format((float)($week_rev['rev']??0),2);
$month_revenue=number_format((float)($month_rev['rev']??0),2);

$search_active  = isset($_GET['search_active'])  ? $conn->real_escape_string($_GET['search_active'])  : '';
$search_history = isset($_GET['search_history']) ? $conn->real_escape_string($_GET['search_history']) : '';

require_once 'header.php';
?>

<style>
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:20px; margin-bottom:30px; }
.stat-card { background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:16px; padding:20px; text-align:center; }
.stat-card h3 { margin:0; font-size:2rem; color:var(--text-main); }
.stat-card p  { margin:5px 0 0; color:var(--text-muted); font-weight:600; font-size:0.9rem; text-transform:uppercase; letter-spacing:1px; }

.queue-item { display:flex; justify-content:space-between; align-items:center; padding:20px; border-bottom:1px solid var(--glass-border); flex-wrap:wrap; gap:15px; }
.queue-item:last-child { border-bottom:none; }
.timer { font-size:0.85rem; color:#ef4444; font-weight:700; margin-top:8px; display:inline-block; background:rgba(239,68,68,0.1); padding:4px 10px; border-radius:8px; }
.instruction-box { background:var(--input-bg); padding:10px 15px; border-radius:10px; border:1px solid var(--glass-border); font-size:0.9rem; color:var(--text-main); flex:1; min-width:250px; }
.filter-bar { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
.filter-input { flex:1; min-width:200px; padding:12px 15px; border-radius:10px; border:1px solid var(--glass-border); background:var(--glass-bg); color:var(--text-main); font-family:inherit; }
.filter-btn { background:var(--glass-border); color:var(--text-main); border:none; padding:12px 20px; border-radius:10px; font-weight:600; cursor:pointer; transition:0.3s; }
.filter-btn:hover { background:var(--primary); color:white; }

.overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.97); z-index:9999; flex-direction:column; align-items:center; padding:20px; backdrop-filter:blur(10px); }
.viewer-layout { display:flex; gap:20px; width:100%; max-width:1200px; height:85vh; }
.scroll-container { position:relative; flex-grow:1; background:#fff; border-radius:16px; overflow-y:scroll; scroll-behavior:smooth; }
.shield { position:absolute; top:0; left:0; width:100%; height:100%; z-index:10; cursor:default; }
iframe { width:100%; height:4000px; border:none; pointer-events:none; }
.nav-sidebar { display:flex; flex-direction:column; gap:15px; justify-content:center; }

.watermark-overlay { position:absolute; top:0; left:0; width:100%; height:100%; z-index:20; pointer-events:none; overflow:hidden; border-radius:16px; }
.watermark-text { position:absolute; font-size:0.85rem; font-weight:800; color:rgba(220,38,38,0.18); white-space:nowrap; transform:rotate(-35deg); letter-spacing:2px; user-select:none; font-family:'Outfit',sans-serif; }

.chart-toggle { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
.chart-tab { padding:8px 20px; border-radius:10px; border:1px solid var(--glass-border); background:var(--input-bg); color:var(--text-muted); font-weight:600; cursor:pointer; transition:0.2s; font-family:inherit; font-size:0.9rem; }
.chart-tab.active { background:var(--primary); color:white; border-color:var(--primary); }
.chart-wrapper { position:relative; width:100%; height:300px; }
.rev-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:15px; margin-top:25px; padding-top:20px; border-top:1px solid var(--glass-border); }
.rev-cell { text-align:center; padding:15px; background:var(--input-bg); border-radius:12px; }
.rev-cell .amount { font-size:1.4rem; font-weight:800; }
.rev-cell .label  { font-size:0.8rem; color:var(--text-muted); margin-top:4px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
.rev-cell .sub    { font-size:0.75rem; color:var(--text-muted); }

.qr-modal-content { background:var(--glass-bg); padding:40px; border-radius:20px; text-align:center; max-width:400px; width:100%; border:1px solid var(--glass-border); }
.status-select { background:transparent; color:var(--text-main); border:none; font-weight:bold; cursor:pointer; outline:none; font-size:0.9rem; }
.status-select option { background:var(--bg-grad-1); color:var(--text-main); }
.glass-input { padding:12px 15px; border-radius:10px; border:1px solid var(--glass-border); background:var(--input-bg); color:var(--text-main); font-family:inherit; font-size:1rem; }

/* ── CLOSING TIME POPUP ── */
.closing-popup {
    display:none; position:fixed; inset:0; background:rgba(15,23,42,0.85);
    z-index:10000; justify-content:center; align-items:center; backdrop-filter:blur(8px);
}
.closing-popup-box {
    background:var(--glass-bg); border:2px solid #f59e0b; border-radius:20px;
    padding:40px; max-width:460px; width:90%; text-align:center; box-shadow:0 20px 40px rgba(0,0,0,0.3);
}
.hours-form { display:grid; grid-template-columns:1fr 1fr; gap:15px; align-items:end; flex-wrap:wrap; }
@media(max-width:600px){ .hours-form { grid-template-columns:1fr; } }
</style>

<div style="max-width:1200px; margin:0 auto;">

<!-- STATS -->
<div class="stats-grid">
    <div class="stat-card"><h3><?= $total_files ?></h3><p>Total Handled</p></div>
    <div class="stat-card" style="border-bottom:3px solid var(--primary);"><h3 style="color:var(--primary);"><?= $active_files ?></h3><p>Live / Ready</p></div>
    <div class="stat-card" style="border-bottom:3px solid var(--text-muted);"><h3 style="color:var(--text-muted);"><?= $past_files ?></h3><p>Completed</p></div>
    <div class="stat-card" style="border-bottom:3px solid #f59e0b;"><h3 style="color:#f59e0b;">₹<?= $total_revenue ?></h3><p>Total Revenue</p></div>
</div>

<!-- REVENUE GRAPH -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <div>
            <h2 style="margin:0; color:var(--primary);">📊 Revenue Analytics</h2>
            <p style="margin:5px 0 0; color:var(--text-muted); font-size:0.9rem;">Track earnings from completed print jobs</p>
        </div>
        <div class="chart-toggle">
            <button class="chart-tab active" onclick="switchChart('daily',this)">📅 Daily (30d)</button>
            <button class="chart-tab"        onclick="switchChart('monthly',this)">📆 Monthly (6m)</button>
        </div>
    </div>
    <div class="chart-wrapper"><canvas id="revenueChart"></canvas></div>
    <div class="rev-summary">
        <div class="rev-cell"><div class="amount" style="color:#10b981;">₹<?= $today_revenue ?></div><div class="label">Today</div><div class="sub"><?= $today_jobs ?> job<?= $today_jobs!==1?'s':'' ?></div></div>
        <div class="rev-cell"><div class="amount" style="color:#3b82f6;">₹<?= $week_revenue ?></div><div class="label">This Week</div></div>
        <div class="rev-cell"><div class="amount" style="color:#f59e0b;">₹<?= $month_revenue ?></div><div class="label">This Month</div></div>
        <div class="rev-cell"><div class="amount" style="color:#a855f7;">₹<?= $total_revenue ?></div><div class="label">All Time</div></div>
    </div>
</div>

<!-- SHOP HOURS SETTINGS -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:24px;">
        <div>
            <h2 style="margin:0; color:var(--primary);">🕐 Shop Hours</h2>
            <p style="color:var(--text-muted); margin:5px 0 0; font-size:0.9rem;">Shop status auto-adjusts based on these hours. Customers will see your availability.</p>
        </div>
        <?php if($open_time && $close_time): ?>
        <div style="background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); border-radius:12px; padding:10px 20px; text-align:center;">
            <div style="font-weight:700; color:var(--primary);">Currently Open</div>
            <div style="font-size:0.85rem; color:var(--text-muted);"><?= date('h:i A', strtotime($open_time)) ?> – <?= date('h:i A', strtotime($close_time)) ?></div>
        </div>
        <?php endif; ?>
    </div>
    <form method="POST" class="hours-form">
        <div>
            <label style="display:block; color:var(--text-muted); font-size:0.85rem; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">Opens At</label>
            <input type="time" name="open_time" class="glass-input" value="<?= $open_time ?>" style="width:100%;" required>
        </div>
        <div>
            <label style="display:block; color:var(--text-muted); font-size:0.85rem; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">Closes At</label>
            <input type="time" name="close_time" class="glass-input" value="<?= $close_time ?>" style="width:100%;" required>
        </div>
        <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
            <button type="submit" name="update_hours" class="btn-glow">💾 Save Hours</button>
        </div>
    </form>
</div>

<!-- PRINT PRICING SETTINGS -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:24px;">
        <div>
            <h2 style="margin:0; color:var(--primary);">💰 Print Pricing</h2>
            <p style="color:var(--text-muted); margin:5px 0 0; font-size:0.9rem;">Set your per-page print rates. Customers see these prices before uploading.</p>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <div style="background:rgba(100,116,139,0.15); border:1px solid rgba(100,116,139,0.3); border-radius:10px; padding:8px 16px; text-align:center;">
                <div style="font-weight:800; color:var(--text-main); font-size:1.1rem;">₹<?= number_format((float)($owner_data['bw_price'] ?? 2.00), 2) ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">⬛ B&W /page</div>
            </div>
            <div style="background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.3); border-radius:10px; padding:8px 16px; text-align:center;">
                <div style="font-weight:800; color:#3b82f6; font-size:1.1rem;">₹<?= number_format((float)($owner_data['color_price'] ?? 10.00), 2) ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">🎨 Color /page</div>
            </div>
        </div>
    </div>
    <form method="POST" style="display:grid; grid-template-columns:1fr 1fr auto; gap:16px; align-items:end; flex-wrap:wrap;">
        <div>
            <label style="display:block; color:var(--text-muted); font-size:0.85rem; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">⬛ B&W Price (₹ per page)</label>
            <div style="position:relative;">
                <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:700; font-size:1rem; pointer-events:none;">₹</span>
                <input type="number" name="bw_price" class="glass-input" style="padding-left:30px;"
                    value="<?= number_format((float)($owner_data['bw_price'] ?? 2.00), 2) ?>"
                    min="0.50" max="100" step="0.50" required>
            </div>
        </div>
        <div>
            <label style="display:block; color:var(--text-muted); font-size:0.85rem; font-weight:600; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px;">🎨 Color Price (₹ per page)</label>
            <div style="position:relative;">
                <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-weight:700; font-size:1rem; pointer-events:none;">₹</span>
                <input type="number" name="color_price" class="glass-input" style="padding-left:30px;"
                    value="<?= number_format((float)($owner_data['color_price'] ?? 10.00), 2) ?>"
                    min="0.50" max="500" step="0.50" required>
            </div>
        </div>
        <div>
            <button type="submit" name="update_pricing" class="btn-glow">💾 Save Pricing</button>
        </div>
    </form>
    <p style="color:var(--text-muted); font-size:0.82rem; margin-top:14px; margin-bottom:0;">
        ℹ️ Cost = Price per page × Page count × Copies. Loyalty discount is applied on top automatically.
    </p>
</div>

<!-- PRINT QUEUE -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <h2 style="margin:0; color:var(--primary);">🖨️ Print Queue</h2>
        <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <form method="POST" style="display:flex; gap:10px; align-items:center; margin:0; background:var(--input-bg); padding:5px 15px; border-radius:12px; border:1px solid var(--glass-border);">
                <span style="font-weight:bold; color:var(--text-muted); font-size:0.9rem;">Status:</span>
                <select name="shop_status" class="status-select" onchange="this.form.submit()">
                    <option value="Available" <?= $current_status=='Available'?'selected':'' ?>>🟢 Available</option>
                    <option value="Busy"      <?= $current_status=='Busy'     ?'selected':'' ?>>🔴 Busy</option>
                    <option value="Offline"   <?= $current_status=='Offline'  ?'selected':'' ?>>⚪ Offline</option>
                </select>
            </form>
            <button onclick="window.location.href='owner_dashboard.php';" class="btn-outline">🔄 Refresh</button>
        </div>
    </div>

    <?php if($msg): ?>
        <div style="padding:15px; border-radius:10px; margin-bottom:20px; font-weight:600; text-align:center; background:rgba(16,185,129,0.2); color:var(--primary); border:1px solid var(--primary);"><?= $msg ?></div>
    <?php endif; ?>

    <form method="GET" class="filter-bar">
        <input type="text" name="search_active" class="filter-input" placeholder="Search queue by customer or filename..." value="<?= htmlspecialchars($search_active) ?>">
        <?php if($search_history): ?><input type="hidden" name="search_history" value="<?= htmlspecialchars($search_history) ?>"><?php endif; ?>
        <button type="submit" class="filter-btn">Search Queue</button>
        <a href="owner_dashboard.php" class="filter-btn" style="text-decoration:none; text-align:center;">Clear</a>
    </form>

    <div id="queue">
        <?php
        $sql = "SELECT p.*, u.name as cust_name, u.email, TIMESTAMPDIFF(SECOND, NOW(), p.upload_time + INTERVAL 1 HOUR) as remaining_secs
                FROM sd_print_jobs p JOIN sd_users u ON p.user_id=u.id
                WHERE p.status IN('active','ready') AND p.shop_id=$user_id";
        if (!empty($search_active)) $sql .= " AND (p.filename LIKE '%$search_active%' OR u.name LIKE '%$search_active%')";
        $sql .= " ORDER BY p.upload_time ASC";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $seconds_left = $row['remaining_secs'];
                //$display_name = preg_replace('/^[0-9]+_[0-9]+_/', '', $row['filename']);
				$display_name = preg_replace('/^([0-9]+_)+/', '', $row['filename']);
                $upload_dt    = date('d M Y, h:i A', strtotime($row['upload_time']));
                $qr_data      = "Job #{$row['id']} | {$row['cust_name']} | $upload_dt | {$row['copies']}x ".strtoupper($row['print_type'])." | ₹{$row['estimated_cost']}  ( ©SafeDoc )";

                // Build viewer watermark data (JSON-encoded for JS)
                $wm_job_id    = $row['id'];
                $wm_customer  = addslashes($row['cust_name']);
                $wm_type      = strtoupper($row['print_type']);
                $wm_copies    = $row['copies'];
                $wm_comments  = addslashes($row['comments'] ?? '');

                echo "<div class='queue-item'>
                        <div style='flex:1; min-width:250px;'>
                            <strong style='font-size:1.1rem; color:var(--text-main); word-break:break-all;'>$display_name</strong>
                            <div style='color:var(--text-muted); font-size:0.9rem; margin-top:5px;'>Customer: <span style='color:var(--accent); font-weight:600;'>{$row['cust_name']}</span></div>
                            <span class='timer' data-remaining='$seconds_left'>Calculating...</span>
                        </div>
                        <div class='instruction-box'>
                            <strong>Instructions:</strong> {$row['copies']}x " . strtoupper($row['print_type']) . " Print — <strong>{$row['page_count']} page" . ($row['page_count']!=1?'s':'') . "</strong><br>
                            <strong>Est. Cost:</strong> ₹{$row['estimated_cost']} <span style='color:var(--text-muted);font-size:0.8rem;'>({$row['page_count']} pg × {$row['copies']} cop)</span><br>";
                if (!empty($row['comments'])) echo "<span style='color:var(--accent);'><em>\"" . htmlspecialchars($row['comments']) . "\"</em></span>";
                echo "      </div>
                        <div style='display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;'>
                            <button class='btn-outline' onclick=\"openQR('" . rawurlencode($qr_data) . "')\">📱 QR</button>
                            <button class='btn-glow' style='background:var(--text-main); color:var(--bg-grad-1) !important;' onclick=\"openViewer('uploads/{$row['filename']}', {$row['id']}, '$wm_customer', '$wm_type', $wm_copies, '$wm_comments')\">👁️ View</button>";
				// Replace your existing 'mark_printed' button with this:
				if ($row['status'] === 'active') {
					echo "<form method='POST' style='margin:0;'><input type='hidden' name='job_id' value='{$row['id']}'><button type='submit' name='mark_ready' class='btn-glow' style='background:#3b82f6;'>✅ Mark Ready</button></form>";
				} else {
					echo "<span style='padding:10px 15px; background:rgba(59,130,246,0.2); color:#3b82f6; border-radius:12px; font-weight:bold;'>READY FOR PICKUP</span>";
					// Trigger the PIN modal instead of immediate submission
					echo "<button type='button' class='btn-glow' style='background:#ef4444;' onclick=\"openPinModal({$row['id']}, '{$display_name}')\">🗑️ Hand Over</button>";
				}
                echo "      </div></div>";
            }
        } else {
            echo "<p style='text-align:center; color:var(--text-muted); padding:40px 0;'>No active print jobs in your queue.</p>";
        }
        ?>
    </div>
</div>

<!-- HISTORY LOG -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <h3 style="margin-top:0; color:var(--text-muted);">📜 Shop History Log</h3>
    <form method="GET" class="filter-bar">
        <input type="text" name="search_history" class="filter-input" placeholder="Search logs by customer or filename..." value="<?= htmlspecialchars($search_history) ?>">
        <?php if($search_active): ?><input type="hidden" name="search_active" value="<?= htmlspecialchars($search_active) ?>"><?php endif; ?>
        <button type="submit" class="filter-btn">Search Logs</button>
        <a href="owner_dashboard.php" class="filter-btn" style="text-decoration:none; text-align:center;">Clear</a>
    </form>
    <div style="margin-top:20px;">
        <?php
        $exp_sql = "SELECT p.*, u.name as cust_name, UNIX_TIMESTAMP(p.upload_time) as utime FROM sd_print_jobs p JOIN sd_users u ON p.user_id=u.id WHERE p.status NOT IN('active','ready') AND p.shop_id=$user_id";
        if (!empty($search_history)) $exp_sql .= " AND (p.filename LIKE '%$search_history%' OR u.name LIKE '%$search_history%')";
        $exp_sql .= " ORDER BY p.upload_time DESC LIMIT 50";
        $exp_res = $conn->query($exp_sql);
        if ($exp_res->num_rows > 0) {
            while($row=$exp_res->fetch_assoc()) {
                //$disp = preg_replace('/^[0-9]+_[0-9]+_/', '', $row['filename']);
				$disp = preg_replace('/^([0-9]+_)+/', '', $row['filename']);
                $bc   = $row['status']==='printed' ? '#3b82f6' : '#ef4444';
                echo "<div style='padding:15px 0; border-bottom:1px solid var(--glass-border); display:flex; justify-content:space-between; align-items:center;'>
                        <div style='flex:1;'>
                            <strong style='color:var(--text-main); word-break:break-all;'>$disp</strong><br>
                            <small style='color:var(--text-muted);'>Customer: {$row['cust_name']} | {$row['page_count']} pages × {$row['copies']} copies | Est: ₹{$row['estimated_cost']} | " . date('d M Y, h:i A', $row['utime']) . "</small>
                        </div>
                        <span style='color:$bc; background:rgba(0,0,0,0.05); padding:4px 12px; border-radius:20px; font-size:0.85rem; font-weight:bold; text-transform:uppercase;'>{$row['status']}</span>
                      </div>";
            }
        } else {
            echo "<p style='color:var(--text-muted); text-align:center; padding:20px;'>No history matches your search.</p>";
        }
        ?>
    </div>
</div>

<!-- LOCATION PANEL -->
<div class="glass-panel" style="padding:40px; margin-bottom:40px;">
    <h3 style="margin-top:0; color:var(--primary);">📍 Set Shop Location</h3>
    <p style="color:var(--text-muted); margin-bottom:15px;">Drag the pin, click the map, or use your device location. Customers will find your shop on the map.</p>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>#owner-location-map{height:320px;border-radius:16px;border:2px solid var(--glass-border);z-index:1;margin-bottom:12px;}</style>
    <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
        <button type="button" id="owner-locate-btn" onclick="useMyLocationOwner()" style="font-size:0.85rem; padding:8px 16px; background:var(--glass-bg); border:1px solid var(--primary); color:var(--primary); border-radius:10px; cursor:pointer; font-weight:600;">📍 Use My Location</button>
    </div>
    <div id="owner-location-map"></div>
    <form method="POST" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap; margin-top:12px;">
        <input type="hidden" name="latitude"  id="loc-lat" value="<?= $owner_lat ?>">
        <input type="hidden" name="longitude" id="loc-lng" value="<?= $owner_lng ?>">
        <div style="flex:1; min-width:250px;">
            <label style="display:block; color:var(--text-muted); font-size:0.85rem; margin-bottom:6px; font-weight:600;">Shop Address / Label</label>
            <input type="text" name="address" class="glass-input" placeholder="e.g. Block 4, Tech Park, Kochi" value="<?= $owner_addr ?>" style="width:100%; box-sizing:border-box;">
        </div>
        <div>
            <div style="color:var(--text-muted); font-size:0.82rem; margin-bottom:6px;" id="coords-display">📌 <?= round($owner_lat,5) ?>, <?= round($owner_lng,5) ?></div>
            <button type="submit" name="update_location" class="btn-glow">💾 Save Location</button>
        </div>
    </form>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function(){
        const il=<?= $owner_lat ?>, ig=<?= $owner_lng ?>, hl=<?= ($owner_data['latitude']!==null?'true':'false') ?>;
        const lm=L.map('owner-location-map').setView([il,ig],hl?15:12);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png').addTo(lm);
        const si=L.icon({iconUrl:'https://img.icons8.com/color/48/print.png',iconSize:[40,40],iconAnchor:[20,40],popupAnchor:[0,-40]});
        const mk=L.marker([il,ig],{draggable:true,icon:si}).addTo(lm).bindPopup('Drag or click to move').openPopup();
        function uc(lat,lng){document.getElementById('loc-lat').value=lat;document.getElementById('loc-lng').value=lng;document.getElementById('coords-display').textContent='📌 '+lat.toFixed(5)+', '+lng.toFixed(5);}
        mk.on('dragend',e=>{const p=e.target.getLatLng();uc(p.lat,p.lng);});
        lm.on('click',e=>{mk.setLatLng(e.latlng);uc(e.latlng.lat,e.latlng.lng);});
        window._ownerLocMap=lm;window._ownerLocMarker=mk;window._updateOwnerCoords=uc;
        if(!hl&&"geolocation" in navigator){navigator.geolocation.getCurrentPosition(p=>{lm.setView([p.coords.latitude,p.coords.longitude],15);mk.setLatLng([p.coords.latitude,p.coords.longitude]);uc(p.coords.latitude,p.coords.longitude);});}
    })();
    function useMyLocationOwner(){
        if(!("geolocation" in navigator)){alert("Geolocation not supported.");return;}
        const b=document.getElementById('owner-locate-btn');b.textContent="⏳ Locating...";b.disabled=true;
        navigator.geolocation.getCurrentPosition(
            p=>{window._ownerLocMap.setView([p.coords.latitude,p.coords.longitude],17);window._ownerLocMarker.setLatLng([p.coords.latitude,p.coords.longitude]);window._updateOwnerCoords(p.coords.latitude,p.coords.longitude);b.textContent="✅ Set!";b.disabled=false;setTimeout(()=>{b.textContent="📍 Use My Location";},2500);},
            e=>{alert("Error: "+e.message);b.textContent="📍 Use My Location";b.disabled=false;}
        );
    }
    </script>
</div>
</div>

<!-- QR MODAL -->
<div id="qrModal" class="overlay" style="justify-content:center;">
    <div class="qr-modal-content">
        <h2 style="margin-top:0; color:var(--primary);">Job Details QR</h2>
        <p style="color:var(--text-muted); font-size:0.9rem;">Scan to view print instructions on mobile.</p>
        <img id="qrImage" style="width:200px;height:200px;margin:20px auto;background:#fff;padding:10px;border-radius:10px;display:block;" src="" alt="QR Code">
        <button class="btn-glow" style="background:#ef4444; width:100%;" onclick="document.getElementById('qrModal').style.display='none'">Close</button>
    </div>
</div>

<!-- SECURE VIEWER -->
<div id="viewer" class="overlay">
    <div style="width:100%; max-width:1200px; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <div>
            <h2 style="margin:0; color:#fff;">🔒 Secure View Mode</h2>
            <small style="color:#94a3b8;">Shop #<?= $user_id ?> &nbsp;|&nbsp; <?= $owner_addr ?></small>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <button class="btn-glow" style="background:#10b981;" onclick="printDoc()">🖨️ Print Document</button>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Mark as Ready for Pickup?');">
                <input type="hidden" name="job_id" id="viewer_job_id" value="">
                <button type="submit" name="mark_ready" class="btn-glow" style="background:#3b82f6;">✅ Mark Ready</button>
            </form>
            <button class="btn-glow" style="background:#ef4444;" onclick="closeViewer()">✕ Close</button>
        </div>
    </div>
    <div class="viewer-layout">
        <div class="nav-sidebar">
            <button class="btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.2);" onclick="document.getElementById('scroller').scrollBy(0,-500)">▲ UP</button>
            <button class="btn-outline" style="color:#fff; border-color:rgba(255,255,255,0.2);" onclick="document.getElementById('scroller').scrollBy(0,500)">▼ DOWN</button>
        </div>
        <div class="scroll-container" id="scroller">
            <div class="watermark-overlay" id="watermarkOverlay"></div>
            <div class="shield"></div>
            <iframe id="printFrame" src=""></iframe>
        </div>
    </div>
</div>

<!-- CLOSING TIME POPUP -->
<div class="closing-popup" id="closingPopup">
    <div class="closing-popup-box">
        <div style="font-size:3rem; margin-bottom:16px;">🕐</div>
        <h2 style="margin-top:0; color:#f59e0b;">Shop Closing Time Passed!</h2>
        <p id="closingPopupMsg" style="color:var(--text-muted); margin-bottom:24px;"></p>
        <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
            <button class="btn-glow" style="background:#f59e0b;" onclick="extendHours()">⏰ Keep Open</button>
            <button class="btn-glow" style="background:#ef4444;" onclick="closeShopNow()">🔒 Close Shop Now</button>
        </div>
        <p style="color:var(--text-muted); font-size:0.82rem; margin-top:16px;">You can update your closing time above if needed.</p>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// ── REVENUE CHART ──────────────────────────────────────────────────────────
const dailyLabels   = <?= json_encode($revenue_labels) ?>;
const dailyRevenue  = <?= json_encode($revenue_values) ?>;
const dailyJobs     = <?= json_encode($jobs_values) ?>;
const monthlyLabels = <?= json_encode($monthly_labels) ?>;
const monthlyValues = <?= json_encode($monthly_values) ?>;
const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
const gridColor = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.07)';
const lblColor  = isDark ? '#94a3b8' : '#475569';

const ctx = document.getElementById('revenueChart').getContext('2d');
const gradGreen = ctx.createLinearGradient(0,0,0,300);
gradGreen.addColorStop(0,'rgba(16,185,129,0.4)'); gradGreen.addColorStop(1,'rgba(16,185,129,0.02)');
let revenueChart = new Chart(ctx, {
    type:'line',
    data:{ labels:dailyLabels, datasets:[{label:'Revenue (₹)',data:dailyRevenue,borderColor:'#10b981',backgroundColor:gradGreen,borderWidth:2.5,pointBackgroundColor:'#10b981',pointRadius:3,pointHoverRadius:6,tension:0.4,fill:true,yAxisID:'y'},{label:'Jobs',data:dailyJobs,borderColor:'#3b82f6',backgroundColor:'transparent',borderWidth:1.5,borderDash:[5,4],pointBackgroundColor:'#3b82f6',pointRadius:2,pointHoverRadius:5,tension:0.4,fill:false,yAxisID:'y1'}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:lblColor,font:{family:'Outfit',size:13},usePointStyle:true}},tooltip:{backgroundColor:isDark?'#1e293b':'#fff',titleColor:isDark?'#f8fafc':'#0f172a',bodyColor:isDark?'#94a3b8':'#475569',borderColor:isDark?'rgba(255,255,255,0.1)':'rgba(0,0,0,0.1)',borderWidth:1,padding:12,callbacks:{label:c=>c.dataset.yAxisID==='y'?' ₹'+c.raw.toFixed(2):' '+c.raw+' job'+(c.raw!==1?'s':'')}}},scales:{x:{grid:{color:gridColor},ticks:{color:lblColor,font:{family:'Outfit'},maxTicksLimit:10,maxRotation:45}},y:{position:'left',grid:{color:gridColor},ticks:{color:'#10b981',font:{family:'Outfit'},callback:v=>'₹'+v}},y1:{position:'right',grid:{drawOnChartArea:false},ticks:{color:'#3b82f6',font:{family:'Outfit'}}}}}
});
function switchChart(type,btn){
    document.querySelectorAll('.chart-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    if(type==='daily'){revenueChart.data.labels=dailyLabels;revenueChart.data.datasets[0].data=dailyRevenue;revenueChart.data.datasets[1].data=dailyJobs;revenueChart.data.datasets[1].hidden=false;}
    else{revenueChart.data.labels=monthlyLabels.length?monthlyLabels:['No data'];revenueChart.data.datasets[0].data=monthlyValues.length?monthlyValues:[0];revenueChart.data.datasets[1].data=[];revenueChart.data.datasets[1].hidden=true;}
    revenueChart.update();
}

// ── WATERMARK (includes shop ID + customer print details) ──────────────────
const SHOP_ID    = <?= $user_id ?>;
let   currentWatermark = '';

function buildWatermark(text) {
    const overlay = document.getElementById('watermarkOverlay');
    overlay.innerHTML = '';
    const cols=3, rows=18, wStep=340, hStep=190;
    for(let r=0;r<rows;r++) for(let c=0;c<cols;c++) {
        const span = document.createElement('span');
        span.className = 'watermark-text';
        span.textContent = text;
        span.style.top  = (r*hStep + (c%2===0?0:70))+'px';
        span.style.left = (c*wStep-40)+'px';
        overlay.appendChild(span);
    }
}

// ── VIEWER ─────────────────────────────────────────────────────────────────
let currentFilePath = '';
function openViewer(filePath, jobId, custName, printType, copies, comments) {
    currentFilePath = filePath;
    document.getElementById('viewer_job_id').value = jobId;

    // Build detailed watermark
    let wm = `Shop #${SHOP_ID}  |  Job #${jobId}  |  ${decodeURIComponent(custName)}  |  ${copies}x ${printType}`;
    if (comments && comments.trim()) wm += `  |  ${decodeURIComponent(comments)}`;
    currentWatermark = wm;

    document.getElementById('printFrame').src = filePath + '#toolbar=0&navpanes=0&view=FitH';
    document.getElementById('viewer').style.display = 'flex';
    document.getElementById('scroller').scrollTop = 0;
    buildWatermark(currentWatermark);
}
function closeViewer() {
    document.getElementById('viewer').style.display = 'none';
    document.getElementById('printFrame').src = '';
    currentFilePath = '';
}
function printDoc() {
    if (!currentFilePath) return;
    const pf = document.createElement('iframe');
    pf.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:1px;height:1px;opacity:0;';
    pf.src = currentFilePath;
    document.body.appendChild(pf);
    pf.onload = function() {
        try { pf.contentWindow.focus(); pf.contentWindow.print(); }
        catch(e) { window.open(currentFilePath, '_blank'); }
        setTimeout(()=>{try{document.body.removeChild(pf);}catch(e){}},90000);
    };
}

// ── QR ─────────────────────────────────────────────────────────────────────
function openQR(dataStr) {
    document.getElementById('qrImage').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='+dataStr;
    document.getElementById('qrModal').style.display = 'flex';
}

// ── TIMERS ─────────────────────────────────────────────────────────────────
function updateTimers() {
    document.querySelectorAll('.timer').forEach(el => {
        let r = parseInt(el.getAttribute('data-remaining'));
        if (r <= 0) { el.innerText='EXPIRED'; el.style.color='#ef4444'; }
        else { let m=Math.floor(r/60),s=r%60; el.innerText=`Expires in: ${m}m ${s.toString().padStart(2,'0')}s`; el.setAttribute('data-remaining',r-1); }
    });
}
updateTimers(); setInterval(updateTimers,1000);

// ── CLOSING TIME ALERT ─────────────────────────────────────────────────────
<?php if($close_time && $current_status !== 'Offline'): ?>
(function() {
    const closeTimeStr = "<?= $close_time ?>";   // e.g. "18:00:00"
    const parts = closeTimeStr.split(':');
    const closeH = parseInt(parts[0]), closeM = parseInt(parts[1]);

    function checkClosingTime() {
        const now = new Date();
        const nowMins = now.getHours()*60 + now.getMinutes();
        const closeMins = closeH*60 + closeM;
        if (nowMins >= closeMins) {
            const popup = document.getElementById('closingPopup');
            if (popup.style.display === 'none' || popup.style.display === '') {
                document.getElementById('closingPopupMsg').textContent =
                    `Your shop's closing time (${closeH.toString().padStart(2,'0')}:${closeM.toString().padStart(2,'0')}) has passed. How long are you planning to keep the shop open?`;
                popup.style.display = 'flex';
            }
        }
    }
    // Check immediately and then every 2 minutes
    setTimeout(checkClosingTime, 3000);
    setInterval(checkClosingTime, 120000);
})();
<?php endif; ?>

function extendHours() {
    document.getElementById('closingPopup').style.display = 'none';
    // Scroll to hours section
    document.querySelector('.hours-form')?.scrollIntoView({behavior:'smooth', block:'center'});
}
function closeShopNow() {
    // Submit status form to set offline
    const form = document.createElement('form');
    form.method = 'POST'; form.style.display = 'none';
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='shop_status'; inp.value='Offline';
    form.appendChild(inp); document.body.appendChild(form); form.submit();
}

document.addEventListener('keydown', e => {
    if (e.ctrlKey && ['s','p','u','i'].includes(e.key.toLowerCase())) e.preventDefault();
    if (e.key === 'F12') e.preventDefault();
});
</script>

<div id="pinModal" class="overlay" style="justify-content:center; display:none;">
    <div class="qr-modal-content" style="max-width: 350px;">
        <h2 style="margin-top:0; color:#ef4444;">Verify Pickup</h2>
		<p id="pinModalFile" style="color:var(--text-muted); font-size:0.85rem; margin-bottom:20px; word-break: break-all;"></p>
        
        <form method="POST">
            <input type="hidden" name="job_id" id="pinJobId">
            <label style="display:block; color:var(--text-muted); font-size:0.75rem; font-weight:800; text-transform:uppercase; margin-bottom:8px;">Enter Customer PIN</label>
            <input type="text" name="entered_pin" maxlength="6" class="glass-input" placeholder="000000" required style="text-align:center; font-size:1.5rem; letter-spacing:8px; font-weight:bold;">
            
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="submit" name="verify_pin_btn" class="btn-glow" style="flex:1; background:#10b981; justify-content:center;">Verify & Delete</button>
                <button type="button" class="btn-outline" style="flex:1;" onclick="document.getElementById('pinModal').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPinModal(jobId, fileName) {
    document.getElementById('pinJobId').value = jobId;
    document.getElementById('pinModalFile').innerText = "Verifying: " + fileName;
    document.getElementById('pinModal').style.display = 'flex';
}
</script>

<?php require_once 'footer.php'; ?>
