# pin-crack (ringkasan Bahasa Indonesia)

Alat pemulihan **rahasia pendek** — PIN, passcode numerik, password 4–8
karakter — dari hash yang bocor. PHP murni, tanpa dependensi.

Dokumentasi lengkap ada di [README.md](README.md) (Bahasa Inggris), karena
antarmuka dan pesan alat ini berbahasa Inggris.

## Intinya

Yang membuat PIN dapat dipulihkan bukan algoritma hash-nya, tetapi **ruang
kunci**:

> PIN 6 digit hanya punya 10⁶ = 1.000.000 kemungkinan. bcrypt bersalt dengan
> cost 12 tidak mengubah angka itu. Yang berubah hanya berapa lama pemulihan
> yang **pasti berhasil** itu berjalan. Dan karena pemulihannya luring, tidak
> ada rate limit, lockout, atau MFA di sisi server yang menghalangi.

Jadi kalau sebuah hash PIN bocor, menaikkan cost bcrypt bukan remediasinya.
Yang menyelesaikan masalah adalah: jangan kirim kolom hash itu pada respons
API, dan perbesar ruang kuncinya.

## Pemakaian singkat

```bash
# wordlist PIN umum, tipe hash dideteksi otomatis
php bin/pin-crack --hash '$2y$12$...'

# ruang kunci penuh 6 digit, mesin idle, bisa dilanjutkan
php bin/pin-crack --hash '$2y$12$...' --full --max
php bin/pin-crack --hash '$2y$12$...' --full --max --resume

# banyak hash, keluaran JSON, hasil ditulis ke berkas
php bin/pin-crack --hash-file hashes.txt --full --json --out recovered.txt

# berapa lama ruang kunci penuh di mesin ini?
php bin/pin-crack --hash '$2y$12$...' --bench
```

## Catatan untuk laporan pengujian

- Urutan kandidat bawaan disusun dari yang paling umum, dan posisi temuan
  ikut dilaporkan ("kandidat ke-418.393 dari 1.000.000"). Itu yang membuat
  hasilnya bisa dipertanggungjawabkan, bukan sekadar "berhasil".
- `--include` menyisipkan kandidat di posisi depan, sehingga hasilnya **tidak
  lagi** mewakili serangan kamus yang sebenarnya. Alat ini mencetak peringatan
  kalau opsi itu dipakai.
- Angka `[BURST]` pada `--bench` berasal dari sapuan ~20 detik dan masih
  menangkap turbo boost. Untuk angka yang masuk tiket, pakai baris
  `sustained`, atau ukur sendiri dengan `--full` sampai tuntas.
- Angka GPU yang disebut pada keluaran `--bench` adalah **estimasi publik**,
  bukan hasil pengukuran alat ini. Tulis begitu juga di laporan.

## Hanya untuk pengujian yang diotorisasi

Jalankan hanya terhadap hash yang Anda memang berhak uji, dan simpan hash
serta hasil pemulihannya seperti material klien lainnya. Lihat
[SECURITY.md](SECURITY.md).
