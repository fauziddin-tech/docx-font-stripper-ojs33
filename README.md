# Docx Font Stripper (OJS 3.3)

Plugin generik OJS 3.3.0-x (diuji terhadap kode **3.3.0-22**; PHP 7.4–8.2) yang
membuang **font tertanam** dari file Word `.docx` saat diunggah, dan **memperkecil gambar
PNG** yang resolusinya jauh di atas ukuran tampilnya, supaya ukuran file kecil.

Sejak 1.2.0.0 plugin ini juga menjadi **penjaga gawang unggahan**: file yang dapat
dijalankan sebagai program oleh server web (`.php`, `.phtml`, `.phar`, `.cgi`, `.asp`,
`.jsp`, `.shtml`, `.htaccess`, …) dan program Windows (`.exe`, `.bat`, …) ditolak saat
diunggah, sebelum disimpan. Lihat [Penjaga unggahan](#penjaga-unggahan).

Sejak 1.3.0.0 plugin ini **menampilkan ukuran file** pada tautan galley di halaman pembaca
(mis. `PDF (1,2 MB)`), apa pun tema yang dipakai. Lihat [Ukuran file galley](#ukuran-file-galley).

Sejak 1.4.0.0 plugin ini juga **menampilkan ukuran setiap file di daftar file backend**
(workflow, dasbor penulis, reviewer), dengan file 5 MB ke atas ditandai merah. Fitur ini
dipindahkan dari tema Manuscript-Daffa. Lihat [Ukuran file di backend](#ukuran-file-di-backend).

Contoh nyata:

| File | Sebelum | Sesudah | Keterangan |
|---|---|---|---|
| Artikel A | 14,95 MB | 0,49 MB | 14 font tertanam, semua entri ZIP tanpa kompresi; sisanya gambar JPEG (tidak diubah) |
| Artikel B | 10,69 MB | 0,15 MB | 22 file font (8,5 MB); logo PNG 1493×1053 px (2,1 MB) yang tampil 1,74×1,23 cm diperkecil ke 344×243 px (99 KB) |

## Yang diubah dalam .docx

| Bagian | Tindakan |
|---|---|
| `word/fonts/*` (`*.odttf` dll.) dan semua target relasi bertipe `…/relationships/font` | dihapus |
| `word/_rels/fontTable.xml.rels` | relasi font dihapus; bila kosong, bagian ini dihapus |
| `word/fontTable.xml` | `w:embedRegular`, `w:embedBold`, `w:embedItalic`, `w:embedBoldItalic` dihapus (deklarasi `w:font` tetap) |
| `[Content_Types].xml` | `Override` untuk bagian yang dihapus, dan `Default` ekstensi (mis. `odttf`) yang tak lagi dipakai, dihapus |
| `word/settings.xml` | `w:embedTrueTypeFonts` dan `w:saveSubsetFonts` dihapus, supaya Word tidak menanam ulang saat disimpan |
| Entri lain yang tersimpan **tanpa kompresi** (mis. `document.xml`) | dikompresi ulang (deflate); isinya tetap identik byte per byte |
| Gambar PNG yang resolusinya jauh di atas ukuran tampil | diperkecil ke **500 dpi** pada ukuran tampil terbesarnya (lihat di bawah) |

### Aturan memperkecil gambar

- Target 500 dpi pada ukuran tampil: standar umum penerbit untuk gambar campuran teks +
  grafis (foto biasanya 300 dpi). Tetap tajam saat dicetak dan saat di-zoom.
- Hanya PNG. JPEG tidak di-encode ulang (akan menambah artefak), format lain tidak disentuh.
- Ukuran tampil dibaca dari `wp:extent` gambar biasa (`wp:inline`/`wp:anchor` → `pic:pic`),
  memperhitungkan crop (`a:srcRect`); bila gambar dipakai beberapa kali, ukuran terbesar yang
  dipakai. Gambar yang juga dipakai di tempat lain (VML, isi bentuk, grup,
  bullet bergambar, dll.) **tidak** diubah, karena ukurannya tidak bisa dipastikan.
- Hanya bila dimensi baru ≤ 70% dimensi lama, file PNG ≥ 64 KB, dan hasilnya ≥ 20% lebih kecil.
- Nama dan format part tetap (`image1.png` tetap PNG), jadi relasi dan `[Content_Types].xml`
  tidak berubah. Transparansi dipertahankan; PNG yang seluruhnya opak disimpan sebagai RGB.
  Profil warna (iCCP) di PNG tidak ikut dibawa oleh GD.
- Butuh ekstensi PHP `gd`; tanpa itu, langkah gambar dilewati (font tetap dibuang).

Namespace transitional dan strict OOXML sama-sama dikenali.

## Pengaman

- Hanya `.docx`: ekstensi di path tersimpan harus `.docx`, file harus ZIP, berisi
  `word/document.xml`, dan tipe konten bagian utama harus
  `…wordprocessingml.document.main+xml` (jadi `.docm`/`.dotx` dilewati). `.doc`, PDF,
  gambar, dll. tidak disentuh.
- Isi dokumen, gambar, tabel, komentar, track changes, header/footer tidak diubah:
  setelah ditulis, setiap bagian yang tidak sengaja diubah dibandingkan ukuran dan CRC-nya
  dengan file asli.
- Hasil divalidasi sebelum menggantikan file asli: ZIP dibuka ulang dengan
  `ZipArchive::CHECKCONS`, CRC setiap entri dihitung ulang, `word/document.xml` dan
  `[Content_Types].xml` harus ada, XML yang diubah harus valid, dan tidak boleh ada
  relasi yang masih menunjuk ke bagian yang dihapus. Bila ada yang gagal, file asli
  dipertahankan.
- Penggantian file bersifat atomik (file sementara di direktori yang sama lalu `rename()`),
  dan izin file dipertahankan.
- Bila tidak ada font tertanam dan tidak ada PNG yang perlu diperkecil, file tidak diubah sama sekali.
- Setiap hasil dicatat ke error log PHP, mis.:
  `[DocxFontStripper] submissionFileId=12 fileId=34 journals/1/articles/5/….docx: cleaned: 10,688,176 -> 146,163 bytes (-98.6%), 23 part(s) removed, 3 XML part(s) updated, 0 part(s) recompressed, 1 image(s) downscaled [word/media/image1.png 1493x1053 (2,121,543) -> 344x243 (98,878)]`
- Kesalahan apa pun ditangkap; unggahan tidak pernah gagal karena plugin ini.

## Penjaga unggahan

OJS 3.3 menerima file berekstensi apa pun dan menyimpannya sebagai
`<uniqid>.<ekstensi>` di `files_dir`. Hanya ekstensi yang mengandung `php` yang diganti
OJS menjadi `.txt`; `.phtml`, `.phar`, `.pht`, `.shtml`, `.cgi`, dan sejenisnya tersimpan
apa adanya. Bila `files_dir` pernah terjangkau dari web (salah konfigurasi, server
bersama), file seperti itu bisa menjadi web shell.

Ekstensi yang ditolak (`classes/UploadGuard.inc.php`, `UploadGuard::DEFAULT_BLOCKED`):

| Kelompok | Ekstensi |
|---|---|
| Skrip server | `php` `phtml` `pht` `phar` `phps` `php3`–`php8` `inc` `cgi` `fcgi` `asp` `aspx` `ashx` `asmx` `jsp` `jspx` `shtml` `shtm` `stm` |
| Konfigurasi server | `.htaccess` `.htpasswd` `.user.ini` |
| Program Windows | `exe` `dll` `com` `scr` `msi` `bat` `cmd` `vbs` `vbe` `wsf` `ps1` `hta` `lnk` |

Sengaja **tidak** ditolak: kode riset (`.py`, `.r`, `.sh`, `.ipynb`), gambar `.svg`,
galley HTML (`.html`, `.htm`), arsip data (`.zip`, `.csv`).

Yang diperiksa hanya ekstensi terakhir (huruf besar/kecil sama saja, titik/spasi di akhir
diabaikan), karena hanya itu yang sampai ke disk: `artikel.php.docx` tersimpan sebagai
`.docx` dan diterima; `zip.phtml` ditolak.

| Jalur unggah | Hook | Akibat |
|---|---|---|
| Wizard unggah (submission, review, revisi, copyediting, produksi/galley, diskusi) | `submissionfilesuploadform::validate` (`lib/pkp/classes/form/Form.inc.php:285`) | Divalidasi sebelum file disimpan. Browser menampilkan pesan, tombol Lanjut tetap nonaktif |
| REST API `POST/PUT /submissions/{id}/files` | `SubmissionFile::validate` (`lib/pkp/classes/services/PKPSubmissionFileService.inc.php:291`) | Respons 400 dengan pesan di `fileId`; OJS menghapus file yang sempat disimpan |

Pesan untuk pengguna: *"File berekstensi .phtml tidak diterima karena dapat dijalankan
sebagai program di server. Unggah naskah dalam format DOCX, ODT, atau PDF."* Setiap
penolakan dicatat ke error log PHP, mis.:
`[DocxFontStripper] blocked upload (wizard): ext=phtml name="zip.phtml" userId=812 ip=203.0.113.5`

Tidak dijaga plugin ini (bukan file submission, hanya bisa diunggah manajer/editor): file
pustaka penerbit, gambar sampul, file galley terbitan (issue galley), logo/favicon jurnal.

## Ukuran file di backend

Di daftar file *Workflow* (Submission, Review, Copyediting, Production), *Author Dashboard*,
dan halaman reviewer (*Download & Review*), ukuran setiap file tampil kecil di bawah jenisnya
(di halaman reviewer di bawah tanggal), mis. "Article Text" lalu "8,0 MB". File **5 MB ke atas**
ditandai merah tebal (sering karena font tertanam di Word). Fitur ini sebelumnya ada di tema
Manuscript-Daffa; sekarang tampil apa pun temanya.

- Skrip `js/fileSizes.js` hanya dimuat di halaman backend untuk pengguna yang login, lewat hook
  `TemplateManager::display`. Daftar yang dimuat lewat AJAX ikut ditangani (pemantau perubahan
  halaman, jeda 150 ms).
- Ukuran diminta sekali per naskah per pemuatan halaman: halaman *About this Publishing System*
  jurnal dengan `?docxFontStripperFileSizes=1&submissionId=N` dijawab plugin dengan JSON
  (`{"sizes": {"<submissionFileId>": <byte>}}`), bukan halaman. File yang diunggah setelah
  halaman dibuka baru tampil ukurannya setelah halaman dimuat ulang.
- Ukuran dibaca dari penyimpanan dengan satu kueri (`submission_files` + `files`), tanpa
  mengunduh file dan tanpa tercatat sebagai unduhan (`classes/SubmissionFileSizes.inc.php`).
- Hanya naskah di jurnal yang sedang dibuka, dan hanya untuk pengguna yang terlibat:

  | Pengguna | Ukuran yang didapat |
  |---|---|
  | Manajer jurnal, admin situs, editor/asisten yang ditugaskan | semua file naskah |
  | Penulis yang ditugaskan | file yang terlihat di dasbor penulis (submission, revisi, copyedit, proof, lampiran review yang dibagikan ke penulis) dan unggahannya sendiri |
  | Reviewer | file penugasan review-nya sendiri dan lampirannya |
  | Lainnya | tidak ada (HTTP 403) |

- Ambang 5 MB: `SubmissionFileSizes::LARGE_FILE_BYTES`. Kelas CSS: `.dfs-file-size` dan
  `.dfs-file-size-large`.
- Bila tema Manuscript-Daffa versi lama (yang masih punya fitur ini) aktif, baris yang sudah
  diberi ukuran oleh tema dilewati, jadi ukuran tidak tampil dua kali.

## Ukuran file galley

Sebelumnya ukuran file galley ditampilkan oleh tema Daffa Manuscript lewat
`galley_link.tpl`-nya sendiri, sehingga hilang ketika jurnal memakai tema lain. Plugin ini
menambahkannya tanpa bergantung pada tema:

- Hook `TemplateManager::display` (`lib/pkp/classes/template/PKPTemplateManager.inc.php:1227`)
  mendaftarkan output filter Smarty, yang dijalankan atas HTML akhir seluruh halaman pembaca
  (template `frontend/…` dan template plugin seperti penampil PDF.js). Halaman backend tidak disentuh.
- Setiap `<a href>` yang menunjuk ke galley artikel atau galley terbitan **di jurnal ini**
  (`article/view|download/{artikel}/{galley}`, juga `…/version/{publikasi}/{galley}`, dan
  `issue/view|download/{terbitan}/{galley}`; URL gaya `index.php?journal=…&path[]=…` juga dikenali)
  diberi `<span class="dfs_file_size">(1,2 MB)</span>` di dalam tautan.
- Ukuran dibaca dari file tersimpan (`files_dir`/Flysystem) untuk galley artikel, dan dari
  `issue_files.file_size` untuk galley terbitan. Hanya publikasi/terbitan yang sudah terbit;
  galley URL jarak jauh dilewati.
- Tautan yang **sudah** menampilkan ukuran (di dalam tautan atau tepat sesudahnya, mis. bila tema
  Daffa Manuscript masih aktif) tidak diberi ukuran kedua. Tautan berkelas `dfs_no_size` juga dilewati.
- Format: `820 B`, `17 KB`, `1,2 MB`, `15 MB`, `1,1 GB` (1 KB = 1024 B); pemisah desimal
  mengikuti bahasa pembaca (koma untuk Bahasa Indonesia, titik untuk English).
- Tampilan dapat diatur lewat CSS tema, mis. `.dfs_file_size { opacity: .8; font-size: .9em; }`.

## Konsekuensi yang perlu diketahui

Gambar PNG yang diperkecil kehilangan piksel di atas 500 dpi; pada ukuran tampil di
dokumen tidak terlihat bedanya, tetapi bila gambar itu nanti diekstrak untuk dicetak jauh
lebih besar, resolusi aslinya sudah tidak ada.

Tanpa font tertanam, komputer pembaca yang **tidak** memiliki font tersebut akan memakai
font pengganti. Teks tetap sama, tetapi tata letak bisa bergeser. Pada file contoh,
LibreOffice tanpa font Lato menghasilkan 13 halaman, bukan 12. Ini tujuan plugin
(file kecil untuk alur editorial), bukan bug.

## Hook OJS yang dipakai (pkp-lib tag `3_3_0-22`, commit `fda81987`)

Semua jalur unggah file submission menyimpan file lewat
`Services::get('file')->add()` (`lib/pkp/classes/services/PKPFileService.inc.php:77`),
yang **tidak** memiliki hook. Plugin ini memakai hook yang terpanggil sesudahnya:

| Hook | Lokasi | Kapan |
|---|---|---|
| `SubmissionFile::add` | `lib/pkp/classes/services/PKPSubmissionFileService.inc.php:448` | file baru: wizard unggah (`controllers/wizard/fileUpload/form/SubmissionFilesUploadForm.inc.php:151` → `:185`) untuk submission, review, lampiran reviewer, revisi, copyediting, produksi, diskusi; dan REST `POST /submissions/{id}/files` (`api/v1/submissions/PKPSubmissionFileHandler.inc.php:262` → `:335`) |
| `SubmissionFile::edit` | `lib/pkp/classes/services/PKPSubmissionFileService.inc.php:461` | "Unggah revisi" file yang sudah ada (`SubmissionFilesUploadForm.inc.php:151` → `:158`) dan REST `PUT …/files/{id}` dengan file baru (`PKPSubmissionFileHandler.inc.php:388` → `:400`); hanya diproses bila `$params['fileId']` ada |

`SubmissionFile::add` juga terpanggil saat file disalin ke tahap lain
(`ManageSubmissionFilesForm.inc.php:129`, `PromoteForm.inc.php:118/160`,
`EditorDecisionForm.inc.php:193`) dengan `fileId` yang sama. Supaya file lama tidak ikut
diubah, plugin hanya memproses file yang ukurannya cocok dengan file yang benar-benar
diunggah (`is_uploaded_file`) pada request yang sama.

Penyimpanan diakses lewat Flysystem milik `PKPFileService` (`$fs`). Untuk adapter lokal
(bawaan), file diproses langsung di `files_dir`; untuk adapter lain, file disalin ke
temp, diproses, lalu ditulis balik.

## Aktif/nonaktif per jurnal

Plugin ini plugin generik biasa: kotak centang **Aktifkan** di *Pengaturan → Website →
Plugin → Plugin Terpasang → Docx Font Stripper* disimpan per jurnal
(`plugin_settings.context_id`). Hook hanya didaftarkan bila plugin aktif untuk jurnal
yang sedang diakses.

## Instalasi

1. Unduh `docxFontStripper-<versi>.tar.gz` dari halaman Releases (atau jalankan
   `scripts/build-package.sh`, hasilnya di `dist/`).
2. OJS: *Pengaturan → Website → Plugin → Unggah Plugin Baru* → pilih file `.tar.gz`.
   Nama arsip harus diawali `docxFontStripper` (sudah demikian), karena OJS mencocokkan
   nama arsip dengan direktori di dalamnya (`lib/pkp/classes/plugins/PluginHelper.inc.php:42`).
3. Aktifkan plugin untuk jurnal yang diinginkan.

Alternatif manual: salin isi arsip ke `plugins/generic/docxFontStripper/`, lalu jalankan
`php lib/pkp/tools/installPluginVersion.php plugins/generic/docxFontStripper/version.xml`.

Kebutuhan: ekstensi PHP `zip` dan `dom` (wajib; bila tidak ada, file dilewati dan dicatat ke
log) serta `gd` (untuk memperkecil gambar).

Memperbarui dari versi lama: unggah arsip versi baru lewat tombol **Upgrade** pada baris
plugin di daftar Plugin Terpasang (bukan "Unggah Plugin Baru"); tombol ini hanya tampil untuk
Administrator situs. Nama arsip tetap harus diawali `docxFontStripper`.

## Pengembangan dan tes

Logika pembersih ada di `classes/DocxFontStripper.inc.php`, penjaga unggahan di
`classes/UploadGuard.inc.php`, dan penanda ukuran galley di `classes/GalleyFileSize.inc.php`;
ketiganya kelas murni (tanpa OJS). Ukuran file backend ada di `classes/SubmissionFileSizes.inc.php`
(aturan akses diuji tanpa OJS) dan `js/fileSizes.js`. Tes membuat `.docx` sintetis dengan dan tanpa font tertanam:

```sh
composer install
vendor/bin/phpunit
# opsional, uji dengan file asli (file tidak diubah; hasil ditulis ke temp):
DOCX_SAMPLE=/path/ke/artikel.docx vendor/bin/phpunit --filter testRealSample
```

GitHub Actions (`.github/workflows/ci.yml`) menjalankan tes di PHP 7.4, 8.1, dan 8.2 dan
membangun paket.

Membuat rilis:

1. Naikkan `<release>` di `version.xml` (mis. `1.2.0.0`) dan tulis `release-notes/1.2.0.0.md`.
2. Setelah masuk ke `main`, buat tag `v` + versi tersebut dan push:
   `git tag v1.2.0.0 && git push origin v1.2.0.0`.
3. Bila tes lolos, workflow membuat Release untuk tag itu dengan
   `docxFontStripper-1.2.0.0.tar.gz` terlampir (tag yang tidak cocok dengan `version.xml`
   ditolak). Release yang dibuat manual lewat GitHub juga mendapat lampiran yang sama.
