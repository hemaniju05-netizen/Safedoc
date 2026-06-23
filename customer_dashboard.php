<?php
// customer_dashboard.php — SafeDoc v3
require_once 'config.php';

if (!isLoggedIn()) { header("Location: auth.php"); exit; }

$msg = ""; $msgType = "";
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Customer';
$target_dir = "uploads/";

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
    file_put_contents($target_dir."index.php","<?php header('Location: ../index.php'); exit; ?>");
}

// ── HANDLE DELETE ───────────────────────────────────────────────────────────
if (isset($_POST['delete_file'])) {
    $del_id = (int)$_POST['job_id'];
    $check  = $conn->query("SELECT filename FROM sd_print_jobs WHERE id=$del_id AND user_id=$user_id");
    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $fp  = $target_dir . $row['filename'];
        if (file_exists($fp)) unlink($fp);
        $conn->query("DELETE FROM sd_print_jobs WHERE id=$del_id");
        $msg = "🗑️ Document permanently deleted."; $msgType = "success";
    }
}

// ── HANDLE REVIEW ───────────────────────────────────────────────────────────
if (isset($_POST['submit_review'])) {
    $job_id  = (int)$_POST['review_job_id'];
    $shop_id = (int)$_POST['review_shop_id'];
    $rating  = max(1, min(5,(int)$_POST['rating']));
    $comment = $conn->real_escape_string(trim($_POST['comment']??''));
    $check   = $conn->query("SELECT id FROM sd_print_jobs WHERE id=$job_id AND user_id=$user_id AND status='printed'");
    if ($check->num_rows > 0) {
        $conn->query("INSERT INTO sd_reviews (customer_id,shop_id,job_id,rating,comment) VALUES ($user_id,$shop_id,$job_id,$rating,'$comment') ON DUPLICATE KEY UPDATE rating=$rating, comment='$comment'");
        $msg = "⭐ Thank you for your review!"; $msgType = "success";
    }
}

// ── HANDLE UPLOAD ────────────────────────────────────────────────────────────
if (isset($_POST['upload'])) {
    date_default_timezone_set('Asia/Kolkata');
    $shop_id      = (int)$_POST['shop_id'];
    $print_type   = in_array($_POST['print_type'],['bw','color']) ? $_POST['print_type'] : 'bw';
    $copies       = max(1, min(50,(int)$_POST['copies']));
    $comments     = $conn->real_escape_string($_POST['comments']??'');
    // ── Fetch shop-specific pricing ──────────────────────────────────────────
    $shop_pricing = $conn->query("SELECT bw_price, color_price FROM sd_users WHERE id=$shop_id AND role='owner' AND is_approved=1")->fetch_assoc();
    $rate_per_page = ($print_type === 'color')
        ? (float)($shop_pricing['color_price'] ?? 10.00)
        : (float)($shop_pricing['bw_price']    ?? 2.00);

    // Verify shop is approved
    $shopCheck = $conn->query("SELECT id FROM sd_users WHERE id=$shop_id AND role='owner' AND is_approved=1");
    if ($shop_id == 0 || $shopCheck->num_rows == 0 || empty($_FILES['doc']['name'][0])) {
        $msg = "Please select a valid file and destination shop."; $msgType = "error";
    } else {
        $uploaded = 0; $errors = [];
        $files = $_FILES['doc'];
        $file_count = min(count($files['name']), 3);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig_name = $files['name'][$i];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') { $errors[] = "$orig_name is not a PDF."; continue; }
            if ($files['size'][$i] > 20*1024*1024) { $errors[] = "$orig_name too large (max 20MB)."; continue; }

            $ts        = date("YmdHis")."_$i";
            $safe_orig = preg_replace("/[^a-zA-Z0-9]/","_",pathinfo($orig_name,PATHINFO_FILENAME));
            $file_name = "{$ts}_{$user_id}_{$safe_orig}.pdf";
            $dest      = $target_dir . $file_name;
            if (!move_uploaded_file($files['tmp_name'][$i], $dest)) { $errors[] = "Failed to save $orig_name."; continue; }

            // ── PAGE COUNT → COST ────────────────────────────────────────────
            $page_count = getPDFPageCount($dest);
            $base_cost  = $rate_per_page * $page_count * $copies;
            $final_cost = $base_cost;

            $pin  = generatePIN();
            $stmt = $conn->prepare("INSERT INTO sd_print_jobs (user_id,shop_id,filename,original_name,print_type,copies,page_count,comments,estimated_cost,pin_code,status) VALUES (?,?,?,?,?,?,?,?,?,?,'active')");
            $stmt->bind_param("iisssiidss",$user_id,$shop_id,$file_name,$orig_name,$print_type,$copies,$page_count,$comments,$final_cost,$pin);
            $stmt->execute();
            $job_id = $conn->insert_id;
            $today  = date('Y-m-d');
            $conn->query("UPDATE sd_users SET total_jobs=total_jobs+1 WHERE id=$user_id");
            $uploaded++;
        }
        if ($uploaded > 0) {
            $msg = "✔ $uploaded document(s) securely sent! Cost calculated per page. Your PIN is shown below.";
            $msgType = "success";
            if (!empty($errors)) $msg .= " ⚠️ Skipped: ".implode(', ',$errors);
        } else {
            $msg = "Upload failed. ".implode(' ',$errors); $msgType = "error";
        }
    }
}

// ── STATS ────────────────────────────────────────────────────────────────────
$stats = $conn->query("SELECT COUNT(*) as total, SUM(status IN('active','ready')) as active_count, SUM(status NOT IN('active','ready')) as past_count FROM sd_print_jobs WHERE user_id=$user_id")->fetch_assoc();
$total_files  = $stats['total']        ?? 0;
$active_files = $stats['active_count'] ?? 0;
$past_files   = $stats['past_count']   ?? 0;
$discount_pct = 0;
$jobs_to_next = 0;

$search_query  = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';

require_once 'header.php';
?>

<style>
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:20px; margin-bottom:30px; }
.stat-card { background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:16px; padding:20px; text-align:center; }
.stat-card h3 { margin:0; font-size:2rem; } .stat-card p { margin:5px 0 0; color:var(--text-muted); font-weight:600; font-size:0.9rem; text-transform:uppercase; letter-spacing:1px; }

.upload-layout { display:flex; gap:30px; flex-wrap:wrap; align-items:stretch; }
.upload-zone { flex:1; min-width:280px; border:2px dashed var(--glass-border); padding:30px; border-radius:20px; text-align:center; background:rgba(0,0,0,0.02); transition:0.3s; display:flex; flex-direction:column; justify-content:center; align-items:center; }
.upload-zone:hover { border-color:var(--primary); background:var(--primary-glow); }
.print-options { flex:1; min-width:280px; background:var(--input-bg); padding:30px; border-radius:20px; border:1px solid var(--glass-border); }
.glass-input,.glass-select,.glass-textarea { width:100%; padding:12px 15px; margin-bottom:15px; background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:10px; color:var(--text-main); font-family:inherit; font-size:0.95rem; box-sizing:border-box; }
.glass-input:focus,.glass-select:focus,.glass-textarea:focus { outline:none; border-color:var(--primary); }
.cost-display { font-size:1.5rem; font-weight:800; color:var(--primary); margin-top:10px; }
input[type="file"] { display:none; }
.custom-file-upload { display:inline-block; padding:12px 28px; cursor:pointer; background:var(--glass-bg); color:var(--primary); border:2px solid var(--primary); border-radius:12px; font-weight:600; margin-bottom:15px; transition:0.3s; }
.custom-file-upload:hover { background:var(--primary); color:#fff; }

.progress-bar-wrap { flex:1; background:rgba(255,255,255,0.08); border-radius:999px; height:8px; margin-top:6px; min-width:100px; }
.progress-bar-fill { height:8px; border-radius:999px; background:linear-gradient(90deg,var(--primary),var(--accent)); transition:width 0.6s; }

.filter-bar { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
.filter-input { flex:1; min-width:200px; padding:12px 15px; border-radius:10px; border:1px solid var(--glass-border); background:var(--glass-bg); color:var(--text-main); }
.filter-btn { background:var(--glass-border); color:var(--text-main); border:none; padding:12px 20px; border-radius:10px; font-weight:600; cursor:pointer; transition:0.3s; }
.filter-btn:hover { background:var(--primary); color:white; }
.history-item { display:flex; justify-content:space-between; align-items:center; padding:15px 20px; border-bottom:1px solid var(--glass-border); gap:15px; flex-wrap:wrap; }
.history-item:last-child { border-bottom:none; }
.badge-active  { background:rgba(16,185,129,0.2); color:var(--primary); padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; text-transform:uppercase; }
.badge-ready   { background:rgba(59,130,246,0.2); color:#3b82f6; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; text-transform:uppercase; box-shadow:0 0 10px rgba(59,130,246,0.4); animation:pulse 2s infinite; }
.badge-expired { background:rgba(239,68,68,0.2); color:#ef4444; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; text-transform:uppercase; }
.badge-printed { background:rgba(148,163,184,0.2); color:#94a3b8; padding:4px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; text-transform:uppercase; }
.btn-delete { background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.3); padding:6px 12px; border-radius:8px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:0.2s; }
.btn-delete:hover { background:#ef4444; color:white; }
.pin-pill { display:inline-block; font-size:1.4rem; font-weight:800; letter-spacing:6px; color:var(--primary); background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); border-radius:10px; padding:4px 14px; font-family:monospace; }
.btn-review { background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.4); padding:7px 16px; border-radius:9px; font-size:0.85rem; font-weight:600; cursor:pointer; transition:0.2s; }
.btn-review:hover { background:#f59e0b; color:#000; }
.review-modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.85); z-index:9999; justify-content:center; align-items:center; backdrop-filter:blur(6px); }
.review-modal-box { background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:20px; padding:36px; max-width:480px; width:90%; box-shadow:0 20px 40px rgba(0,0,0,0.3); }
.shop-card-row { display:flex; justify-content:space-between; align-items:center; padding:8px 12px; border-radius:10px; background:var(--input-bg); margin-bottom:6px; cursor:pointer; border:2px solid transparent; transition:0.2s; }
.shop-card-row:hover { border-color:var(--primary); }
.shop-card-row.selected { border-color:var(--primary); background:var(--primary-glow); }
@keyframes pulse { 0%{opacity:1}50%{opacity:0.6}100%{opacity:1} }
</style>

<div class="glass-panel" style="padding:40px; max-width:1000px; margin:0 auto;">

    <!-- STATS -->
    <div class="stats-grid">
        <div class="stat-card"><h3><?= $total_files ?></h3><p>Total Uploads</p></div>
        <div class="stat-card" style="border-bottom:3px solid var(--primary);"><h3 style="color:var(--primary);"><?= $active_files ?></h3><p>Active / Ready</p></div>
        <div class="stat-card" style="border-bottom:3px solid var(--text-muted);"><h3 style="color:var(--text-muted);"><?= $past_files ?></h3><p>Past History</p></div>
    </div>

    <?php if($msg): ?>
    <div style="padding:15px; border-radius:10px; margin-bottom:20px; font-weight:600; text-align:center; <?= $msgType==='error'?'background:rgba(239,68,68,0.2);color:#ef4444;border:1px solid #ef4444;':'background:rgba(16,185,129,0.2);color:var(--primary);border:1px solid var(--primary);' ?>"><?= $msg ?></div>
    <?php endif; ?>

    <h2 style="color:var(--primary); margin-top:10px;">📤 Send to Shop</h2>

    <form method="POST" enctype="multipart/form-data" class="upload-layout">
        <div class="upload-zone" id="dropZoneArea">
            <div style="font-size:2.5rem; margin-bottom:10px;">📄</div>
            <label for="fileInput" class="custom-file-upload">📂 Choose PDF Files</label>
            <input type="file" name="doc[]" id="fileInput" accept="application/pdf" multiple required onchange="handleFiles(this)">
            <div id="file-chosen" style="color:var(--text-muted); font-size:0.9rem; margin-bottom:12px;">No file chosen (max 3 PDFs)</div>
            <div id="file-list" style="width:100%; text-align:left;"></div>
            <!-- Page counting status -->
            <div id="page-count-status" style="display:none; width:100%; margin-top:10px; padding:10px 14px; background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); border-radius:10px; font-size:0.85rem; color:var(--primary); text-align:left;"></div>
            <button type="submit" name="upload" class="btn-glow" style="width:100%; margin-top:16px; justify-content:center;">🔒 Send Securely</button>
        </div>

        <div class="print-options">
            <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:8px;">Select Destination Shop:</label>
            <div style="margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                <small style="color:var(--text-muted);">Sorted by distance from your location</small>
                <button type="button" onclick="sortByDistance()" style="font-size:0.75rem; padding:4px 10px; background:var(--glass-bg); border:1px solid var(--primary); color:var(--primary); border-radius:8px; cursor:pointer; font-weight:600;">📍 Near Me</button>
            </div>

            <select name="shop_id" id="shop_select" class="glass-select" required>
                <option value="" disabled selected>-- Choose a Shop --</option>
                <?php
                $owners = $conn->query("SELECT id, name, shop_status, latitude, longitude, open_time, close_time, bw_price, color_price FROM sd_users WHERE role='owner' AND is_approved=1 ORDER BY shop_status='Available' DESC, name");
                while($owner = $owners->fetch_assoc()) {
					// Universal PHP version compatible logic
					switch($owner['shop_status']) {
						case 'Available': 
							$icon = '🟢'; 
							break;
						case 'Busy':      
							$icon = '🔴'; 
							break;
						default:          
							$icon = '⚪'; 
							break;
					}
                    $hours = ($owner['open_time'] && $owner['close_time']) ? ' ('.date('h:iA',strtotime($owner['open_time'])).'-'.date('h:iA',strtotime($owner['close_time'])).')' : '';
                    $bwp   = number_format((float)($owner['bw_price']    ?? 2.00), 2);
                    $clrp  = number_format((float)($owner['color_price'] ?? 10.00), 2);
                    echo "<option value='{$owner['id']}' data-lat='{$owner['latitude']}' data-lng='{$owner['longitude']}' data-bw='{$bwp}' data-color='{$clrp}'>{$icon} {$owner['name']} ({$owner['shop_status']}){$hours} — B&W ₹{$bwp}/Color ₹{$clrp}</option>";
                }
                ?>
            </select>
            <!-- Shop pricing badge — populated by JS when shop selected -->
            <div id="shop-pricing-badge" style="display:none; margin-top:8px; padding:8px 14px; background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); border-radius:10px; font-size:0.82rem; color:var(--text-muted); gap:8px; align-items:center;"></div>

            <div style="display:flex; gap:15px;">
                <div style="flex:1;">
                    <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:5px;">Print Type:</label>
                    <select name="print_type" id="print_type" class="glass-select" onchange="calculateCost()">
                        <option value="bw">Black &amp; White (₹2/page)</option>
                        <option value="color">Color Print (₹10/page)</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:5px;">Copies:</label>
                    <input type="number" name="copies" id="copies" class="glass-input" value="1" min="1" max="50" onchange="calculateCost()">
                </div>
            </div>

            <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:5px;">Special Instructions (Optional):</label>
            <textarea name="comments" class="glass-textarea" rows="2" placeholder="e.g., Double-sided, staple top left..."></textarea>

            <!-- COST BREAKDOWN PANEL -->
            <div style="border-top:1px solid var(--glass-border); padding-top:15px; margin-top:5px;">

                <!-- Per-file breakdown (shown when pages are counted) -->
                <div id="cost-breakdown" style="display:none; margin-bottom:12px; font-size:0.85rem;">
                    <div style="color:var(--text-muted); font-weight:700; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.5px; font-size:0.78rem;">Cost Breakdown</div>
                    <div id="breakdown-rows"></div>
                </div>

                <!-- Totals row -->
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div>
                        <div style="color:var(--text-muted); font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Estimated Total</div>
                        <div id="formula-label" style="color:var(--text-muted); font-size:0.78rem; margin-top:3px;">Pages × Copies × Rate</div>
                    </div>
                    <div style="text-align:right;">
                        <div class="cost-display" id="cost_display">—</div>
                        <div id="orig_price_label" style="color:var(--text-muted); font-size:0.75rem; margin-top:2px;"></div>
                    </div>
                </div>

                <!-- Pricing legend -->
                <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
                    <span style="font-size:0.75rem; background:var(--input-bg); border:1px solid var(--glass-border); border-radius:8px; padding:4px 10px; color:var(--text-muted);">⚫ B&amp;W: ₹2/page</span>
                    <span style="font-size:0.75rem; background:var(--input-bg); border:1px solid var(--glass-border); border-radius:8px; padding:4px 10px; color:var(--text-muted);">🎨 Color: ₹10/page</span>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- DOCUMENT MANAGEMENT -->
<div class="glass-panel" style="padding:40px; max-width:1000px; margin:40px auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:20px;">
        <h3 style="margin:0; color:var(--accent); font-size:1.5rem;">🔍 Document Management</h3>
        <a href="customer_dashboard.php?export_csv=1" style="background:transparent; color:var(--accent); border:1px solid var(--accent); border-radius:10px; padding:10px 18px; font-weight:600; text-decoration:none; font-size:0.9rem;">📥 Export CSV</a>
    </div>

    <form method="GET" class="filter-bar">
        <input type="text" name="search" class="filter-input" placeholder="Search by filename..." value="<?= htmlspecialchars($search_query) ?>">
        <select name="status" class="filter-input" style="max-width:200px;">
            <option value="">All Statuses</option>
            <option value="active"  <?= $status_filter=='active'  ?'selected':'' ?>>Active in Queue</option>
            <option value="ready"   <?= $status_filter=='ready'   ?'selected':'' ?>>Ready for Pickup</option>
            <option value="printed" <?= $status_filter=='printed' ?'selected':'' ?>>Completed</option>
            <option value="expired" <?= $status_filter=='expired' ?'selected':'' ?>>Expired</option>
        </select>
        <button type="submit" class="filter-btn">Filter</button>
        <a href="customer_dashboard.php" class="filter-btn" style="text-decoration:none; text-align:center;">Clear</a>
    </form>

    <?php
    if (isset($_GET['export_csv'])) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="safedoc_history_'.date('Ymd').'.csv"');
        $out = fopen('php://output','w');
        fputcsv($out,['Job ID','Filename','Shop','Print Type','Pages','Copies','Est. Cost (₹)','Status','Upload Date']);
        $csv_res = $conn->query("SELECT p.id, p.original_name, p.filename, s.name as shop, p.print_type, p.page_count, p.copies, p.estimated_cost, p.status, p.upload_time FROM sd_print_jobs p LEFT JOIN sd_users s ON p.shop_id=s.id WHERE p.user_id=$user_id ORDER BY p.upload_time DESC");
        while($r=$csv_res->fetch_assoc()){$disp=$r['original_name']?:preg_replace('/^[0-9]+_[0-9]+_/','',$r['filename']);fputcsv($out,[$r['id'],$disp,$r['shop'],strtoupper($r['print_type']),$r['page_count'],$r['copies'],$r['estimated_cost'],$r['status'],$r['upload_time']]);}
        fclose($out); exit;
    }
    ?>

    <div style="margin-top:20px;">
    <?php
    $sql = "SELECT p.*, s.name as shop_name, UNIX_TIMESTAMP(p.upload_time) as utime,
                   r.id as review_id, r.rating as my_rating, r.comment as my_comment
            FROM sd_print_jobs p
            LEFT JOIN sd_users s ON p.shop_id=s.id
            LEFT JOIN sd_reviews r ON r.job_id=p.id AND r.customer_id=$user_id
            WHERE p.user_id=$user_id";
    if (!empty($search_query)) $sql .= " AND (p.original_name LIKE '%$search_query%' OR p.filename LIKE '%$search_query%')";
    if (!empty($status_filter)) $sql .= " AND p.status='$status_filter'";
    $sql .= " ORDER BY p.upload_time DESC LIMIT 50";
    $history = $conn->query($sql);

    if ($history->num_rows > 0) {
        while($row = $history->fetch_assoc()) {
            $disp_name = $row['original_name'] ?: preg_replace('/^[0-9]+_[0-9]+_/','',$row['filename']);
            //$sc = match($row['status']){'active'=>'badge-active','ready'=>'badge-ready','printed'=>'badge-printed',default=>'badge-expired'};
			switch($row['status']) {
				case 'active':  $sc = 'badge-active'; break;
				case 'ready':   $sc = 'badge-ready'; break;
				case 'printed': $sc = 'badge-printed'; break;
				default:        $sc = 'badge-expired'; break;
			}
            $is_del = in_array($row['status'],['active','ready']);
            $queue_pos_html = '';
            if ($row['status']==='active') {
                $qr   = $conn->query("SELECT COUNT(*) as c FROM sd_print_jobs WHERE shop_id={$row['shop_id']} AND status='active' AND upload_time<'{$row['upload_time']}'");
                $ahead= (int)($qr->fetch_assoc()['c']??0);
                $qcls = $ahead===0?'color:var(--primary)':'color:#3b82f6';
                $qtxt = $ahead===0?'🟢 Next in Queue!':"⏳ {$ahead} job(s) ahead";
                $queue_pos_html = "<span style='font-size:0.78rem;font-weight:700;margin-left:6px;$qcls'>$qtxt</span>";
            }
            echo "<div class='history-item'>
                    <div style='flex:1;'>
                        <strong style='color:var(--text-main);word-break:break-all;'>📄 $disp_name</strong>
                        <div style='color:var(--text-muted);font-size:0.85rem;margin-top:4px;'>
                            Sent to: <span style='color:var(--accent);'>{$row['shop_name']}</span> | ".date('d M Y, h:i A',$row['utime'])."
                            <br><small>Instructions: {$row['copies']}x ".strtoupper($row['print_type'])." — <strong style='color:var(--text-main);'>{$row['page_count']} page".($row['page_count']!=1?'s':'')."</strong> — Est: ₹{$row['estimated_cost']}</small>
                            $queue_pos_html
                        </div>";
            if (in_array($row['status'],['active','ready']) && $row['pin_code']) {
                echo "<div style='margin-top:8px;'>Pickup PIN: <span class='pin-pill'>{$row['pin_code']}</span></div>";
            }
            if ($row['status']==='printed') {
                if ($row['review_id']) {
                    $stars = str_repeat('★',$row['my_rating']).str_repeat('☆',5-$row['my_rating']);
                    echo "<div style='margin-top:8px;font-size:0.85rem;color:var(--text-muted);'>Your review: <span style='color:#f59e0b;font-size:1rem;'>$stars</span>".($row['my_comment']?"<em style='color:var(--text-muted);'> — ".htmlspecialchars($row['my_comment'])."</em>":"")."<button class='btn-review' style='margin-left:8px;padding:3px 10px;font-size:0.78rem;' onclick=\"openReview({$row['id']},{$row['shop_id']},'".htmlspecialchars(addslashes($row['shop_name']))."',{$row['my_rating']},".json_encode($row['my_comment']??'').")\">Edit</button></div>";
                } else {
                    echo "<div style='margin-top:8px;'><button class='btn-review' onclick=\"openReview({$row['id']},{$row['shop_id']},'".htmlspecialchars(addslashes($row['shop_name']))."',0,'')\">⭐ Leave a Review</button></div>";
                }
            }
            echo "</div><div style='text-align:right;min-width:100px;'><span class='$sc' style='display:block;margin-bottom:10px;'>".strtoupper($row['status'])."</span>";
            if ($is_del) {
                echo "<form method='POST' style='margin:0;' onsubmit=\"return confirm('Delete this file permanently?');\">\n<input type='hidden' name='job_id' value='{$row['id']}'>\n<button type='submit' name='delete_file' class='btn-delete'>Cancel &amp; Delete</button></form>";
            }
            echo "</div></div>";
        }
    } else {
        echo "<p style='color:var(--text-muted);text-align:center;padding:20px;'>No documents found.</p>";
    }
    ?>
    </div>
</div>

<!-- MY REVIEWS -->
<?php
$my_reviews = $conn->query("SELECT r.*, s.name as shop_name FROM sd_reviews r JOIN sd_users s ON r.shop_id=s.id WHERE r.customer_id=$user_id ORDER BY r.created_at DESC LIMIT 20");
if ($my_reviews->num_rows > 0):
?>
<div class="glass-panel" style="padding:40px; max-width:1000px; margin:40px auto;">
    <h3 style="margin-top:0; color:var(--primary);">⭐ My Reviews</h3>
    <?php while($rev=$my_reviews->fetch_assoc()):
        $stars=str_repeat('★',$rev['rating']).str_repeat('☆',5-$rev['rating']);
    ?>
    <div style="padding:14px 0; border-bottom:1px solid var(--glass-border);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <strong><?= htmlspecialchars($rev['shop_name']) ?></strong>
                <span style="color:#f59e0b; margin-left:8px;"><?= $stars ?></span>
            </div>
            <small style="color:var(--text-muted);"><?= date('d M Y',strtotime($rev['created_at'])) ?></small>
        </div>
        <?php if($rev['comment']): ?>
        <p style="margin:6px 0 0; color:var(--text-muted); font-size:0.9rem;"><?= htmlspecialchars($rev['comment']) ?></p>
        <?php endif; ?>
    </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<!-- REVIEW MODAL -->
<div id="reviewModal" class="review-modal">
    <div class="review-modal-box">
        <h2 style="margin-top:0; color:var(--primary);">⭐ Rate Your Experience</h2>
        <p id="reviewShopLabel" style="color:var(--text-muted); margin-bottom:16px;"></p>
        <form method="POST">
            <input type="hidden" name="review_job_id"  id="reviewJobId">
            <input type="hidden" name="review_shop_id" id="reviewShopId">
            <input type="hidden" name="rating"         id="ratingInput" value="0">
            <div style="margin-bottom:16px;">
                <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:8px;">Your Rating:</label>
                <div style="display:flex; gap:4px;" id="starRow">
                    <span class="star" data-val="1" style="font-size:1.6rem; cursor:pointer;">☆</span>
                    <span class="star" data-val="2" style="font-size:1.6rem; cursor:pointer;">☆</span>
                    <span class="star" data-val="3" style="font-size:1.6rem; cursor:pointer;">☆</span>
                    <span class="star" data-val="4" style="font-size:1.6rem; cursor:pointer;">☆</span>
                    <span class="star" data-val="5" style="font-size:1.6rem; cursor:pointer;">☆</span>
                </div>
            </div>
            <label style="color:var(--text-muted); font-weight:600; font-size:0.85rem; display:block; margin-bottom:6px;">Comment (optional):</label>
            <textarea name="comment" id="reviewComment" class="glass-textarea" rows="3" placeholder="How was the experience?"></textarea>
            <div style="display:flex; gap:12px; margin-top:8px;">
                <button type="submit" name="submit_review" class="btn-glow" style="flex:1; justify-content:center;">Submit Review</button>
                <button type="button" class="btn-outline" style="flex:0 0 auto;" onclick="closeReview()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
// ── PDF.js setup ──────────────────────────────────────────────────────────
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

// Default rates — updated dynamically when shop is selected
let RATE_BW    = 2;
let RATE_COLOR = 10;

// Update rates when shop changes
function updateShopRates() {
    const sel = document.getElementById('shop_select');
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.bw) {
        RATE_BW    = parseFloat(opt.dataset.bw)    || 2;
        RATE_COLOR = parseFloat(opt.dataset.color)  || 10;
        // Show pricing badge
        const badge = document.getElementById('shop-pricing-badge');
        if (badge) {
            badge.innerHTML = `⬛ B&W: <strong>₹${RATE_BW}/page</strong> &nbsp;|&nbsp; 🎨 Color: <strong>₹${RATE_COLOR}/page</strong>`;
            badge.style.display = 'flex';
        }
    }
    calculateCost();
}
document.getElementById('shop_select').addEventListener('change', updateShopRates);

// Stores page counts per file index after PDF.js analysis
let filePagesMap = {};     // { fileIndex: pageCount }
let filesSelected = [];    // File objects in order

// ── FILE PICKER ───────────────────────────────────────────────────────────
function handleFiles(input) {
    filesSelected  = Array.from(input.files).slice(0, 3);
    filePagesMap   = {};

    const chosen = document.getElementById('file-chosen');
    const list   = document.getElementById('file-list');
    const status = document.getElementById('page-count-status');

    if (filesSelected.length === 0) {
        chosen.textContent = 'No file chosen (max 3 PDFs)';
        list.innerHTML = '';
        status.style.display = 'none';
        resetCost();
        return;
    }

    chosen.textContent = `${filesSelected.length} file(s) selected — counting pages…`;
    status.style.display = 'block';
    status.innerHTML = '⏳ Reading page counts…';
    list.innerHTML = '';

    // Render placeholder rows immediately
    filesSelected.forEach((f, i) => {
        list.innerHTML += `
        <div id="file-row-${i}" style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--input-bg);border-radius:10px;margin-bottom:6px;font-size:0.85rem;">
            <span>📄</span>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-main);">${f.name}</span>
            <span style="color:var(--text-muted);font-size:0.75rem;">${(f.size/1024/1024).toFixed(1)}MB</span>
            <span id="pages-badge-${i}" style="background:var(--glass-border);color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.75rem;font-weight:700;white-space:nowrap;">…</span>
        </div>`;
    });

    // Count pages for each PDF via PDF.js
    const promises = filesSelected.map((file, i) =>
        countPDFPages(file).then(n => {
            filePagesMap[i] = n;
            const badge = document.getElementById(`pages-badge-${i}`);
            if (badge) {
                badge.textContent  = `${n} pg`;
                badge.style.background = 'rgba(16,185,129,0.15)';
                badge.style.color      = 'var(--primary)';
                badge.style.border     = '1px solid rgba(16,185,129,0.3)';
            }
        }).catch(() => {
            filePagesMap[i] = 1; // fallback
            const badge = document.getElementById(`pages-badge-${i}`);
            if (badge) { badge.textContent='?'; badge.title='Could not read page count — cost estimated at 1 page'; }
        })
    );

    Promise.all(promises).then(() => {
        const total = Object.values(filePagesMap).reduce((a,b)=>a+b, 0);
        status.innerHTML = `✅ Total: <strong>${total} page${total!==1?'s':''}</strong> across ${filesSelected.length} file${filesSelected.length!==1?'s':''}`;
        calculateCost();
        chosen.textContent = `${filesSelected.length} file(s) selected`;
    });
}

async function countPDFPages(file) {
    const url = URL.createObjectURL(file);
    try {
        const pdf = await pdfjsLib.getDocument({ url, disableWorker: false }).promise;
        const n   = pdf.numPages;
        URL.revokeObjectURL(url);
        return n;
    } catch(e) {
        URL.revokeObjectURL(url);
        return 1;
    }
}

function resetCost() {
    document.getElementById('cost_display').innerText = '—';
    document.getElementById('orig_price_label').innerText = '';
    document.getElementById('formula-label').innerText = 'Pages × Copies × Rate';
    document.getElementById('cost-breakdown').style.display = 'none';
    document.getElementById('breakdown-rows').innerHTML = '';
}

// ── LIVE COST CALCULATOR ──────────────────────────────────────────────────
function calculateCost() {
    const type   = document.getElementById('print_type').value;
    const copies = Math.max(1, parseInt(document.getElementById('copies').value) || 1);
    const rate   = type === 'color' ? RATE_COLOR : RATE_BW;
    const typeLabel = type === 'color' ? 'Color' : 'B&W';

    const hasCounts = Object.keys(filePagesMap).length > 0;
    const breakdown = document.getElementById('cost-breakdown');
    const rows      = document.getElementById('breakdown-rows');
    const formula   = document.getElementById('formula-label');

    let grandBase = 0;

    if (hasCounts && filesSelected.length > 0) {
        // Per-file breakdown
        breakdown.style.display = 'block';
        rows.innerHTML = '';

        filesSelected.forEach((f, i) => {
            const pages    = filePagesMap[i] ?? 1;
            const fileCost = pages * copies * rate;
            grandBase += fileCost;

            const shortName = f.name.length > 28 ? f.name.substring(0,26)+'…' : f.name;
            rows.innerHTML += `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:var(--input-bg);border-radius:8px;margin-bottom:4px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.82rem;color:var(--text-main);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${f.name}">📄 ${shortName}</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">${pages} pg × ${copies} cop × ₹${rate} = <span style="color:var(--primary);font-weight:700;">₹${fileCost.toFixed(2)}</span></div>
                </div>
            </div>`;
        });

        formula.innerHTML = `${Object.values(filePagesMap).reduce((a,b)=>a+b,0)} total pages × ${copies} cop × ₹${rate}`;
    } else {
        // No files yet — show placeholder formula
        breakdown.style.display = 'none';
        grandBase = 1 * copies * rate; // 1-page estimate
        formula.innerHTML = `<em style="color:var(--text-muted);">Select a PDF to see page count</em>`;
    }

    const final = grandBase;

    document.getElementById('cost_display').innerText = '₹' + final.toFixed(2);

    const origLabel = document.getElementById('orig_price_label');
    origLabel.textContent = '';
}

// Initialise display on load
resetCost();
document.getElementById('print_type').addEventListener('change', calculateCost);
document.getElementById('copies').addEventListener('input', calculateCost);

// ── SORT SHOPS BY DISTANCE ────────────────────────────────────────────────
function sortByDistance() {
    if (!("geolocation" in navigator)) { alert("Geolocation not supported."); return; }
    navigator.geolocation.getCurrentPosition(pos => {
        const uLat = pos.coords.latitude, uLng = pos.coords.longitude;
        const sel  = document.getElementById('shop_select');
        const opts = Array.from(sel.options).filter(o => o.value);
        opts.sort((a,b) => {
            const dA = haversineJS(uLat,uLng,parseFloat(a.dataset.lat||0),parseFloat(a.dataset.lng||0));
            const dB = haversineJS(uLat,uLng,parseFloat(b.dataset.lat||0),parseFloat(b.dataset.lng||0));
            return dA-dB;
        });
        while (sel.options.length > 1) sel.remove(1);
        opts.forEach(o => {
            const km = haversineJS(uLat,uLng,parseFloat(o.dataset.lat||0),parseFloat(o.dataset.lng||0));
            const ds = km < 1 ? ` (${(km*1000).toFixed(0)}m)` : ` (${km.toFixed(1)}km)`;
            o.text = o.text.replace(/ \(\d+(\.\d+)?k?m\)$/, '') + ds;
            sel.appendChild(o);
        });
        alert('✅ Shops sorted by distance from your location!');
    }, err => alert("Could not get location: " + err.message));
}
function haversineJS(lat1,lng1,lat2,lng2){
    const R=6371,dL=(lat2-lat1)*Math.PI/180,dG=(lng2-lng1)*Math.PI/180;
    const a=Math.sin(dL/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dG/2)**2;
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

// ── REVIEW MODAL ──────────────────────────────────────────────────────────
function openReview(jobId,shopId,shopName,cur,comment){
    document.getElementById('reviewJobId').value  = jobId;
    document.getElementById('reviewShopId').value = shopId;
    document.getElementById('reviewShopLabel').textContent = 'Shop: '+shopName;
    document.getElementById('reviewComment').value = comment||'';
    setStars(cur);
    document.getElementById('reviewModal').style.display = 'flex';
}
function closeReview(){ document.getElementById('reviewModal').style.display='none'; }
function setStars(val){
    document.getElementById('ratingInput').value=val;
    document.querySelectorAll('#starRow .star').forEach(s=>{
        s.textContent = parseInt(s.dataset.val)<=val?'★':'☆';
        s.style.color  = parseInt(s.dataset.val)<=val?'#f59e0b':'var(--text-muted)';
    });
}
document.querySelectorAll('#starRow .star').forEach(s=>{
    s.addEventListener('mouseover',()=>{ document.querySelectorAll('#starRow .star').forEach(x=>{ x.textContent=parseInt(x.dataset.val)<=parseInt(s.dataset.val)?'★':'☆'; x.style.color=parseInt(x.dataset.val)<=parseInt(s.dataset.val)?'#f59e0b':'var(--text-muted)'; }); });
    s.addEventListener('mouseleave',()=>setStars(parseInt(document.getElementById('ratingInput').value)));
    s.addEventListener('click',()=>setStars(parseInt(s.dataset.val)));
});
document.getElementById('reviewModal').addEventListener('click',function(e){ if(e.target===this) closeReview(); });
</script>


<?php require_once 'footer.php'; ?>
