# 🎓 ALUMNICONNECT — Platform Alumni & Social Network SMK

[![PHP Version](https://img.shields.io/badge/PHP-8.x-blue.svg)](https://www.php.net/)
[![MySQL Database](https://img.shields.io/badge/Database-MySQL%20%2F%20MariaDB-orange.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/Project-PKWU%20PjBL%202026-green.svg)]()

> **Projek Kreatif dan Kewirausahaan (PKWU)**  
> **Satuan Pendidikan:** SMK — Program Keahlian: PPLG (Pengembangan Perangkat Lunak dan Gim)  
> **Paket 14:** ALUMNICONNECT (Platform Alumni dan Social Network)  
> **Vendor IT:** Finz The Dragon  
> **Lead Developer:** Fidi Naidzar · Kelas XI PPLG · No. Absen 14  

---

## 📌 Deskripsi Proyek
**ALUMNICONNECT** adalah platform jejaring sosial dan direktori alumni sekolah yang dirancang untuk menjembatani komunikasi antara alumni, sekolah (guru BK), dan siswa tingkat akhir dalam satu ekosistem digital terpadu.

### 🌟 Fitur Utama (Sesuai KAK PKWU):
1. **Multi-Role Authentication:** Role Admin, Guru, Siswa, dan Alumni.
2. **Social Feed & Interaksi:** CRUD Postingan, upload kategori/media, like real-time (AJAX), komentar interaktif, share counter, dan trending hashtag (`#PPLG`).
3. **Direktori Alumni & Search Ranking:** Pencarian multi-filter (Angkatan, Jurusan, Profesi) dengan algoritma:
   $$\text{Search Score} = (\text{Keyword Match} \times 0.35) + (\text{Relevance} \times 0.25) + (\text{Popularity} \times 0.25) + (\text{Recency} \times 0.15)$$
4. **Profil Alumni Lengkap & Edit Akun:** Menampilkan rekam jejak pengalaman kerja (`alumni_work`), riwayat pendidikan (`alumni_education`), serta fitur ubah Foto Profil (PP), Username, dan Password (Bcrypt).
5. **Direct Messaging & Networking:** Chat dua arah antar pengguna untuk mentoring, info lowongan kerja, atau konsultasi karier.
6. **Mesin Rekomendasi Alumni:** Rekomendasi alumni relevan berbasis kesamaan jurusan dan keaktifan.
7. **Pusat Notifikasi:** Real-time pemberitahuan aktivitas follow, like, komentar, dan pesan.
8. **Dashboard Guru & Admin:** Statistik visual persebaran alumni per angkatan & jurusan serta laporan siap cetak/PDF.
9. **Responsive UI/UX (Mobile & Desktop):** Tampilan mobile dengan *Bottom Navigation Bar* dan desktop 3 kolom simetris sesuai identitas visual *Finz The Dragon* (*Dark Navy, Cyan Brand, Warm Orange*).

---

## 🚀 Panduan Instalasi Lokal

### 1. Prasyarat
- Web Server: **Laragon** / **XAMPP**
- PHP: Versi 8.0 ke atas
- MySQL / MariaDB

### 2. Clone Repository
```bash
git clone https://github.com/Bloodzz22/Web-Alumni-SMK-1.git
```
Pindahkan atau letakkan folder proyek di:
- Laragon: `d:/laragon/www/alumniconnect`
- XAMPP: `c:/xampp/htdocs/alumniconnect`

### 3. Import Database
1. Buka **phpMyAdmin** (`http://localhost/phpmyadmin`).
2. Buat database baru bernama `alumniconnect`.
3. Import file database yang sudah disediakan di folder `database/alumniconnect.sql`.

### 4. Konfigurasi Database (Jika Diperlukan)
Sesuaikan kredensial di file `config/database.php`:
```php
$host     = 'localhost';
$user     = 'root';
$password = ''; // sesuaikan jika ada password mysql
$database = 'alumniconnect';
```

---

## 🔑 Akun Demo Siap Pakai

Semua akun demo telah dienkripsi dengan standar `PASSWORD_BCRYPT`.  
**Password untuk semua akun demo:** `password123`

| Role | Username | Password | Keterangan |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `password123` | Administrator Sekolah |
| **Guru** | `guru_budi` | `password123` | Guru Bimbingan Konseling (BK) |
| **Siswa** | `siswa_andi` | `password123` | Siswa Tingkat Akhir XI PPLG |
| **Alumni** | `alum_sarah` | `password123` | Sarah Peterson (Software Engineer Tokopedia) |
| **Alumni** | `alum_alex` | `password123` | Alex Rodriguez (UI/UX Designer Gojek) |

---

## 📱 Akses Aplikasi

- **Laptop / PC:** Buka [http://localhost/alumniconnect/](http://localhost/alumniconnect/)
- **Smartphone (HP):** Hubungkan HP ke WiFi yang sama dengan laptop, lalu akses melalui IP lokal komputer, contoh: `http://192.168.40.164/alumniconnect/`

---

## 📁 Struktur Direktori
```text
alumniconnect/
├── admin/          # Dashboard Admin & Guru (statistik & laporan)
├── assets/         # CSS kustom, JavaScript, dan direktori uploads
├── auth/           # Login, Register multi-role, dan Logout
├── config/         # Konfigurasi koneksi basis data MySQL
├── database/       # Dump database SQL siap import
├── includes/       # Header, Footer, Bottom Nav, dan fungsi helper
├── pages/          # Feed, Direktori, Profil, Edit Profil, Pesan, Notifikasi
└── index.php       # Landing Page utama sekolah & almamater
```

---
*Dikembangkan oleh **Finz The Dragon** © 2026. Semua hak cipta dilindungi.*
