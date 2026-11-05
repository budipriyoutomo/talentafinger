import React, { useMemo } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { cn } from '@/lib/utils'
import { AlertTriangle, CalendarX, Camera, Download, Send, ShieldAlert, Timer, X } from 'lucide-react'

// Urutan & tampilan jenis pengecualian (label dari server: type_labels).
const TYPE_META = {
  no_attendance: { icon: CalendarX, variant: 'secondary' },
  single_punch: { icon: Timer, variant: 'warning' },
  review_pending: { icon: Camera, variant: 'info' },
  send_failed: { icon: Send, variant: 'destructive' },
  risk_flagged: { icon: ShieldAlert, variant: 'warning' },
}
const TYPES = Object.keys(TYPE_META)

/**
 * Laporan pengecualian absensi per HARI KERJA: siapa yang perlu ditindaklanjuti.
 * Tanpa data jadwal (milik Talenta), jadi "Tidak ada absen" bisa saja libur.
 */
export default function AttendanceExceptions({
  filters = {},
  rows = [],
  total_rows = 0,
  max_rows = 5000,
  summary = {},
  per_outlet = [],
  employee_count = 0,
  type_labels = {},
  workday_cutoff = '00:00',
  brands = [],
  outlets = [],
}) {
  const { errors = {} } = usePage().props

  const outletOptions = useMemo(
    () => (filters.brand_id ? outlets.filter((o) => o.brand_id === filters.brand_id) : outlets),
    [outlets, filters.brand_id]
  )

  const query = (next = {}) => {
    const merged = {
      date_from: filters.date_from || '',
      date_to: filters.date_to || '',
      brand_id: filters.brand_id || '',
      outlet_id: filters.outlet_id || '',
      type: filters.type || '',
      ...next,
    }
    if (Object.prototype.hasOwnProperty.call(next, 'brand_id')) merged.outlet_id = ''
    return Object.fromEntries(Object.entries(merged).filter(([, v]) => v))
  }

  const apply = (next) => {
    router.get('/attendance-exceptions', query(next), { preserveState: true, preserveScroll: true })
  }

  const exportUrl = `/attendance-exceptions/export?${new URLSearchParams(query()).toString()}`

  const columns = useMemo(
    () => [
      { accessorKey: 'date', header: 'Hari Kerja', cell: ({ row }) => <span className="whitespace-nowrap text-sm">{row.original.date}</span> },
      {
        accessorKey: 'name',
        header: 'Karyawan',
        cell: ({ row }) => (
          <div className="text-sm">
            <div className="font-medium">{row.original.name}</div>
            <div className="text-xs text-muted-foreground">
              {[row.original.employee_code, row.original.biometric_id && `PIN ${row.original.biometric_id}`].filter(Boolean).join(' · ') || '-'}
            </div>
          </div>
        ),
      },
      {
        accessorKey: 'outlet_name',
        header: 'Outlet',
        cell: ({ row }) => (
          <div className="text-sm">
            <div>{row.original.outlet_name || '-'}</div>
            <div className="text-xs text-muted-foreground">{row.original.brand_name}</div>
          </div>
        ),
      },
      {
        accessorKey: 'punches',
        header: 'Absen',
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="whitespace-nowrap text-sm">
              {r.punches}×{r.first_at && <span className="text-muted-foreground"> · {r.first_at}{r.last_at !== r.first_at ? `–${r.last_at}` : ''}</span>}
            </div>
          )
        },
      },
      {
        id: 'types',
        header: 'Pengecualian',
        enableSorting: false,
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1">
            {row.original.types.map((t) => (
              <Badge key={t} variant={TYPE_META[t]?.variant || 'secondary'}>{type_labels[t] || t}</Badge>
            ))}
          </div>
        ),
      },
    ],
    [type_labels]
  )

  const hasFilters = filters.brand_id || filters.outlet_id || filters.type

  return (
    <Layout>
      <Head title="Pengecualian Absensi" />

      <div className="space-y-6">
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>Pengecualian Absensi</CardTitle>
                <CardDescription>
                  Per hari kerja (ganti hari pukul {workday_cutoff}) untuk {employee_count} karyawan aktif. Tanpa data jadwal,
                  “Tidak ada absen” bisa berarti libur — periksa sebelum menindaklanjuti.
                </CardDescription>
              </div>
              <Button asChild variant="outline" className="gap-2">
                <a href={exportUrl}>
                  <Download className="h-4 w-4" /> Export CSV
                </a>
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Dari</label>
                <Input type="date" value={filters.date_from || ''} onChange={(e) => apply({ date_from: e.target.value })} className="sm:w-44" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Sampai</label>
                <Input type="date" value={filters.date_to || ''} onChange={(e) => apply({ date_to: e.target.value })} className="sm:w-44" />
              </div>
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium text-foreground">Brand</label>
                <Select value={filters.brand_id || ''} onChange={(e) => apply({ brand_id: e.target.value })}>
                  <option value="">Semua Brand</option>
                  {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </Select>
              </div>
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium text-foreground">Outlet</label>
                <Select value={filters.outlet_id || ''} onChange={(e) => apply({ outlet_id: e.target.value })}>
                  <option value="">Semua Outlet</option>
                  {outletOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </Select>
              </div>
              <div className="space-y-1.5 sm:w-52">
                <label className="text-sm font-medium text-foreground">Jenis</label>
                <Select value={filters.type || ''} onChange={(e) => apply({ type: e.target.value })}>
                  <option value="">Semua jenis</option>
                  {TYPES.map((t) => <option key={t} value={t}>{type_labels[t] || t}</option>)}
                </Select>
              </div>
              {hasFilters && (
                <Button variant="outline" className="gap-2" onClick={() => apply({ brand_id: '', outlet_id: '', type: '' })}>
                  <X className="h-4 w-4" /> Reset
                </Button>
              )}
            </div>
            {(errors.date_to || errors.date_from) && (
              <p className="mt-3 flex items-center gap-1.5 text-sm text-red-600 dark:text-red-400">
                <AlertTriangle className="h-4 w-4" /> {errors.date_to || errors.date_from}
              </p>
            )}
          </CardContent>
        </Card>

        {/* Kartu ringkasan: klik = filter jenis itu */}
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
          {TYPES.map((t) => {
            const Icon = TYPE_META[t].icon
            const active = filters.type === t
            return (
              <button
                key={t}
                type="button"
                onClick={() => apply({ type: active ? '' : t })}
                className={cn(
                  'rounded-xl border bg-card p-4 text-left shadow-sm transition hover:border-indigo-300',
                  active && 'border-indigo-500 ring-2 ring-indigo-500/30'
                )}
              >
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Icon className="h-4 w-4" /> {type_labels[t] || t}
                </div>
                <div className="mt-1 text-2xl font-bold">{summary[t] ?? 0}</div>
              </button>
            )
          })}
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Rekap per Outlet</CardTitle>
            <CardDescription>Karyawan multi-outlet dihitung sekali per hari, di outlet tempat absen pertamanya (atau outlet pertamanya bila tak absen).</CardDescription>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            {per_outlet.length === 0 ? (
              <p className="text-sm text-muted-foreground">Tidak ada pengecualian.</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="py-2 pr-4 font-medium">Outlet</th>
                    {TYPES.map((t) => <th key={t} className="py-2 pr-4 text-right font-medium">{type_labels[t] || t}</th>)}
                  </tr>
                </thead>
                <tbody>
                  {per_outlet.map((o) => (
                    <tr key={o.outlet_id || 'none'} className="border-b last:border-0">
                      <td className="py-2 pr-4">
                        <button type="button" className="text-left hover:underline" onClick={() => o.outlet_id && apply({ outlet_id: o.outlet_id })}>
                          {o.outlet_name || '-'}
                        </button>
                        <div className="text-xs text-muted-foreground">{o.brand_name}</div>
                      </td>
                      {TYPES.map((t) => (
                        <td key={t} className={cn('py-2 pr-4 text-right tabular-nums', !o[t] && 'text-muted-foreground')}>{o[t]}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Detail</CardTitle>
            <CardDescription>
              {total_rows > rows.length
                ? `Menampilkan ${rows.length} dari ${total_rows} baris. Gunakan Export CSV untuk semua baris.`
                : `${total_rows} baris`}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <DataTable columns={columns} data={rows} getRowId={(r) => r.key} filterPlaceholder="Cari nama / PIN / outlet..." />
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
