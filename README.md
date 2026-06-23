# 📄 SafeDoc — Secure Document Printing Portal

SafeDoc is a web-based document printing platform that lets users securely upload PDF files to nearby print shops, track print jobs in real time, and collect their documents using a one-time PIN — with automatic file deletion after 1 hour for privacy.

Built as an academic mini-project at Viswajyothi College of Engineering & Technology.

---

## ✨ Features

### For Customers
- Upload up to 3 PDF documents (max 20 MB each) per job
- Choose between B&W or colour printing with per-page cost estimate
- Real-time job status tracking: `Active → Ready → Printed`
- Secure one-time 6-digit PIN for document pickup
- Email notification when document is ready
- Leave a star rating and review after pickup
- Loyalty discount after 5 completed jobs

### For Shop Owners
- Admin-approved onboarding before going live
- Set shop location (lat/lng) — appears on the customer map
- Configure open/close hours (auto-adjusts Available/Offline status)
- Set custom B&W and colour pricing per page
- Mark jobs as Ready and trigger customer email with PIN
- Verify customer PIN at handover
- Revenue log and job history dashboard

### For Admins
- Approve or reject shop owner registrations
- View all users, jobs, shops, and reviews
- Full system oversight from a dedicated admin portal

### Privacy & Security
- All uploaded files auto-deleted 1 hour after upload
- Uploads folder blocked from direct browsing (`index.php` redirect)
- Session-based authentication with role separation (`customer`, `owner`, `admin`)
- PIN-verified document handover
- PDF-only uploads enforced server-side

### Map / Discovery
- Interactive Leaflet.js map showing all approved, active shops
- Radius filter (km/miles) with live GPS location
- Shop status badges: Available / Busy / Offline

---

## 🛠 Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL (auto-provisioned via `config.php`) |
| Frontend | HTML5, CSS3, Vanilla JS |
| Maps | Leaflet.js |
| Email | PHP `mail()` |
| PDF handling | Custom PHP page-count parser |

---

## 📁 Project Structure

```
safedoc/
├── index.php               # Landing page
├── auth.php                # Login & registration
├── admin_auth.php          # Separate admin login
├── config.php              # DB setup, session config, helpers
├── customer_dashboard.php  # Upload, track, review
├── owner_dashboard.php     # Jobs queue, settings, PIN verify
├── admin_dashboard.php     # User/shop/job management
├── map.php                 # Leaflet map to find shops
├── header.php              # Shared nav header
├── footer.php              # Shared footer
├── logout.php              # Session destroy
└── uploads/                # Temporary PDF storage (auto-cleared)
```

---

## ⚙️ Setup & Installation

### Requirements
- PHP 8.0+
- MySQL 5.7+ or MariaDB
- A local server like XAMPP, WAMP, or Laragon

### Steps

1. **Clone the repo**
   ```bash
   git clone https://github.com/hemaniju05-netizen/safedoc.git
   ```

2. **Move to your server's web root**
   ```
   e.g. C:/xampp/htdocs/safedoc  (XAMPP)
   ```

3. **Start Apache and MySQL** via XAMPP/WAMP control panel.

4. **Open in browser**
   ```
   http://localhost/safedoc/
   ```
   The database and all tables are created automatically on first load.

5. **Admin login** (pre-seeded)
   ```
   Email:    safedoc@2026.mini
   Password: (set in config.php — change before deploying)
   ```

> ⚠️ This project is intended for local/academic use. Before deploying to a production server, replace plain `mail()` with an SMTP library, use environment variables for credentials, and add CSRF protection.

---

## 🖼 Screenshots
   ![Landing Page](landing.png)
   ![Customer Dashboard](customer.png)
   ![Map](map.png)

---

## 📌 Academic Context

- **Project type:** Mini-project
- **Course:** B.Tech Computer Science and Design Engineering
- **Institution:** Viswajyothi College of Engineering & Technology, Vazhakulam
- **Year:** 2025–26

## 👩‍💻 Developed By

- Adhitya Biju
- Annmary Cyriac
- Hema Niju
- Shelna Subash
---

## 📄 License

This project is for academic and educational purposes.
