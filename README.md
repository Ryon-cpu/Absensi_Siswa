# Sistem Absensi Siswa

Aplikasi web untuk mengelola siswa, kelas, guru, dan absensi. Backend menggunakan
Laravel REST API, sedangkan frontend Vue 3 disajikan melalui Laravel.

## Fitur

- Login dan dashboard sesuai peran admin, guru, atau siswa.
- Pengelolaan siswa, kelas, guru, dan penugasan guru.
- Pencatatan serta riwayat absensi dengan status `hadir`, `izin`, `sakit`, dan `alpa`.
- Filter dan laporan absensi.
- Ekspor ringkasan laporan ke Excel (`.xlsx`) dan PDF.
- Antrean notifikasi WhatsApp wali untuk absensi `izin`, `sakit`, atau `alpa`.

## Teknologi

- PHP 8.3+, Laravel, dan Laravel Sanctum.
- Vue 3, Vue Router, Axios, dan Vite.
- MySQL (atau database lain yang didukung Laravel).
- SheetJS, jsPDF, dan AutoTable untuk ekspor laporan.

Frontend berada di `resources/js` dan menggunakan CSS biasa. Backend API berada
di `app/Http/Controllers/Api`, dengan endpoint didefinisikan di `routes/api.php`.

## Menjalankan secara lokal

Persyaratan: PHP 8.3+, Composer, Node.js yang didukung Vite, npm, dan server database.

1. Pasang dependency:

   ```bash
   composer install
   npm ci
   ```

2. Buat `.env` dari `.env.example`, lalu sesuaikan koneksi database:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Di Windows, salin `.env.example` secara manual jika perintah `cp` tidak tersedia.

3. Buat tabel dan bangun frontend:

   ```bash
   php artisan migrate
   npm run build
   ```

   Pastikan `.env` menunjuk ke database lokal yang benar sebelum menjalankan migration.

4. Jalankan aplikasi:

   ```bash
   php artisan serve
   ```

   Untuk pengembangan frontend dengan Vite, jalankan `npm run dev` di terminal lain.

## Pengujian

Jalankan backend tests:

```bash
php artisan test
```

Periksa build frontend:

```bash
npm run build
```

Tes notifikasi menggunakan mock; tes otomatis tidak mengirim WhatsApp sungguhan.

## Notifikasi WhatsApp (opsional)

Pengiriman menggunakan WhatsApp Business Platform Cloud API dan antrean Laravel.
Isi pengaturan berikut di `.env`; jangan simpan token di source code atau repository:

```dotenv
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_API_VERSION=
WHATSAPP_TEMPLATE_NAME=
WHATSAPP_TEMPLATE_LANGUAGE=id
```

Siapkan template pesan yang disetujui Meta dan nomor wali siswa. Setelah konfigurasi
Cloud API siap, jalankan worker antrean pada lingkungan yang memang diizinkan
mengirim pesan:

```bash
php artisan queue:work
```

Jangan gunakan worker yang terhubung ke API nyata untuk pengujian.
