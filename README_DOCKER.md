# Menjalankan ADMS Middleware dengan Docker

Stack di-containerkan menjadi: **app** (PHP-FPM 8.3 + pyzk), **nginx**, **db**
(PostgreSQL 16), **redis**, **queue** worker, **scheduler**, dan **vpn**
(L2TP/IPSec dial-out ke MikroTik).

## Arsitektur jaringan

```
                          ┌────────────── container vpn ──────────────┐
  Admin (browser) ─:8080─▶│ nginx (:80)  ◀── mesin ZKTeco polling ADMS │  ppp0
                          │ queue worker ──── TCP 4370 ──▶ mesin ZKTeco │ ───▶ VPN
                          └────────────────────────────────────────────┘
        app(php-fpm)  scheduler  db  redis   ← network compose normal
```

- `nginx` & `queue` berbagi network namespace `vpn` (`network_mode: service:vpn`):
  - nginx dijangkau mesin lewat IP tunnel (polling absensi ADMS, inbound) **dan**
    oleh admin lewat port host `WEB_PORT` yang dipublish container `vpn`.
  - queue worker melakukan koneksi keluar TCP 4370 (`zk_sync.py`) ke mesin.
- `app` (php-fpm), `scheduler`, `db`, `redis` di network compose biasa.

## Prasyarat host (WAJIB untuk VPN)

VPN L2TP/IPSec butuh dukungan kernel host:

```bash
sudo modprobe ppp_generic        # menyediakan /dev/ppp
ls -l /dev/ppp                   # harus ada
modprobe af_key esp4 xfrm_user   # dukungan IPsec (umumnya sudah ada)
```

> Catatan: VPN hanya berjalan di host Linux (butuh `/dev/ppp` + NET_ADMIN).
> Di Docker Desktop Windows/Mac device `/dev/ppp` tidak tersedia — jalankan
> stack ini di server Linux.

## Setup

1. Salin env dan isi nilainya:

   ```bash
   cp .env.docker.example .env.docker
   ```

   Wajib diisi:
   - `VPN_PSK`, `VPN_USERNAME`, `VPN_PASSWORD` (PPP secret khusus container —
     minta dibuatkan admin MikroTik), `VPN_SERVER` (default 202.138.226.231),
     `VPN_ROUTES` (default `200.100.100.0/24`).
   - `MEKARI_*` untuk integrasi Talenta.
   - `DB_PASSWORD` yang kuat.

2. Build image:

   ```bash
   docker compose --env-file .env.docker build
   ```

3. Generate `APP_KEY` lalu tempel ke `.env.docker`:

   ```bash
   docker compose --env-file .env.docker run --rm app php artisan key:generate --show
   # salin output (base64:...) ke APP_KEY= di .env.docker
   ```

4. Jalankan:

   ```bash
   docker compose --env-file .env.docker up -d
   ```

   Service `app` otomatis menjalankan `migrate --force`, `storage:link`, dan
   cache config/route/view (production).

5. (Opsional) seed admin awal sekali:

   ```bash
   docker compose --env-file .env.docker run --rm -e RUN_SEED=true app php artisan db:seed --force
   ```

   Login default: `admin@adms.local` / `password` (ganti setelah login).

Dashboard: `http://<host>:8080`.

> Semua perintah `docker compose` HARUS pakai `--env-file .env.docker` agar
> variabel `${VPN_*}`, `${WEB_PORT}`, `${DB_*}` ikut terbaca saat interpolasi.

## Operasional

```bash
# Status & log
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs -f queue
docker compose --env-file .env.docker logs -f vpn

# Cek tunnel VPN naik
docker compose --env-file .env.docker exec vpn ip -4 addr show ppp0

# Tes konektivitas ke mesin lewat VPN (dari namespace vpn = tempat queue jalan)
docker compose --env-file .env.docker exec vpn ping -c3 200.100.100.x

# Artisan
docker compose --env-file .env.docker exec app php artisan <cmd>

# Rebuild setelah update kode/aset
docker compose --env-file .env.docker up -d --build
```

## Testing di dalam container (HATI-HATI)

Test memakai `RefreshDatabase`. **Jangan** jalankan ke DB produksi. Pakai DB
test terpisah dan bersihkan cache config dulu:

```bash
docker compose --env-file .env.docker exec \
  -e DB_DATABASE=adms_test app \
  bash -lc "php artisan config:clear && php artisan test"
```

## Troubleshooting

- **`ppp0` tidak naik**: cek `logs vpn`; pastikan `/dev/ppp` ada di host,
  PSK/credential benar, dan MikroTik mengizinkan akun ini. Encoding server =
  `aes-cbc + sha1` (sudah dicocokkan di proposal IPsec).
- **nginx/queue mati saat vpn restart**: keduanya berbagi namespace vpn; dengan
  `restart: unless-stopped` mereka akan ikut restart dan menyambung kembali.
- **Mesin tidak bisa polling server**: pastikan MikroTik me-route balik ke IP
  tunnel container, dan mesin diarahkan ke IP `ppp0` container (atau hostname
  yang sesuai) di port 80.
- **Aset frontend berubah**: rebuild (`up -d --build`) — aset dibundel di image
  nginx, bukan volume.
```
