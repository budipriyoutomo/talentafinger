# ADMS Middleware — Core Backend

Project Laravel middleware untuk integrasi mesin fingerprint Solution X100-C (protokol iClock) ke Mekari Talenta API.

## Struktur Core yang Sudah Dibangun

### Database & Models
- **3 Migrations**: `machines`, `employee_mappings`, `attendance_logs` (UUID primary keys)
- **3 Models**: `Machine`, `EmployeeMapping`, `AttendanceLog` dengan relationships

### Services
- **AdmsParserService**: Parse raw tab-separated ATTLOG payload dari mesin
- **IdempotencyService**: Cek & mark duplikat log absensi
- **EmployeeMappingService**: Lookup mapping biometric_id → talenta_employee_id
- **MekariTalentaService**: Build HMAC-SHA256 signature & send attendance ke Mekari API

### Controllers & Routes
- **AdmsController**:
  - `GET /iclock/cdata?SN=...` → Handshake device, update last_seen_at, status online
  - `POST /iclock/cdata` → Ingest ATTLOG payload, instant 200 OK response (<50ms)

### Queue Job
- **SendAttendanceToTalenta**: Async job untuk kirim data ke Mekari Talenta
  - Retry 3x dengan backoff: 60s, 120s, 180s
  - Employee mapping lookup → jika tidak ada, mark failed
  - HMAC-SHA256 signed requests
  - Automatic failed() handler untuk update log status

### Tests
- **AdmsHandshakeTest**: Valid SN, invalid SN, missing SN
- **AdmsIngestTest**: Valid payload, duplicate detection, malformed payload, unknown machine

### Configuration
- `.env.example`: PostgreSQL setup (host, port 5432), Redis queue, Mekari API credentials
- `config/mekari.php`: Centralized Mekari API config

## Langkah Setup Lokal

### Prerequisites
- PHP 8.1+ dengan extensions: openssl, fileinfo, pdo_pgsql
- PostgreSQL running (port 5432)
- Redis running (port 6379)

### Install & Run

```bash
cd adms-middleware
cp .env.example .env

# Edit .env untuk setup database:
# DB_HOST, DB_USERNAME, DB_PASSWORD sesuai PostgreSQL lokal

php artisan key:generate
php artisan migrate
php artisan db:seed

# Test handshake
curl "http://localhost:8000/iclock/cdata?SN=DEMO001"

# Test ingest
curl -X POST "http://localhost:8000/iclock/cdata" \
  --data $'ATTLOG\tSN=DEMO001\t2026-06-15 08:03:22\t1001\t0\t1\t0'

# Run tests
php artisan test

# Run queue worker
php artisan queue:work redis --queue=attendance
```

## Core Functionality Checklist

- ✅ FR-01: Device handshake & validation
- ✅ FR-02: ATTLOG payload parsing & ingestion
- ✅ FR-03: Instant 200 OK response (<50ms)
- ✅ FR-04: Async job queue dispatch
- ✅ FR-05: Idempotency filter (duplicate detection)
- ✅ FR-06: Employee ID mapping lookup
- ✅ FR-07: HMAC-SHA256 signature builder
- ✅ FR-08: NTP time sync (server config)
- ✅ FR-09: Rate limiter (configurable via job)
- ✅ FR-10: Error handling & retry backoff
- ⏳ FR-11: Dashboard (not implemented — UI pending)
- ⏳ FR-12: Log viewer (not implemented — UI pending)
- ⏳ FR-13: Queue status (not implemented — UI pending)

## Next Steps

1. **Setup PostgreSQL database** di local/development
2. **Configure Mekari Talenta API credentials** di `.env`
3. **Test dengan real fingerprint data** atau simulasi dengan curl
4. **Implement dashboard UI** (setelah keputusan tech stack front-end)
5. **Deploy ke VPS production** sesuai FASE 2 di PRD

## Architecture Notes

- **UUID Primary Keys**: Semua tabel menggunakan UUID (bukan BIGINT AUTO_INCREMENT)
- **Response Before Job**: Controller return 200 OK SEBELUM dispatch job (FR-03)
- **CSRF Bypass**: Route `/iclock/*` excluded dari CSRF middleware
- **Graceful Failure**: Malformed payload, missing machine, tidak crash app
- **Queue Name**: `attendance` di Redis, configurable via `QUEUE_CONNECTION`

## Database Schema

```sql
machines (id: uuid)
  - serial_number (unique)
  - name, location
  - last_seen_at (nullable)
  - status (online/offline)

employee_mappings (id: uuid)
  - machine_id (FK)
  - biometric_id_lokal
  - talenta_employee_id
  - employee_name (nullable)
  - UNIQUE(machine_id, biometric_id_lokal)

attendance_logs (id: uuid)
  - machine_id (FK)
  - biometric_id_lokal
  - timestamp
  - status_sync (pending|sent|failed|duplicate)
  - payload_raw (nullable)
  - error_message (nullable)
  - INDEX(status_sync)
  - INDEX(machine_id, biometric_id_lokal, timestamp)
```

---

**Ready to build**: Core engine selesai, tinggal integrasikan dengan Mekari Talenta API sandbox, test end-to-end, lalu dashboard UI.
