<?php
// config.php — SafeDoc v3 (Admin + Approval + Hours + Location Radius)

ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
session_start();

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "safedoc";

$conn = new mysqli($servername, $username, $password);
$conn->query("CREATE DATABASE IF NOT EXISTS $dbname");
$conn->select_db($dbname);
$conn->set_charset("utf8mb4");

// ── 1. USERS TABLE ──────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sd_users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(100) UNIQUE NOT NULL,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('customer','owner','admin') DEFAULT 'customer',
    address      TEXT NULL,
    latitude     DECIMAL(10,8) NULL,
    longitude    DECIMAL(11,8) NULL,
    shop_status  ENUM('Available','Busy','Offline') DEFAULT 'Offline',
    is_approved  TINYINT(1) DEFAULT 0,
    open_time    TIME NULL,
    close_time   TIME NULL,
    total_jobs   INT DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Safe upgrades for sd_users
foreach ([
    'latitude'    => "ADD COLUMN latitude DECIMAL(10,8) NULL",
    'longitude'   => "ADD COLUMN longitude DECIMAL(11,8) NULL",
    'address'     => "ADD COLUMN address TEXT NULL",
    'shop_status' => "ADD COLUMN shop_status ENUM('Available','Busy','Offline') DEFAULT 'Offline'",
    'is_approved' => "ADD COLUMN is_approved TINYINT(1) DEFAULT 0",
    'open_time'   => "ADD COLUMN open_time TIME NULL",
    'close_time'  => "ADD COLUMN close_time TIME NULL",
    'total_jobs'  => "ADD COLUMN total_jobs INT DEFAULT 0",
] as $col => $ddl) {
    $r = $conn->query("SHOW COLUMNS FROM sd_users LIKE '$col'");
    if ($r->num_rows == 0) $conn->query("ALTER TABLE sd_users $ddl");
}

// Modify shop_status enum to remove 'Away' if it still exists
$conn->query("ALTER TABLE sd_users MODIFY COLUMN shop_status ENUM('Available','Busy','Offline') DEFAULT 'Offline'");

// ── Pricing columns for shop owners ─────────────────────────────────────────
foreach ([
    'bw_price'    => "ADD COLUMN bw_price DECIMAL(6,2) DEFAULT 2.00",
    'color_price' => "ADD COLUMN color_price DECIMAL(6,2) DEFAULT 10.00",
] as $col => $ddl) {
    $r = $conn->query("SHOW COLUMNS FROM sd_users LIKE '$col'");
    if ($r->num_rows == 0) $conn->query("ALTER TABLE sd_users $ddl");
}

// Auto-approve admins and customers; owners need admin approval
$conn->query("UPDATE sd_users SET is_approved=1 WHERE role IN('customer','admin') AND is_approved=0");

// ── Seed default admin account (runs only if email doesn't exist) ────────────
$admin_email = 'safedoc@2026.mini';
$admin_check = $conn->query("SELECT id FROM sd_users WHERE email='$admin_email'");
if ($admin_check->num_rows === 0) {
    $admin_hash = '$2y$12$5/emOfO4gaX2kbaNewFyEOF085Z65jbOBzg3hd3Cv3KkWDEsV0vCS';
    $conn->query("INSERT INTO sd_users (name, email, password, role, is_approved)
                  VALUES ('SafeDoc Admin', '$admin_email', '$admin_hash', 'admin', 1)");
}

// ── 2. PRINT JOBS TABLE ─────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sd_print_jobs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    shop_id        INT NOT NULL,
    filename       VARCHAR(255) NOT NULL,
    original_name  VARCHAR(255) DEFAULT '',
    print_type     ENUM('bw','color') DEFAULT 'bw',
    copies         INT DEFAULT 1,
    page_count     INT DEFAULT 1,
    comments       TEXT NULL,
    estimated_cost DECIMAL(10,2) DEFAULT 0.00,
    discount_pct   INT DEFAULT 0,
    pin_code       VARCHAR(6) DEFAULT NULL,
    pin_attempts   INT DEFAULT 0,
    pin_wiped      TINYINT(1) DEFAULT 0,
    upload_time    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status         ENUM('active','ready','printed','expired') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES sd_users(id) ON DELETE CASCADE
)");

foreach ([
    'original_name' => "ADD COLUMN original_name VARCHAR(255) DEFAULT ''",
    'page_count'    => "ADD COLUMN page_count INT DEFAULT 1",
    'discount_pct'  => "ADD COLUMN discount_pct INT DEFAULT 0",
    'pin_code'      => "ADD COLUMN pin_code VARCHAR(6) DEFAULT NULL",
    'pin_attempts'  => "ADD COLUMN pin_attempts INT DEFAULT 0",
    'pin_wiped'     => "ADD COLUMN pin_wiped TINYINT(1) DEFAULT 0",
] as $col => $ddl) {
    $r = $conn->query("SHOW COLUMNS FROM sd_print_jobs LIKE '$col'");
    if ($r->num_rows == 0) $conn->query("ALTER TABLE sd_print_jobs $ddl");
}

// ── 3. REVENUE LOG TABLE ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sd_revenue_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    shop_id    INT NOT NULL,
    user_id    INT NOT NULL,
    job_id     INT NOT NULL,
    amount     DECIMAL(10,2) NOT NULL,
    log_date   DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── 4. REVIEWS TABLE ────────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS sd_reviews (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    shop_id     INT NOT NULL,
    job_id      INT NOT NULL,
    rating      TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment     TEXT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_review (customer_id, job_id),
    FOREIGN KEY (customer_id) REFERENCES sd_users(id) ON DELETE CASCADE,
    FOREIGN KEY (shop_id)     REFERENCES sd_users(id) ON DELETE CASCADE
)");

// ── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * Count pages in a PDF without external libraries.
 * Reads the raw PDF bytes and finds the /Count entry in the Pages dict,
 * falling back to counting /Type /Page objects.
 */
function getPDFPageCount(string $filepath): int {
    if (!file_exists($filepath)) return 1;

    // Read up to 512 KB — enough for all cross-reference and page-tree data
    $fp   = fopen($filepath, 'rb');
    $data = fread($fp, 524288);
    fclose($fp);

    // Strategy 1: look for /Type /Pages … /Count N  (most reliable)
    if (preg_match_all('/\/Type\s*\/Pages[\s\S]{0,200}?\/Count\s+(\d+)/i', $data, $m)) {
        // The largest Count value in a /Pages dict is the total page count
        return (int) max($m[1]);
    }

    // Strategy 2: global search for /Count N
    if (preg_match('/\/Count\s+(\d+)/', $data, $m)) {
        return max(1, (int)$m[1]);
    }

    // Strategy 3: count /Type /Page (non-Pages) entries
    preg_match_all('/\/Type\s*\/Page[^s]/i', $data, $m);
    return max(1, count($m[0]));
}

function isLoggedIn() { return isset($_SESSION['user_id']); }
function isOwner()    { return isset($_SESSION['role']) && $_SESSION['role'] === 'owner'; }
function isAdmin()    { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function isApproved() { return isset($_SESSION['is_approved']) && $_SESSION['is_approved'] == 1; }

function getLoyaltyDiscount($conn, $user_id) {
    $r = $conn->query("SELECT COUNT(*) as c FROM sd_print_jobs WHERE user_id=$user_id AND status IN('printed','ready','active')");
    $count = (int)($r->fetch_assoc()['c'] ?? 0);
    return ($count >= 5) ? 5 : 0;
}

function generatePIN() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function sendEmail($to, $subject, $body) {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: SafeDoc Portal <noreply@safedoc.app>\r\n";
    @mail($to, $subject, $body, $headers);
}

function buildReadyEmail($customerName, $shopName, $pin, $docName) {
    return "
    <div style='font-family:sans-serif;max-width:500px;margin:0 auto;background:#0f172a;color:#f8fafc;border-radius:16px;overflow:hidden;'>
        <div style='background:linear-gradient(135deg,#10b981,#3b82f6);padding:30px;text-align:center;'>
            <h1 style='margin:0;font-size:2rem;'>📄 SafeDoc</h1>
        </div>
        <div style='padding:35px;'>
            <h2 style='color:#10b981;margin-top:0;'>Your document is ready! ✅</h2>
            <p style='color:#94a3b8;'>Hi <strong style='color:#f8fafc;'>$customerName</strong>, your document <strong style='color:#f8fafc;'>\"$docName\"</strong> is ready at <strong style='color:#3b82f6;'>$shopName</strong>.</p>
            <div style='background:rgba(16,185,129,0.1);border:2px solid #10b981;border-radius:12px;padding:25px;text-align:center;margin:25px 0;'>
                <p style='margin:0 0 8px;color:#94a3b8;font-size:0.85rem;letter-spacing:2px;text-transform:uppercase;'>Your Pickup PIN</p>
                <div style='font-size:2.5rem;font-weight:800;letter-spacing:8px;color:#10b981;'>$pin</div>
                <p style='margin:10px 0 0;color:#64748b;font-size:0.8rem;'>Show this PIN at the shop counter</p>
            </div>
            <p style='color:#64748b;font-size:0.85rem;'>⏰ Auto-deleted after 1 hour for your privacy.</p>
        </div>
        <div style='padding:20px;text-align:center;'><p style='color:#475569;font-size:0.8rem;'>© SafeDoc. Secure. Private. Instant.</p></div>
    </div>";
}
?>
