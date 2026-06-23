<?php
// map.php
require_once 'config.php';
if (!isLoggedIn()) { header("Location: auth.php"); exit; }

// Fetch only APPROVED shops with valid coordinates
$result = $conn->query("SELECT id, name, address, latitude, longitude, shop_status, open_time, close_time FROM sd_users WHERE role='owner' AND is_approved=1 AND latitude IS NOT NULL ORDER BY name");
$printers = [];
while($row = $result->fetch_assoc()) $printers[] = $row;

require_once 'header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    .map-page-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom:20px; }
    .map-wrapper { display:flex; gap:24px; margin-top:20px; }
    .map-sidebar { width:340px; flex-shrink:0; display:flex; flex-direction:column; gap:0; max-height:75vh; overflow-y:auto; border-radius:20px; }
    .printer-card { padding:18px 20px; border-bottom:1px solid var(--glass-border); background:var(--glass-bg); cursor:pointer; transition:0.2s; }
    .printer-card:first-child { border-radius:20px 20px 0 0; }
    .printer-card:last-child  { border-radius:0 0 20px 20px; border-bottom:none; }
    .printer-card:only-child  { border-radius:20px; }
    .printer-card:hover { background:var(--primary-glow); border-left:3px solid var(--primary); }
    .printer-card.hidden-card { display:none; }
    #interactive-map { flex:1; border-radius:24px; border:2px solid var(--glass-border); z-index:1; min-height:75vh; }

    /* Controls bar */
    .map-controls { display:flex; gap:15px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
    .map-control-group { display:flex; flex-direction:column; gap:6px; }
    .map-control-label { font-size:0.8rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
    .radius-control { display:flex; align-items:center; gap:10px; background:var(--glass-bg); border:1px solid var(--glass-border); border-radius:12px; padding:8px 14px; }
    .radius-slider { -webkit-appearance:none; width:140px; height:4px; border-radius:2px; background:var(--glass-border); outline:none; cursor:pointer; }
    .radius-slider::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:16px; border-radius:50%; background:var(--primary); cursor:pointer; }
    .radius-val { font-weight:700; color:var(--primary); min-width:60px; font-size:0.95rem; }
    .unit-toggle { display:flex; background:var(--input-bg); border:1px solid var(--glass-border); border-radius:10px; overflow:hidden; }
    .unit-btn { padding:6px 14px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; background:transparent; color:var(--text-muted); transition:0.2s; }
    .unit-btn.active { background:var(--primary); color:#fff; }
    .locate-btn { display:flex; align-items:center; gap:8px; padding:8px 16px; background:var(--glass-bg); border:1px solid var(--primary); color:var(--primary); border-radius:12px; font-weight:600; cursor:pointer; font-size:0.9rem; transition:0.2s; white-space:nowrap; }
    .locate-btn:hover { background:var(--primary-glow); }
    .shop-count-badge { font-size:0.85rem; color:var(--text-muted); font-weight:600; }
    .shop-count-badge span { color:var(--primary); font-weight:800; }
    .distance-label { font-size:0.75rem; color:var(--primary); font-weight:700; margin-top:4px; }
    .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:5px; }

    @media (max-width:900px) {
        .map-wrapper { flex-direction:column; }
        .map-sidebar  { width:100%; max-height:300px; }
        #interactive-map { min-height:400px; }
        .map-controls { flex-direction:column; align-items:flex-start; }
    }
</style>

<div class="glass-panel" style="padding:30px; margin-bottom:16px;">
    <div class="map-page-header">
        <div>
            <h2 style="margin:0; color:var(--primary);">📍 Find SafeDoc Printers</h2>
            <p style="color:var(--text-muted); margin-top:5px; margin-bottom:0;">Locate the nearest active print shop and send your documents securely.</p>
        </div>
        <div class="shop-count-badge">Showing <span id="visibleCount">0</span> of <?= count($printers) ?> shops</div>
    </div>

    <!-- CONTROLS -->
    <div class="map-controls" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--glass-border);">
        <button class="locate-btn" id="locateBtn" onclick="locateMe()">
            📍 Use My Location
        </button>

        <div class="map-control-group">
            <div class="map-control-label">Search Radius</div>
            <div class="radius-control">
                <input type="range" class="radius-slider" id="radiusSlider" min="1" max="100" value="10" oninput="onRadiusChange(this.value)">
                <span class="radius-val" id="radiusLabel">10 km</span>
                <div class="unit-toggle">
                    <button class="unit-btn active" id="btn-km" onclick="setUnit('km')">km</button>
                    <button class="unit-btn" id="btn-m" onclick="setUnit('m')">m</button>
                </div>
            </div>
        </div>

        <div class="map-control-group">
            <div class="map-control-label">Filter by Status</div>
            <div class="unit-toggle" style="border-radius:12px;">
                <button class="unit-btn active" onclick="setStatusFilter('all', this)">All</button>
                <button class="unit-btn" onclick="setStatusFilter('Available', this)">🟢 Open</button>
                <button class="unit-btn" onclick="setStatusFilter('Busy', this)">🔴 Busy</button>
            </div>
        </div>
    </div>
</div>

<div class="map-wrapper">
    <div class="map-sidebar" id="sidebar">
        <div id="no-shops-msg" style="display:none; padding:30px; text-align:center; color:var(--text-muted); background:var(--glass-bg); border-radius:20px;">
            <div style="font-size:2.5rem; margin-bottom:10px;">🔍</div>
            <p style="margin:0; font-weight:600;">No shops found within the selected radius.<br><small>Try increasing the search radius.</small></p>
        </div>
        <?php if(empty($printers)): ?>
            <p style="color:var(--text-muted); text-align:center; margin-top:20px; padding:20px;">No shops have registered their locations yet.</p>
        <?php else: ?>
            <?php foreach($printers as $p):
                //$sc = match($p['shop_status']) { 'Available'=>'#10b981', 'Busy'=>'#ef4444', default=>'#64748b' };
				// Universal PHP version compatible logic
				switch($p['shop_status']) {
					case 'Available': 
						$sc = '#10b981'; 
						break;
					case 'Busy':      
						$sc = '#ef4444'; 
						break;
					default:          
						$sc = '#64748b'; 
						break;
				}
                $hours_str = ($p['open_time'] && $p['close_time']) ? date('h:iA',strtotime($p['open_time'])).'-'.date('h:iA',strtotime($p['close_time'])) : '';
            ?>
            <div class="printer-card" id="card-<?= $p['id'] ?>" data-lat="<?= $p['latitude'] ?>" data-lng="<?= $p['longitude'] ?>" data-status="<?= $p['shop_status'] ?>" onclick="focusMap(<?= $p['latitude'] ?>, <?= $p['longitude'] ?>, <?= $p['id'] ?>)">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                    <div style="font-weight:800; font-size:1rem; color:var(--text-main);">🖨️ <?= htmlspecialchars($p['name']) ?></div>
                    <span style="padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; white-space:nowrap; background:<?= $sc ?>22; color:<?= $sc ?>;"><?= $p['shop_status'] ?></span>
                </div>
                <div style="color:var(--text-muted); font-size:0.82rem; margin-top:4px;">📍 <?= htmlspecialchars($p['address'] ?: 'Location set') ?></div>
                <?php if($hours_str): ?>
                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:3px;">🕐 <?= $hours_str ?></div>
                <?php endif; ?>
                <div class="distance-label" id="dist-<?= $p['id'] ?>"></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div id="interactive-map"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const printers = <?= json_encode($printers) ?>;

// ── MAP INIT ──────────────────────────────────────────────────────────────
const map = L.map('interactive-map').setView([12.9716, 77.5946], 12);
L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',{attribution:'© CartoDB'}).addTo(map);

const printerIcon = L.icon({
    iconUrl:'https://img.icons8.com/color/48/print.png',
    iconSize:[38,38], iconAnchor:[19,38], popupAnchor:[0,-38]
});
const myIcon = L.divIcon({
    html:`<div style="width:16px;height:16px;border-radius:50%;background:#3b82f6;border:3px solid #fff;box-shadow:0 2px 8px rgba(59,130,246,0.5);"></div>`,
    iconSize:[16,16], iconAnchor:[8,8]
});

// ── STATE ─────────────────────────────────────────────────────────────────
let userLat = null, userLng = null;
let radiusKm = 10;
let currentUnit = 'km';
let statusFilter = 'all';
let userMarker = null, radiusCircle = null;
const markers = {};

// Create markers for all printers
printers.forEach(p => {
    const sc = p.shop_status === 'Available' ? '#10b981' : p.shop_status === 'Busy' ? '#ef4444' : '#64748b';
    const hours = (p.open_time && p.close_time)
        ? `<br><small style="color:#64748b;">🕐 ${formatTime(p.open_time)} – ${formatTime(p.close_time)}</small>`
        : '';
    const mk = L.marker([p.latitude, p.longitude], {icon: printerIcon}).addTo(map)
        .bindPopup(`<div style="min-width:200px;">
            <strong style="color:#0f172a;font-size:1rem;">🖨️ ${p.name}</strong><br>
            <span style="color:${sc};font-weight:700;">${p.shop_status}</span>
            <br><small style="color:#64748b;">${p.address || ''}</small>${hours}
            <br><br><a href="customer_dashboard.php" style="background:${sc};color:#fff;padding:6px 14px;border-radius:8px;text-decoration:none;font-weight:700;font-size:0.85rem;">📤 Send Document</a>
        </div>`);
    markers[p.id] = mk;
});

function formatTime(t) {
    if (!t) return '';
    const parts = t.split(':');
    const h = parseInt(parts[0]), m = parts[1];
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12  = h % 12 || 12;
    return `${h12}:${m} ${ampm}`;
}

// ── LOCATE ME ─────────────────────────────────────────────────────────────
function locateMe() {
    if (!("geolocation" in navigator)) { alert("Geolocation not supported."); return; }
    const btn = document.getElementById('locateBtn');
    btn.innerHTML = '⏳ Locating...'; btn.disabled = true;
    navigator.geolocation.getCurrentPosition(
        pos => {
            userLat = pos.coords.latitude; userLng = pos.coords.longitude;
            if (userMarker) map.removeLayer(userMarker);
            userMarker = L.marker([userLat, userLng], {icon: myIcon}).addTo(map).bindPopup('<b>📍 You are here</b>').openPopup();
            map.setView([userLat, userLng], 13);
            drawRadiusCircle(); filterShops();
            btn.innerHTML = '✅ Location Set'; btn.disabled = false;
            setTimeout(() => { btn.innerHTML = '📍 Use My Location'; }, 3000);
        },
        err => { alert("Could not get location: " + err.message); btn.innerHTML = '📍 Use My Location'; btn.disabled = false; }
    );
}

// ── RADIUS ────────────────────────────────────────────────────────────────
function setUnit(unit) {
    currentUnit = unit;
    document.getElementById('btn-km').classList.toggle('active', unit === 'km');
    document.getElementById('btn-m').classList.toggle('active', unit === 'm');
    const slider = document.getElementById('radiusSlider');
    if (unit === 'km') {
        slider.min=1; slider.max=100; slider.step=1;
        radiusKm = parseInt(slider.value);
    } else {
        slider.min=100; slider.max=5000; slider.step=100;
        slider.value = Math.round(radiusKm * 1000);
        radiusKm = parseInt(slider.value) / 1000;
    }
    updateRadiusLabel(slider.value);
    if (userLat) { drawRadiusCircle(); filterShops(); }
}

function onRadiusChange(val) {
    if (currentUnit === 'km') { radiusKm = parseFloat(val); }
    else { radiusKm = parseFloat(val) / 1000; }
    updateRadiusLabel(val);
    if (userLat) { drawRadiusCircle(); filterShops(); }
}

function updateRadiusLabel(val) {
    const lbl = document.getElementById('radiusLabel');
    if (currentUnit === 'km') lbl.textContent = parseFloat(val).toFixed(0) + ' km';
    else lbl.textContent = parseFloat(val) >= 1000 ? (parseFloat(val)/1000).toFixed(1)+' km' : parseFloat(val)+' m';
}

function drawRadiusCircle() {
    if (radiusCircle) map.removeLayer(radiusCircle);
    radiusCircle = L.circle([userLat, userLng], {
        radius: radiusKm * 1000,
        color: '#3b82f6', fillColor: '#3b82f6', fillOpacity: 0.06,
        weight: 2, dashArray: '6 4'
    }).addTo(map);
}

// ── HAVERSINE DISTANCE ────────────────────────────────────────────────────
function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2-lat1)*Math.PI/180;
    const dLng = (lng2-lng1)*Math.PI/180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// ── FILTER SHOPS ──────────────────────────────────────────────────────────
function filterShops() {
    let visible = 0;
    printers.forEach(p => {
        const card   = document.getElementById('card-'+p.id);
        const marker = markers[p.id];
        const distEl = document.getElementById('dist-'+p.id);

        let showByRadius = true;
        let distKm = null;

        if (userLat !== null) {
            distKm = haversine(userLat, userLng, parseFloat(p.latitude), parseFloat(p.longitude));
            showByRadius = distKm <= radiusKm;

            if (distEl) {
                if (distKm < 1) distEl.textContent = '📏 ' + (distKm*1000).toFixed(0) + ' m away';
                else distEl.textContent = '📏 ' + distKm.toFixed(1) + ' km away';
            }
        } else if (distEl) {
            distEl.textContent = '';
        }

        const showByStatus = (statusFilter === 'all' || p.shop_status === statusFilter);
        const show = showByRadius && showByStatus;

        if (card)   card.classList.toggle('hidden-card', !show);
        if (marker) { show ? marker.addTo(map) : map.removeLayer(marker); }
        if (show)   visible++;
    });

    document.getElementById('visibleCount').textContent = visible;
    document.getElementById('no-shops-msg').style.display = visible === 0 ? 'block' : 'none';
}

// ── STATUS FILTER ─────────────────────────────────────────────────────────
function setStatusFilter(status, btn) {
    statusFilter = status;
    btn.closest('.unit-toggle').querySelectorAll('.unit-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterShops();
}

// ── FOCUS MAP ────────────────────────────────────────────────────────────
function focusMap(lat, lng, id) {
    map.flyTo([lat, lng], 16, {duration:1.2});
    if (markers[id]) markers[id].openPopup();
}

// ── INIT ──────────────────────────────────────────────────────────────────
// Try to auto-get location on page load
if ("geolocation" in navigator) {
    navigator.geolocation.getCurrentPosition(
        pos => {
            userLat = pos.coords.latitude; userLng = pos.coords.longitude;
            userMarker = L.marker([userLat, userLng], {icon: myIcon}).addTo(map).bindPopup('<b>📍 You are here</b>');
            map.setView([userLat, userLng], 13);
            drawRadiusCircle(); filterShops();
            document.getElementById('locateBtn').innerHTML = '✅ Location Active';
        },
        () => { filterShops(); } // no location, show all
    );
} else {
    filterShops();
}
</script>

<?php require_once 'footer.php'; ?>
