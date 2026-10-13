PERBAIKAN SISTEM PERPUSTAKAAN

Perubahan utama:
1. Kelola Data Buku: import CSV/XLSX, template CSV, hapus massal berdasarkan checkbox atau kategori, dan kurangi stok secara massal.
2. Stok buku: ditambahkan stok_total untuk membedakan jumlah eksemplar fisik dengan stok yang sedang tersedia.
3. Peminjaman: sistem menolak peminjaman saat stok 0 dan transaksi + pengurangan stok dibuat atomik.
4. Pengembalian: transaksi + penambahan stok dibuat atomik.
5. Katalog index.php: menampilkan stok tersedia/total dan status Tidak tersedia/dipinjam jika stok 0. Katalog publik, admin, dan mahasiswa tetap menggunakan sumber data buku yang sama.
6. Penghapusan buku yang sudah memiliki riwayat peminjaman diblokir agar histori transaksi tidak hilang.
7. Kategori tidak dapat dihapus jika masih dipakai buku, untuk mencegah ON DELETE CASCADE menghapus koleksi secara tidak sengaja.

Catatan:
- File database SQL yang diperbarui tersedia terpisah sebagai perpustakaan_kampus_uindk_updated.sql.
- Jika memakai database lama, halaman buku.php otomatis mencoba menambahkan stok_total dan menginisialisasi nilainya dari stok tersedia + transaksi aktif.
- Backup folder dan database sebelum dipasang di XAMPP.
