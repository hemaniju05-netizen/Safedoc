<?php
// admin_dashboard.php
require_once 'config.php';

if (!isLoggedIn() || !isAdmin()) {
    header("Location: index.php"); exit;
}

$msg = ''; $msgType = '';

// ── APPROVE / REJECT OWNER ──────────────────────────────────────────────────
if (isset($_POST['approve_owner'])) {
    $oid = (int)$_POST['owner_id'];
    $conn->query("UPDATE sd_users SET is_approved=1 WHERE id=$oid AND role='owner'");
    $msg = "✅ Shop owner account approved!"; $msgType = 'success';
}
if (isset($_POST['reject_owner'])) {
    $oid = (int)$_POST['owner_id'];
    $conn->query("DELETE FROM sd_users WHERE id=$oid AND role='owner' AND is_approved=0");
    $msg = "🗑️ Pending owner account removed."; $msgType = 'error';
}

// ── PENDING OWNERS ──────────────────────────────────────────────────────────
$pending = $conn->query("SELECT id, name, email, address, created_at FROM sd_users WHERE role='owner' AND is_approved=0 ORDER BY created_at DESC");

// ── APPROVED OWNERS ──────────────────────────────────────────────────────────
$owners_sql = "SELECT u.id, u.name, u.email, u.address, u.shop_status, u.open_time, u.close_time, u.bw_price, u.color_price, u.created_at,
    COUNT(DISTINCT j.id) as total_jobs,
    ROUND(AVG(r.rating),1) as avg_rating,
    COUNT(DISTINCT r.id) as review_count
    FROM sd_users u
    LEFT JOIN sd_print_jobs j ON j.shop_id=u.id
    LEFT JOIN sd_reviews r ON r.shop_id=u.id
    WHERE u.role='owner' AND u.is_approved=1
    GROUP BY u.id ORDER BY u.name";
$owners = $conn->query($owners_sql);

// ── PLATFORM STATS ───────────────────────────────────────────────────────────
$platform_stats = $conn->query("SELECT
    (SELECT COUNT(*) FROM sd_users WHERE role='customer') as total_customers,
    (SELECT COUNT(*) FROM sd_users WHERE role='owner' AND is_approved=1) as total_owners,
    (SELECT COUNT(*) FROM sd_users WHERE role='owner' AND is_approved=0) as pending_owners,
    (SELECT COUNT(*) FROM sd_print_jobs) as total_jobs,
    (SELECT COUNT(*) FROM sd_reviews) as total_reviews
")->fetch_assoc();

$default_tab = (isset($_POST['approve_owner']) || isset($_POST['reject_owner'])) ? 'pending' : 'overview';

require_once 'header.php';
?>
<style>
.tab-bar {
    display:flex; gap:4px;
    background:var(--glass-bg);
    border:1px solid var(--glass-border);
    border-radius:16px;
    padding:6px;
    margin-bottom:28px;
    flex-wrap:wrap;
}
.tab-btn {
    flex:1; min-width:130px;
    padding:12px 20px; border-radius:12px; border:none;
    background:transparent; color:var(--text-muted);
    font-weight:700; font-size:0.95rem; cursor:pointer; transition:0.2s;
    display:flex; align-items:center; justify-content:center; gap:8px;
    white-space:nowrap;
}
.tab-btn:hover { background:var(--input-bg); color:var(--text-main); }
.tab-btn.active {
    background:linear-gradient(135deg,rgba(168,85,247,0.2),rgba(59,130,246,0.2));
    color:var(--text-main);
    border:1px solid rgba(168,85,247,0.35);
}
.tab-badge { background:#f59e0b; color:#000; border-radius:20px; padding:1px 8px; font-size:0.75rem; font-weight:800; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:28px; }
.stat-card { background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:16px; padding:22px 18px; text-align:center; }
.stat-card h3 { margin:0; font-size:2.2rem; font-weight:800; }
.stat-card p  { margin:6px 0 0; color:var(--text-muted); font-weight:600; font-size:0.8rem; text-transform:uppercase; letter-spacing:1px; }

.admin-table-wrap { overflow-x:auto; border-radius:16px; border:1px solid var(--glass-border); }
.admin-table { width:100%; border-collapse:collapse; font-size:0.9rem; }
.admin-table thead th {
    background:var(--input-bg); color:var(--text-muted);
    font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.8px;
    padding:14px 16px; text-align:left; border-bottom:1px solid var(--glass-border); white-space:nowrap;
}
.admin-table tbody tr { border-bottom:1px solid var(--glass-border); transition:background 0.15s; }
.admin-table tbody tr:last-child { border-bottom:none; }
.admin-table tbody tr:hover { background:var(--input-bg); }
.admin-table td { padding:14px 16px; color:var(--text-main); vertical-align:middle; }
.td-name { font-weight:700; }
.td-muted { color:var(--text-muted); font-size:0.85rem; }

.status-badge { padding:3px 12px; border-radius:20px; font-size:0.75rem; font-weight:700; text-transform:uppercase; white-space:nowrap; display:inline-block; }
.status-available { background:rgba(16,185,129,0.15); color:#10b981; }
.status-busy      { background:rgba(239,68,68,0.15);  color:#ef4444; }
.status-offline   { background:rgba(100,116,139,0.15); color:#64748b; }

.btn-approve { background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.5); padding:6px 14px; border-radius:8px; font-weight:700; cursor:pointer; transition:0.2s; font-size:0.85rem; white-space:nowrap; }
.btn-approve:hover { background:#10b981; color:#fff; }
.btn-reject  { background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.4); padding:6px 14px; border-radius:8px; font-weight:700; cursor:pointer; transition:0.2s; font-size:0.85rem; white-space:nowrap; }
.btn-reject:hover  { background:#ef4444; color:#fff; }

.reviews-toggle { background:var(--input-bg); border:1px solid var(--glass-border); border-radius:8px; padding:5px 12px; font-size:0.8rem; font-weight:600; cursor:pointer; color:var(--text-muted); transition:0.2s; white-space:nowrap; }
.reviews-toggle:hover { color:var(--primary); border-color:var(--primary); }
.reviews-drawer { display:none; background:var(--input-bg); border-top:1px solid var(--glass-border); }
.reviews-drawer td { padding:12px 16px; }
.review-item { padding:10px 0; border-bottom:1px solid var(--glass-border); font-size:0.88rem; }
.review-item:last-child { border-bottom:none; }

.empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
.empty-state .icon { font-size:3rem; margin-bottom:12px; }
.empty-state p { font-size:1rem; font-weight:600; }
</style>

<div style="max-width:1200px;margin:0 auto;">

<div style="margin-bottom:24px;">
    <h1 style="margin:0 0 4px;font-size:1.9rem;font-weight:800;background:linear-gradient(90deg,#a855f7,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">🛡️ Admin Control Panel</h1>
    <p style="color:var(--text-muted);margin:0;font-size:0.92rem;">Manage shop approvals, monitor platform activity, and review shop performance.</p>
</div>

<?php if($msg): ?>
<div style="padding:13px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;<?= $msgType==='success'?'background:rgba(16,185,129,0.15);color:var(--primary);border:1px solid rgba(16,185,129,0.4);':'background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.4);' ?>"><?= $msg ?></div>
<?php endif; ?>

<!-- TAB BAR -->
<div class="tab-bar">
    <button class="tab-btn" id="tab-overview" onclick="switchTab('overview')">📊 Overview</button>
    <button class="tab-btn" id="tab-pending" onclick="switchTab('pending')">
        ⏳ Pending Approvals
        <?php if($pending->num_rows > 0): ?><span class="tab-badge"><?= $pending->num_rows ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" id="tab-shops" onclick="switchTab('shops')">
        🏪 Shop Owners <span style="color:var(--text-muted);font-size:0.8rem;font-weight:600;">(<?= $owners->num_rows ?>)</span>
    </button>
</div>

<!-- ═══ TAB: OVERVIEW ═══ -->
<div class="tab-panel" id="panel-overview">
    <div class="stats-grid">
        <div class="stat-card"><h3><?= $platform_stats['total_customers'] ?></h3><p>👤 Customers</p></div>
        <div class="stat-card" style="border-bottom:3px solid #10b981;"><h3 style="color:#10b981;"><?= $platform_stats['total_owners'] ?></h3><p>🏪 Active Shops</p></div>
        <div class="stat-card" style="border-bottom:3px solid #f59e0b;"><h3 style="color:#f59e0b;"><?= $platform_stats['pending_owners'] ?></h3><p>⏳ Pending Approval</p></div>
        <div class="stat-card" style="border-bottom:3px solid #3b82f6;"><h3 style="color:#3b82f6;"><?= $platform_stats['total_jobs'] ?></h3><p>🖨️ Total Jobs</p></div>
        <div class="stat-card" style="border-bottom:3px solid #a855f7;"><h3 style="color:#a855f7;"><?= $platform_stats['total_reviews'] ?></h3><p>⭐ Total Reviews</p></div>
    </div>
    <div class="glass-panel" style="padding:28px;">
        <h2 style="margin:0 0 18px;font-size:1.1rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">Platform Summary</h2>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Metric</th><th>Value</th><th>Notes</th></tr></thead>
                <tbody>
                    <tr><td class="td-name">👤 Customers</td><td><strong><?= $platform_stats['total_customers'] ?></strong></td><td class="td-muted">Registered customer accounts</td></tr>
                    <tr><td class="td-name">🏪 Active Shops</td><td><strong style="color:#10b981;"><?= $platform_stats['total_owners'] ?></strong></td><td class="td-muted">Approved &amp; operational</td></tr>
                    <tr><td class="td-name">⏳ Pending Approvals</td><td><strong style="color:#f59e0b;"><?= $platform_stats['pending_owners'] ?></strong></td><td class="td-muted">Awaiting admin review</td></tr>
                    <tr><td class="td-name">🖨️ Total Print Jobs</td><td><strong style="color:#3b82f6;"><?= $platform_stats['total_jobs'] ?></strong></td><td class="td-muted">All time across all shops</td></tr>
                    <tr><td class="td-name">⭐ Total Reviews</td><td><strong style="color:#a855f7;"><?= $platform_stats['total_reviews'] ?></strong></td><td class="td-muted">Customer reviews submitted</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══ TAB: PENDING APPROVALS ═══ -->
<div class="tab-panel" id="panel-pending">
    <div class="glass-panel" style="padding:28px;">
        <h2 style="margin:0 0 20px;font-size:1.1rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">⏳ Pending Shop Approvals</h2>
        <?php if($pending->num_rows === 0): ?>
        <div class="empty-state"><div class="icon">✅</div><p>No pending approvals — all caught up!</p></div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>#</th><th>Shop Name</th><th>Email</th><th>Address</th><th>Registered</th><th style="text-align:center;">Actions</th></tr>
                </thead>
                <tbody>
                <?php $i=1; while($p = $pending->fetch_assoc()): ?>
                <tr>
                    <td class="td-muted"><?= $i++ ?></td>
                    <td class="td-name">🖨️ <?= htmlspecialchars($p['name']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($p['email']) ?></td>
                    <td class="td-muted"><?= htmlspecialchars($p['address'] ?: '—') ?></td>
                    <td class="td-muted" style="white-space:nowrap;"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="owner_id" value="<?= $p['id'] ?>">
                                <button type="submit" name="approve_owner" class="btn-approve">✅ Approve</button>
                            </form>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this pending account?');">
                                <input type="hidden" name="owner_id" value="<?= $p['id'] ?>">
                                <button type="submit" name="reject_owner" class="btn-reject">✕ Reject</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ TAB: SHOP OWNERS ═══ -->
<div class="tab-panel" id="panel-shops">
    <div class="glass-panel" style="padding:28px;">
        <h2 style="margin:0 0 20px;font-size:1.1rem;font-weight:800;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;">🏪 Approved Shop Owners</h2>
        <?php if($owners->num_rows === 0): ?>
        <div class="empty-state"><div class="icon">🏪</div><p>No approved shops yet.</p></div>
        <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th><th>Shop Name</th><th>Email</th><th>Status</th>
                        <th>Hours</th><th>B&amp;W</th><th>Color</th>
                        <th>Jobs</th><th>Rating</th><th>Reviews</th><th style="text-align:center;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i=1; while($o = $owners->fetch_assoc()):
                    switch($o['shop_status']) {
                        case 'Available': $sc='status-available'; break;
                        case 'Busy':      $sc='status-busy';      break;
                        default:          $sc='status-offline';   break;
                    }
                    $stars = $o['avg_rating']
                        ? str_repeat('★', round($o['avg_rating'])).str_repeat('☆', 5-round($o['avg_rating']))
                        : '—';
                    $has_reviews = (int)$o['review_count'] > 0;
                ?>
                <tr>
                    <td class="td-muted"><?= $i++ ?></td>
                    <td class="td-name">🖨️ <?= htmlspecialchars($o['name']) ?></td>
                    <td class="td-muted" style="font-size:0.83rem;"><?= htmlspecialchars($o['email']) ?></td>
                    <td><span class="status-badge <?= $sc ?>"><?= $o['shop_status'] ?></span></td>
                    <td class="td-muted" style="white-space:nowrap;font-size:0.83rem;">
                        <?php if($o['open_time'] && $o['close_time']): ?>
                            <?= date('h:i A', strtotime($o['open_time'])) ?> – <?= date('h:i A', strtotime($o['close_time'])) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-weight:700;">₹<?= number_format((float)($o['bw_price']??2),2) ?></td>
                    <td style="font-weight:700;">₹<?= number_format((float)($o['color_price']??10),2) ?></td>
                    <td style="color:#10b981;font-weight:800;"><?= $o['total_jobs'] ?></td>
                    <td style="white-space:nowrap;">
                        <span style="color:#f59e0b;font-size:0.88rem;"><?= $stars ?></span><br>
                        <span style="font-size:0.76rem;color:var(--text-muted);"><?= $o['avg_rating'] ? $o['avg_rating'].'/5' : 'No ratings' ?></span>
                    </td>
                    <td style="color:#a855f7;font-weight:800;"><?= $o['review_count'] ?></td>
                    <td style="text-align:center;">
                        <?php if($has_reviews): ?>
                        <button class="reviews-toggle" onclick="toggleReviews(<?= $o['id'] ?>, this)">👁 Reviews</button>
                        <?php else: ?><span class="td-muted" style="font-size:0.8rem;">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php if($has_reviews):
                    $shop_reviews = $conn->query("SELECT r.rating, r.comment, r.created_at, u.name as cust_name
                        FROM sd_reviews r JOIN sd_users u ON r.customer_id=u.id
                        WHERE r.shop_id={$o['id']} ORDER BY r.created_at DESC LIMIT 5");
                ?>
                <tr class="reviews-drawer" id="reviews-<?= $o['id'] ?>">
                    <td colspan="11" style="padding:16px 20px;">
                        <div style="font-weight:700;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:10px;">⭐ Reviews — <?= htmlspecialchars($o['name']) ?></div>
                        <?php while($rev = $shop_reviews->fetch_assoc()):
                            $rv = str_repeat('★',$rev['rating']).str_repeat('☆',5-$rev['rating']);
                        ?>
                        <div class="review-item">
                            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
                                <div>
                                    <strong style="color:var(--text-main);"><?= htmlspecialchars($rev['cust_name']) ?></strong>
                                    <span style="color:#f59e0b;margin-left:8px;"><?= $rv ?></span>
                                    <?php if($rev['comment']): ?>
                                    <div style="color:var(--text-muted);font-size:0.84rem;margin-top:3px;">"<?= htmlspecialchars($rev['comment']) ?>"</div>
                                    <?php endif; ?>
                                </div>
                                <small style="color:var(--text-muted);white-space:nowrap;"><?= date('d M Y', strtotime($rev['created_at'])) ?></small>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /wrapper -->

<script>
const DEFAULT_TAB = '<?= $default_tab ?>';

function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.getElementById('panel-' + name).classList.add('active');
    sessionStorage.setItem('adminTab', name);
}

function toggleReviews(shopId, btn) {
    const drawer = document.getElementById('reviews-' + shopId);
    const isHidden = drawer.style.display !== 'table-row';
    drawer.style.display = isHidden ? 'table-row' : 'none';
    btn.textContent = isHidden ? '✕ Close' : '👁 Reviews';
}

const savedTab = sessionStorage.getItem('adminTab') || DEFAULT_TAB;
switchTab(savedTab);
</script>

<?php require_once 'footer.php'; ?>
