# Quick Start — React Dashboard

## 🚀 Get Running in 3 Steps

### Step 1: Database Setup
```bash
# Edit .env with your PostgreSQL credentials
nano .env

# Run migrations and seed demo data
php artisan migrate
php artisan db:seed
```

### Step 2: Start Laravel Server (Terminal 1)
```bash
php artisan serve
# Runs on http://localhost:8000
```

### Step 3: Start Vite Dev Server (Terminal 2)
```bash
npm run dev
# Watches for file changes with hot reload
```

## 🌐 Visit the Dashboard

Open browser: **http://localhost:8000/dashboard**

### Pages Available
- **Dashboard** → Real-time device monitor & stats
- **Machines** → Register/manage fingerprint machines  
- **Logs** → View & filter attendance logs
- **Mappings** → Manage biometric → employee ID mappings

---

## 📦 What Was Built

### React Components (Frontend)
✅ **Dashboard.jsx** — Device heartbeat, queue status, stats  
✅ **Machines.jsx** — Add/delete machines  
✅ **AttendanceLogs.jsx** — Filter/search logs, export CSV  
✅ **EmployeeMappings.jsx** — CRUD for biometric mappings  
✅ **Layout.jsx** — Navigation & app shell  

### Backend (Laravel)
✅ **DashboardController** — Routes data to Inertia  
✅ **API Routes** — CRUD endpoints for React frontend  
✅ **Inertia Setup** — Server-side rendering of React  
✅ **Tailwind CSS** — Ready-to-use styling  

### Infrastructure
✅ **Vite** — Fast dev server with HMR  
✅ **Tailwind CSS 4** — Utility-first styling  
✅ **React 19** — Latest React features  
✅ **Axios** — HTTP client (pre-installed)  

---

## 🛠️ Development Tips

### Auto-reload on Changes
- Edit `.jsx` files → automatic browser reload
- Edit `.css` files → automatic refresh
- Edit Laravel code → manual browser refresh needed

### Test the ADMS Core
The fingerprint integration still works independently:
```bash
# Test handshake
curl "http://localhost:8000/iclock/cdata?SN=DEMO001"

# Test data ingestion
curl -X POST "http://localhost:8000/iclock/cdata" \
  --data $'ATTLOG\tSN=DEMO001\t2026-06-15 08:03:22\t1001\t0\t1\t0'
```

### Queue Worker
```bash
php artisan queue:work redis --queue=attendance
# Processes pending jobs from the Redis queue
```

---

## 📁 Key Files Created

```
resources/
├── js/
│   ├── app.jsx                      ← React entry point
│   ├── layouts/Layout.jsx           ← Navbar & routing
│   └── pages/
│       ├── Dashboard.jsx
│       ├── Machines.jsx
│       ├── AttendanceLogs.jsx
│       └── EmployeeMappings.jsx
├── css/app.css                      ← Tailwind + buttons
└── views/app.blade.php              ← Inertia shell

routes/
├── web.php                          ← Dashboard UI routes
└── api.php                          ← CRUD API endpoints

app/Http/Controllers/
├── DashboardController.php          ← Inertia data provider
└── AdmsController.php               ← iClock protocol (unchanged)

app/Models/
├── Machine.php
├── EmployeeMapping.php
└── AttendanceLog.php
```

---

## 🎨 Styling System

### Utility Classes (Tailwind)
```jsx
<button className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
  Click me
</button>
```

### Component Classes (Custom)
```jsx
<button className="btn btn-primary">Click me</button>
<div className="card">Content</div>
<span className="badge badge-success">Online</span>
```

---

## ⚙️ Configuration

### Environment (.env)
```env
APP_ENV=local
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=adms_middleware
REDIS_HOST=127.0.0.1
QUEUE_CONNECTION=redis
```

### Vite (vite.config.js)
- Input: `resources/js/app.jsx`
- Output: `public/build/`
- HMR enabled during dev

### Tailwind (tailwind.config.js)
- Content: `resources/**/*.{js,jsx}`
- Extended colors for UI

---

## 🚀 Production Build

```bash
npm run build              # Creates optimized assets
# Then deploy with Laravel normally
```

---

## 🐛 Troubleshooting

| Issue | Fix |
|-------|-----|
| Blank dashboard | Check `npm run dev` running in terminal 2 |
| CSRF error | Clear cookies, refresh page |
| API 404 errors | Verify `/api` routes in `routes/api.php` |
| Styles not applying | Restart Vite dev server |
| Database error | Check PostgreSQL running, `.env` correct |

---

## 📚 Further Reading

- [Inertia.js Docs](https://inertiajs.com)
- [React Docs](https://react.dev)
- [Tailwind CSS](https://tailwindcss.com)
- [Laravel Docs](https://laravel.com/docs)

---

## ✨ What's Next?

1. **Customize dashboard** — Add more stats, charts (Chart.js)
2. **Real-time updates** — Add WebSockets for live device status
3. **User authentication** — Add login via Laravel Auth
4. **Dark mode** — Toggle Tailwind dark: modifier
5. **Mobile responsive** — Already mobile-friendly, test on phone

---

**Happy building! 🎉**
