import React, { useEffect, useMemo, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Combobox } from '@/components/ui/combobox'
import { cn } from '@/lib/utils'
import {
  AlertTriangle,
  CalendarDays,
  ChevronRight,
  Clock,
  Download,
  Fingerprint,
  Loader2,
  LogIn,
  LogOut,
  MapPin,
  Network,
  RotateCcw,
  SlidersHorizontal,
  X,
} from 'lucide-react'

// Identitas mesin dalam satu baris teks: "Outlet · Brand · Company".
const placement = (m) => [m?.outlet_name, m?.brand_name, m?.company_name].filter(Boolean).join(' · ')

const machineLabel = (m) => m?.name || m?.serial_number || 'Mesin tak dikenal'

// "2026-10-14 08:00:00" -> "08:00" (tanggal sudah di kolom sendiri).
const timeOf = (dt) => (dt ? dt.slice(11, 16) : '-')

// "YYYY-MM-DD" dibaca sebagai tanggal lokal (new Date('YYYY-MM-DD') = UTC).
const parseDate = (ymd) => {
  const [y, m, d] = ymd.split('-').map(Number)
  return new Date(y, m - 1, d)
}
const toYmd = (date) =>
  [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')

const fmtDay = (ymd) => parseDate(ymd).toLocaleDateString('id-ID', { weekday: 'short' })
const fmtDate = (ymd) => parseDate(ymd).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
const fmtLongDate = (ymd) =>
  parseDate(ymd).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

// Selisih tap pertama & terakhir di hari yang sama -> "8j 30m".
const durationOf = (row) => {
  if (row.total < 2) return null
  const secs = (dt) => {
    const [h, m, s] = dt.slice(11, 19).split(':').map(Number)
    return h * 3600 + m * 60 + s
  }
  const minutes = Math.floor((secs(row.check_out) - secs(row.check_in)) / 60)
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return h ? `${h}j ${m}m` : `${m}m`
}

const initials = (name) =>
  (name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('')

// Rentang cepat untuk filter tanggal.
const datePresets = () => {
  const today = new Date()
  const daysAgo = (n) => toYmd(new Date(today.getFullYear(), today.getMonth(), today.getDate() - n))
  return [
    { label: 'Hari ini', from: daysAgo(0), to: daysAgo(0) },
    { label: 'Kemarin', from: daysAgo(1), to: daysAgo(1) },
    { label: '7 hari', from: daysAgo(6), to: daysAgo(0) },
    { label: '30 hari', from: daysAgo(29), to: daysAgo(0) },
    { label: 'Bulan ini', from: toYmd(new Date(today.getFullYear(), today.getMonth(), 1)), to: daysAgo(0) },
  ]
}


// Pil jam masuk/keluar.
function TimePill({ kind, value }) {
  const isIn = kind === 'in'
  const Icon = isIn ? LogIn : LogOut
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-mono text-xs font-semibold',
        isIn
          ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:ring-emerald-900'
          : 'bg-sky-50 text-sky-700 ring-1 ring-sky-200 dark:bg-sky-950 dark:text-sky-300 dark:ring-sky-900'
      )}
    >
      <Icon className="h-3 w-3" />
      {value}
    </span>
  )
}

function Field({ label, children, className }) {
  return (
    <div className={cn('space-y-1.5', className)}>
      <label className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{label}</label>
      {children}
    </div>
  )
}

/** Detail satu baris: ringkasan tap karyawan di satu mesin pada satu hari. */
function PresenceDialog({ row, onClose }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const duration = durationOf(row)

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="bg-card text-card-foreground mt-10 w-full max-w-xl overflow-hidden rounded-xl border shadow-2xl">
        <div className="flex items-start justify-between gap-3 border-b p-5">
          <div className="flex items-center gap-3">
            <div
              className={cn(
                'flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                row.name
                  ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                  : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
              )}
            >
              {row.name ? initials(row.name) : <AlertTriangle className="h-5 w-5" />}
            </div>
            <div>
              <h3 className="font-semibold">{row.name || 'PIN tak dikenal'}</h3>
              <p className="text-muted-foreground text-xs">
                PIN {row.pin}{row.employee_code ? ` · ${row.employee_code}` : ''}
              </p>
              <p className="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs">
                <CalendarDays className="h-3 w-3" /> {fmtLongDate(row.date)}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup">
            <X className="h-4 w-4" />
          </Button>
        </div>

        <div className="space-y-5 p-5">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {[
              ['Masuk', timeOf(row.check_in), 'text-emerald-600 dark:text-emerald-400'],
              ['Keluar', row.total > 1 ? timeOf(row.check_out) : '—', 'text-sky-600 dark:text-sky-400'],
              ['Durasi', duration || '—', ''],
              ['Jumlah tap', row.total, ''],
            ].map(([label, value, tone]) => (
              <div key={label} className="bg-muted/50 rounded-lg p-3 text-center">
                <div className="text-muted-foreground text-xs">{label}</div>
                <div className={cn('mt-0.5 font-mono text-lg font-bold', tone)}>{value}</div>
              </div>
            ))}
          </div>
        </div>

        <div className="bg-muted/30 flex justify-end gap-2 border-t px-5 py-3">
          <Button variant="outline" onClick={onClose}>Tutup</Button>
        </div>
      </div>
    </div>
  )
}

export default function EmployeePresence({
  rows = [],
  machineIdentities = {},
  filters = {},
  machines = [],
  brands = [],
  outlets = [],
  employees = [],
}) {
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(false)

  // Indikator muat saat filter mengirim request ulang.
  useEffect(() => {
    const offStart = router.on('start', () => setLoading(true))
    const offFinish = router.on('finish', () => setLoading(false))
    return () => {
      offStart()
      offFinish()
    }
  }, [])

  const outletOptions = useMemo(
    () => (filters.brand_id ? outlets.filter((o) => o.brand_id === filters.brand_id) : outlets),
    [outlets, filters.brand_id]
  )

  const employeeOptions = useMemo(
    () =>
      employees.map((e) => ({
        value: e.id,
        label: e.name,
        description: [e.employee_code, `PIN ${e.biometric_id}`].filter(Boolean).join(' · '),
      })),
    [employees]
  )

  const presets = useMemo(datePresets, [])
  const activePreset = presets.find((p) => p.from === filters.date_from && p.to === filters.date_to)

  const applyFilters = (next) => {
    const merged = {
      machine_id: filters.machine_id || '',
      brand_id: filters.brand_id || '',
      outlet_id: filters.outlet_id || '',
      employee_id: filters.employee_id || '',
      date_from: filters.date_from || '',
      date_to: filters.date_to || '',
      ...next,
    }
    // Ganti brand -> reset outlet supaya tak nyangkut di outlet brand lain.
    if (Object.prototype.hasOwnProperty.call(next, 'brand_id')) merged.outlet_id = ''
    router.get('/employee-presence', Object.fromEntries(Object.entries(merged).filter(([, v]) => v)), {
      preserveState: true,
      preserveScroll: true,
      only: ['rows', 'machineIdentities', 'filters'],
    })
  }

  // Reset = kembali ke rentang default server (30 hari terakhir), tanpa filter lain.
  const resetFilters = () =>
    router.get('/employee-presence', {}, { preserveScroll: true, only: ['rows', 'machineIdentities', 'filters'] })

  const activeFilterCount = ['employee_id', 'brand_id', 'outlet_id', 'machine_id'].filter((k) => filters[k]).length

  const columns = useMemo(
    () => [
      {
        accessorKey: 'date',
        header: 'Tanggal',
        cell: ({ row }) => {
          const d = row.getValue('date')
          return (
            <div className="flex items-center gap-3 whitespace-nowrap">
              <div className="bg-muted flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg leading-none">
                <span className="text-muted-foreground text-[10px] uppercase">{fmtDay(d)}</span>
                <span className="text-sm font-bold">{parseDate(d).getDate()}</span>
              </div>
              <span className="text-muted-foreground hidden text-xs xl:inline">{fmtDate(d)}</span>
            </div>
          )
        },
      },
      {
        id: 'employee',
        header: 'Karyawan',
        // Teks gabungan supaya kotak pencarian cocok ke nama, kode, maupun PIN.
        accessorFn: (r) => [r.name, r.employee_code, r.pin].filter(Boolean).join(' '),
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex items-center gap-3">
              <div
                className={cn(
                  'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                  r.name
                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                    : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                )}
              >
                {r.name ? initials(r.name) : <AlertTriangle className="h-4 w-4" />}
              </div>
              <div className="min-w-0 text-sm">
                {r.name ? (
                  <div className="truncate font-medium">{r.name}</div>
                ) : (
                  <div
                    className="font-medium text-amber-700 dark:text-amber-400"
                    title="PIN ini tak cocok dengan karyawan mana pun (atau di luar wewenang Anda)"
                  >
                    PIN tak dikenal
                  </div>
                )}
                <div className="text-muted-foreground truncate text-xs">
                  {[r.employee_code, `PIN ${r.pin}`].filter(Boolean).join(' · ')}
                </div>
              </div>
            </div>
          )
        },
      },
      {
        id: 'machine',
        header: 'Mesin',
        accessorFn: (r) => {
          const m = machineIdentities[r.machine_id]
          return [machineLabel(m), m?.serial_number, m?.outlet_name].filter(Boolean).join(' ')
        },
        cell: ({ row }) => {
          const m = machineIdentities[row.original.machine_id]
          return (
            <div className="flex min-w-0 items-start gap-2 text-sm" title={m?.serial_number}>
              <span
                className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', m?.is_online ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600')}
                title={m?.is_online ? 'ADMS online' : 'ADMS offline'}
              />
              <div className="min-w-0">
                <div className="truncate font-medium">{machineLabel(m)}</div>
                <div className="text-muted-foreground flex items-center gap-1 truncate text-xs">
                  <MapPin className="h-3 w-3 shrink-0" />
                  {placement(m) || 'Belum ditempatkan'}
                </div>
              </div>
            </div>
          )
        },
      },
      {
        accessorKey: 'check_in',
        header: 'Absen Masuk',
        cell: ({ row }) => <TimePill kind="in" value={timeOf(row.original.check_in)} />,
      },
      {
        accessorKey: 'check_out',
        header: 'Absen Keluar',
        // Satu tap saja hari itu = belum ada absen keluar.
        cell: ({ row }) =>
          row.original.total > 1 ? (
            <TimePill kind="out" value={timeOf(row.original.check_out)} />
          ) : (
            <span className="text-muted-foreground inline-flex items-center gap-1 rounded-full border border-dashed px-2.5 py-1 text-xs">
              Belum keluar
            </span>
          ),
      },
      {
        id: 'duration',
        header: 'Durasi',
        accessorFn: (r) => (r.total > 1 ? r.check_out.slice(11) : ''),
        cell: ({ row }) => {
          const d = durationOf(row.original)
          return d ? (
            <span className="inline-flex items-center gap-1 text-sm">
              <Clock className="text-muted-foreground h-3.5 w-3.5" /> {d}
            </span>
          ) : (
            <span className="text-muted-foreground text-sm">—</span>
          )
        },
      },
      {
        id: 'actions',
        header: '',
        cell: ({ row }) => (
          <Button
            size="sm"
            variant="ghost"
            className="text-muted-foreground hover:text-foreground gap-1"
            onClick={() => setDetail(row.original)}
          >
            Detail <ChevronRight className="h-4 w-4" />
          </Button>
        ),
      },
    ],
    [machineIdentities]
  )

  // Satu baris CSV per karyawan x mesin x hari, lengkap dengan identitas mesin.
  const handleExport = () => {
    const header = ['Tanggal', 'Karyawan', 'Kode', 'PIN', 'Mesin', 'Serial Number', 'Outlet', 'Brand', 'Company', 'Absen Masuk', 'Absen Keluar', 'Durasi', 'Jumlah Tap']
    const lines = rows.map((r) => {
      const m = machineIdentities[r.machine_id] || {}
      return [
        r.date, r.name || 'PIN tak dikenal', r.employee_code, r.pin, machineLabel(m), m.serial_number,
        m.outlet_name, m.brand_name, m.company_name,
        r.check_in?.slice(11), r.total > 1 ? r.check_out?.slice(11) : '', durationOf(r) || '', r.total,
      ]
    })
    const csv = [header, ...lines]
      .map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
      .join('\n')
    const link = document.createElement('a')
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
    link.download = `absen-per-mesin-${filters.date_from}_${filters.date_to}.csv`
    link.click()
  }

  return (
    <Layout>
      <Head title="Absen per Mesin" />

      <div className="space-y-6">
        {/* Header halaman */}
        {/* Judul halaman sudah ada di top bar Layout; di sini cukup penjelasan + aksi. */}
        <div className="flex flex-wrap items-center justify-between gap-4">
          <p className="text-muted-foreground flex items-center gap-2 text-sm">
            <Fingerprint className="h-4 w-4 text-indigo-500" />
            Jam masuk & keluar karyawan di setiap mesin, per hari.
          </p>
          <Button onClick={handleExport} variant="outline" className="gap-2" disabled={rows.length === 0}>
            <Download className="h-4 w-4" /> Export CSV
          </Button>
        </div>

        {/* Filter */}
        <Card className="gap-4 py-5">
          <CardContent className="space-y-4 px-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2 text-sm font-medium">
                <SlidersHorizontal className="h-4 w-4" /> Filter
                {activeFilterCount > 0 && <Badge variant="info">{activeFilterCount} aktif</Badge>}
                {loading && <Loader2 className="text-muted-foreground h-4 w-4 animate-spin" />}
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                {presets.map((p) => (
                  <button
                    key={p.label}
                    type="button"
                    onClick={() => applyFilters({ date_from: p.from, date_to: p.to })}
                    className={cn(
                      'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                      activePreset?.label === p.label
                        ? 'border-indigo-600 bg-indigo-600 text-white'
                        : 'hover:bg-accent text-muted-foreground hover:text-foreground'
                    )}
                  >
                    {p.label}
                  </button>
                ))}
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
              <Field label="Dari tanggal">
                <Input type="date" value={filters.date_from || ''} max={filters.date_to || undefined} onChange={(e) => applyFilters({ date_from: e.target.value })} />
              </Field>
              <Field label="Sampai tanggal">
                <Input type="date" value={filters.date_to || ''} min={filters.date_from || undefined} onChange={(e) => applyFilters({ date_to: e.target.value })} />
              </Field>
              <Field label="Karyawan" className="xl:col-span-1">
                <Combobox
                  options={employeeOptions}
                  value={filters.employee_id || ''}
                  onChange={(v) => applyFilters({ employee_id: v })}
                  placeholder="Semua karyawan"
                  searchPlaceholder="Cari nama, kode, atau PIN..."
                  emptyText="Karyawan tidak ditemukan."
                />
              </Field>
              <Field label="Brand">
                <Select value={filters.brand_id || ''} onChange={(e) => applyFilters({ brand_id: e.target.value })}>
                  <option value="">Semua brand</option>
                  {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </Select>
              </Field>
              <Field label="Outlet">
                <Select value={filters.outlet_id || ''} onChange={(e) => applyFilters({ outlet_id: e.target.value })}>
                  <option value="">Semua outlet</option>
                  {outletOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </Select>
              </Field>
              <Field label="Mesin">
                <Select value={filters.machine_id || ''} onChange={(e) => applyFilters({ machine_id: e.target.value })}>
                  <option value="">Semua mesin</option>
                  {machines.map((m) => <option key={m.id} value={m.id}>{m.name || m.serial_number}</option>)}
                </Select>
              </Field>
            </div>

            <div className="text-muted-foreground flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-xs">
              <span className="flex items-center gap-1.5">
                <CalendarDays className="h-3.5 w-3.5" />
                {filters.date_from && fmtDate(filters.date_from)} – {filters.date_to && fmtDate(filters.date_to)}
                <span className="hidden sm:inline">· masuk = tap pertama, keluar = tap terakhir · absen foto & duplikat tidak dihitung</span>
              </span>
              {activeFilterCount > 0 && (
                <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={resetFilters}>
                  <RotateCcw className="h-3 w-3" /> Reset filter
                </Button>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Tabel */}
        <Card className={cn('py-5 transition-opacity', loading && 'pointer-events-none opacity-60')}>
          <CardContent className="px-5">
            {rows.length === 0 ? (
              <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <div className="bg-muted flex h-14 w-14 items-center justify-center rounded-full">
                  <Network className="text-muted-foreground h-6 w-6" />
                </div>
                <div>
                  <div className="font-medium">Belum ada absen mesin</div>
                  <p className="text-muted-foreground text-sm">Coba perluas rentang tanggal atau kurangi filter.</p>
                </div>
                {activeFilterCount > 0 && (
                  <Button variant="outline" size="sm" className="gap-1" onClick={resetFilters}>
                    <RotateCcw className="h-3.5 w-3.5" /> Reset filter
                  </Button>
                )}
              </div>
            ) : (
              <DataTable
                columns={columns}
                data={rows}
                getRowId={(r) => r.key}
                pageSizeOptions={[20, 50, 100]}
                filterPlaceholder="Cari nama, kode, PIN, atau mesin di hasil ini..."
              />
            )}
          </CardContent>
        </Card>
      </div>

      {detail && (
        <PresenceDialog
          row={detail}
          onClose={() => setDetail(null)}
        />
      )}
    </Layout>
  )
}
