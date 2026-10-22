# Docx Font Stripper (OJS 3.3)

Plugin generik OJS 3.3.0-x (diuji terhadap kode **3.3.0-22**; PHP 7.4–8.2) yang
membuang **font tertanam** dari file Word `.docx` saat diunggah, supaya ukuran file kecil.
Contoh nyata: artikel 15,0 MB dengan 14 font tertanam (dan semua entri ZIP tanpa
kompresi) menjadi 0,49 MB; sisanya hampir seluruhnya gambar.

## Yang diubah dalam .docx

| Bagian | Tindakan |
|---|---|
| `word/fonts/*` (`*.odttf` dll.) dan semua target relasi bertipe `…/relationships/font` | dihapus |
| `word/_rels/fontTable.xml.rels` | relasi font dihapus; bila kosong, bagian ini dihapus |
| `word/fontTable.xml` | `w:embedRegular`, `w:embedBold`, `w:embedItalic`, `w:embedBoldItalic` dihapus (deklarasi `w:font` tetap) |
| `[Content_Types].xml` | `Override` untuk bagian yang dihapus, dan `Default` ekstensi (mis. `odttf`) yang tak lagi dipakai, dihapus |
| `word/settings.xml` | `w:embedTrueTypeFonts` dan `w:saveSubsetFonts` dihapus, supaya Word tidak menanam ulang saat disimpan |
| Entri lain yang tersimpan **tanpa kompresi** (mis. `document.xml`) | dikompresi ulang (deflate); isinya tetap identik byte per byte |

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
- Bila tidak ada font tertanam, file tidak diubah sama sekali.
- Setiap hasil dicatat ke error log PHP, mis.:
  `[DocxFontStripper] submissionFileId=12 fileId=34 journals/1/articles/5/….docx: stripped: 14,953,562 -> 489,826 bytes (-96.7%), 15 part(s) removed, 3 XML part(s) updated, 24 part(s) recompressed`
- Kesalahan apa pun ditangkap; unggahan tidak pernah gagal karena plugin ini.

## Konsekuensi yang perlu diketahui

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

Kebutuhan: ekstensi PHP `zip` dan `dom`. Bila tidak ada, plugin melewati file (dan mencatatnya ke log).

## Pengembangan dan tes

Logika pembersih ada di `classes/DocxFontStripper.inc.php` sebagai kelas murni (tanpa
OJS). Tes membuat `.docx` sintetis dengan dan tanpa font tertanam:

```sh
composer install
vendor/bin/phpunit
# opsional, uji dengan file asli (file tidak diubah; hasil ditulis ke temp):
DOCX_SAMPLE=/path/ke/artikel.docx vendor/bin/phpunit --filter testRealSample
```

GitHub Actions (`.github/workflows/ci.yml`) menjalankan tes di PHP 7.4, 8.1, dan 8.2,
membangun paket, dan saat sebuah Release dipublikasikan, melampirkan
`docxFontStripper-<versi>.tar.gz` ke Release tersebut. Naikkan `<release>` di
`version.xml` sebelum membuat Release.
