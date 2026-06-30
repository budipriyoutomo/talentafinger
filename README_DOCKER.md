# Menjalankan ADMS Middleware dengan Docker

Stack di-containerkan menjadi: **app** (PHP-FPM 8.3 + pyzk), **nginx**,
**queue** worker, **scheduler**, dan **vpn** (L2TP/IPSec dial-out ke MikroTik).

> **Deploy ini:** PostgreSQL **di luar VPS** (tidak ada service `db`); queue,
> cache, dan session pakai **driver database** (tidak ada service `redis`);
> HTTPS diterminasi **Cloudflare** (proxied) — origin melayani HTTP saja.

## Arsitektur jaringan

```
  Visitor ─https─▶ Cloudflare (proxied) ─http─▶ VPS_IP:${WEB_PORT}
                                                      │
                          ┌──────────────── container vpn ─────────────┐
                          │ nginx (:80)  ◀── mesin ZKTeco polling ADMS  │  ppp0
                          │ queue worker ──── TCP 4370 ──▶ mesin ZKTeco │ ───▶ VPN
                          └─────────────────────────────────────────────┘
        app(php-fpm)   scheduler          ← network compose normal
                  │
                  └──── TCP 5432 ────▶ PostgreSQL EKSTERNAL (luar VPS)
```

- `nginx` & `queue` berbagi network namespace `vpn` (`network_mode: service:vpn`):
  - nginx dijangkau mesin lewat IP tunnel (polling absensi ADMS, inbound) **dan**
    oleh Cloudflare lewat port host `WEB_PORT` yang dipublish container `vpn`.
  - queue worker melakukan koneksi keluar TCP 4370 (`zk_sync.py`) ke mesin.
- `app` (php-fpm) & `scheduler` di network compose biasa; keduanya konek ke
  **PostgreSQL eksternal** (`DB_HOST`) dan menyimpan job antrean di tabel `jobs`.

## HTTPS via Cloudflare (proxied)

1. DNS: buat A record domain → IP VPS, **proxy ON** (awan oranye).
2. SSL/TLS mode di dashboard Cloudflare:
   - **Flexible** (paling cepat jalan): Cloudflare→origin HTTP. Cukup untuk mulai.
   - **Full (strict)** (disarankan): pasang *Origin Certificate* gratis Cloudflare
     di nginx (listen 443 ssl) lalu publish `WEB_PORT=443`. Lebih aman.
3. Laravel sudah mempercayai header `X-Forwarded-Proto` dari Cloudflare
   (`bootstrap/app.php` → `trustProxies`), jadi URL/redirect/secure-cookie
   memakai `https` otomatis. Set `APP_URL=https://domain` & `SESSION_SECURE_COOKIE=true`.
4. **Wajib keamanan:** origin (`WEB_PORT`) terbuka ke internet. Batasi firewall
   host agar hanya menerima dari [IP range Cloudflare](https://www.cloudflare.com/ips/),
   mis. dengan `ufw`/`iptables`, supaya tak ada yang bypass Cloudflare langsung ke IP VPS.
   (Alternatif lebih rapi: pakai **Cloudflare Tunnel/cloudflared** agar tidak ada
   port yang perlu dibuka sama sekali.)

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
   - `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` → PostgreSQL
     **eksternal**. Pastikan VPS bisa menjangkau host:port-nya dan `pg_hba`/
     firewall penyedia DB mengizinkan IP VPS.
   - `APP_URL=https://domain-anda`.

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

Dashboard: `https://<domain-anda>` (lewat Cloudflare).

> Semua perintah `docker compose` HARUS pakai `--env-file .env.docker` agar
> variabel `${VPN_*}`, `${WEB_PORT}`, `${DB_*}` ikut terbaca saat interpolasi.
>
> **DB eksternal:** `app` tetap menjalankan `migrate --force` saat start. Migrasi
> sudah termasuk tabel `jobs`, `cache`, `sessions` yang dibutuhkan driver database.

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
