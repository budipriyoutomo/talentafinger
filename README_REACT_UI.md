# React UI for ADMS Middleware

Built with **Inertia.js** — Laravel server-side routing with React components.

## Architecture Overview

```
Laravel Routes (web.php)
    ↓
DashboardController
    ↓
Inertia::render('PageName', $data)
    ↓
React Components (resources/js/pages/)
    ↓
User Browser
```

## Setup & Running

### Prerequisites
- PostgreSQL running on localhost:5432
- Redis running on localhost:6379
- PHP 8.1+

### Installation

```bash
# Install PHP dependencies (already done)
composer install

# Install Node dependencies (already done)
npm install

# Setup database
cp .env.example .env
# Edit .env with your database credentials

php artisan key:generate
php artisan migrate
php artisan db:seed

# Optional: Create some test data
php artisan tinker
# > Machine::create(['serial_number' => 'TEST001', 'name' => 'Test', 'status' => 'offline'])
```

### Development

```bash
# Terminal 1: Start Laravel dev server
php artisan serve          # Runs on http://localhost:8000

# Terminal 2: Start Vite dev server (HMR - hot module replacement)
npm run dev               # Vite on http://localhost:5173
```

Then visit: **http://localhost:8000/dashboard**

### Production Build

```bash
npm run build             # Creates public/build/ with hashed assets
php artisan serve        # Serve with production APP_ENV=production
```

## Project Structure

```
resources/
├── js/
│   ├── app.jsx                  # React entry point (Inertia setup)
│   ├── layouts/
│   │   └── Layout.jsx           # Main layout with navbar
│   └── pages/
│       ├── Dashboard.jsx        # Device monitor + queue status
│       ├── Machines.jsx         # Machine CRUD
│       ├── AttendanceLogs.jsx   # Log viewer with filters
│       └── EmployeeMappings.jsx # Mapping CRUD
├── css/
│   └── app.css                  # Tailwind + custom components
└── views/
    └── app.blade.php            # Inertia shell (single page app)

routes/
├── web.php                      # Dashboard UI routes → Inertia
└── api.php                      # API endpoints for JS frontend
```

## Pages

### 1. Dashboard (`/dashboard`)
- Real-time device heartbeat (5-second auto-refresh)
- Stats cards: online machines, logs today, sent, failed
- Device status grid with last_seen_at
- Queue status summary

### 2. Machines (`/machines`)
- List all registered fingerprint machines
- Create new machine (SN, name, location)
- Delete machine
- Display online/offline status

### 3. Attendance Logs (`/attendance-logs`)
- View all logs with pagination
- Filters: status, machine, biometric_id, date range
- Search by employee name
- Export to CSV
- Error details on click
- Success rate calculation

### 4. Employee Mappings (`/employee-mappings`)
- List all biometric → Talenta ID mappings
- Add mapping (machine + biometric → employee)
- Delete mapping
- Filter by machine
- Stats: total, per machine

## API Endpoints

All routes prefixed with `/api/`:

- `GET /machines` — list all machines
- `POST /machines` — create machine (body: serial_number, name, location)
- `DELETE /machines/{id}` — delete machine

- `GET /employee-mappings` — list all mappings
- `POST /employee-mappings` — create mapping
- `DELETE /employee-mappings/{id}` — delete mapping

- `GET /attendance-logs?status=sent&machine_id=...&search=...` — list logs with filters
- `GET /dashboard-stats` — summary stats

## Key Technologies

| Layer | Tool |
|-------|------|
| Frontend Framework | React 19 |
| Server-side Routing | Inertia.js 3 |
| Styling | Tailwind CSS 4 |
| HTTP Client | Axios |
| Backend | Laravel 13 |
| Database | PostgreSQL |
| Build Tool | Vite 8 |

## Component Hierarchy

```
Layout (navbar + routing)
├── Dashboard
│   ├── Stats Cards
│   ├── Device Heartbeat Grid
│   └── Queue Status
├── Machines
│   ├── Add Form
│   └── Machine List
├── AttendanceLogs
│   ├── Filters
│   ├── Search
│   ├── Table
│   └── Stats
└── EmployeeMappings
    ├── Add Form
    ├── Filter Dropdown
    └── Mapping Table
```

## Styling

### Tailwind Components (see resources/css/app.css)
- `.btn`, `.btn-primary`, `.btn-danger`, `.btn-success`, `.btn-outline`
- `.card` — white box with shadow
- `.badge`, `.badge-success`, `.badge-danger`, `.badge-warning`

### Custom Colors
```css
--primary: #3b82f6 (blue)
--success: #10b981 (green)
--danger: #ef4444 (red)
--warning: #f59e0b (orange)
```

## Hot Module Replacement (HMR)

During `npm run dev`:
- Edit React components → browser auto-refreshes (no full page reload)
- Edit Tailwind CSS → browser auto-refreshes
- Change Laravel route or controller → manually refresh browser

## Debugging Tips

### React DevTools
Install Chrome extension: "React Developer Tools"
- Browse component tree
- Inspect props

### Network Tab
- Check API calls: `/api/machines`, `/api/dashboard-stats`
- Verify response status (200 = success, 422 = validation error)

### Browser Console
- Logs from React components
- API errors (404, 500, etc)

### Laravel Logs
```bash
tail -f storage/logs/laravel.log
```

## Troubleshooting

### Blank page on `/dashboard`
- Check browser console for JS errors
- Verify `npm run dev` is running in another terminal
- Restart `php artisan serve`

### CSRF token mismatch
- Ensure `.env` has `SESSION_DRIVER=database` or `cookie`
- Check Blade template has `<meta name="csrf-token">`

### Styles not loading
- Verify `npm run dev` is running
- Clear browser cache (Ctrl+Shift+Delete)

### API returns 404
- Check `routes/api.php` exists
- Verify Laravel route is defined in `web.php` (for Inertia) or `api.php` (for AJAX)

## Extending the UI

### Adding a New Page
1. Create `resources/js/pages/NewPage.jsx`
2. Add route in `routes/web.php`: `Route::get('/new-page', ...)`
3. Add controller method in `DashboardController`
4. Add navbar link in `Layout.jsx`

### Adding a New API Endpoint
1. Add route in `routes/api.php`
2. Call from React component via `fetch('/api/endpoint')`
3. Handle response with `.json()` and `.then()`

### Styling a Component
- Use Tailwind utility classes: `className="flex justify-between items-center"`
- Use custom components: `className="btn btn-primary"`
- Keep CSS in `resources/css/app.css` under `@layer components`

## Performance Notes

- Device heartbeat refresh every 5 seconds (configurable in Dashboard.jsx)
- Attendance logs limited to 500 records (configurable in API)
- Inertia caches components automatically
- Tailwind CSS purges unused styles in production

## Production Deployment

1. Set `APP_ENV=production` in `.env`
2. Run `npm run build` to create optimized assets
3. Laravel automatically serves hashed assets from `public/build/manifest.json`
4. No separate Node.js server needed — just Laravel!

---

**Built with ❤️ using Inertia.js & React**
