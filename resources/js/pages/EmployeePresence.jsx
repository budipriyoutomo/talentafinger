import React, { useEffect, useMemo, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Cpu, Download, Eye, MapPin, X } from 'lucide-react'

// Identitas mesin dalam satu baris teks: "Outlet · Brand · Company".
const placement = (m) => [m?.outlet_name, m?.brand_name, m?.company_name].filter(Boolean).join(' · ')

const machineLabel = (m) => m?.name || m?.serial_number || 'Mesin tak dikenal'

// Badge jalur ADMS (mesin -> server) & TCP 4370 (server -> mesin), sama
// artinya dengan badge di halaman Machines.
function MachineStatus({ machine }) {
  return (
    <div className="flex flex-wrap gap-1">
      {!machine.is_active && <Badge variant="secondary">Nonaktif</Badge>}
      <Badge variant={machine.is_online ? 'success' : 'destructive'}>
        ADMS {machine.is_online ? 'online' : 'offline'}
      </Badge>
      {machine.tcp_ready !== null && (
        <Badge variant={machine.tcp_ready ? 'success' : 'warning'}>
          TCP {machine.tcp_ready ? 'ready' : 'gagal'}
        </Badge>
      )}
    </div>
  )
}

/** Detail satu karyawan: setiap mesin tempat ia absen + identitas mesinnya. */
function PresenceDialog({ row, machineIdentities, filters, onClose }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="mt-10 w-full max-w-3xl rounded-lg bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <MapPin className="h-5 w-5 text-indigo-500" />
            <div>
              <h3 className="font-semibold text-slate-900 dark:text-slate-50">
                {row.name || 'PIN tak dikenal'}
              </h3>
              <p className="text-xs text-slate-500">
                PIN {row.pin}{row.employee_code ? ` · ${row.employee_code}` : ''} · {filters.date_from} s/d {filters.date_to}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup">
            <X className="h-4 w-4" />
          </Button>
        </div>

        <div className="space-y-3 p-4">
          {row.machines.map((pm) => {
            const m = machineIdentities[pm.machine_id] || {}
            return (
              <div key={pm.machine_id} className="rounded-md border border-slate-200 p-3 dark:border-slate-800">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Cpu className="h-4 w-4 shrink-0 text-slate-400" />
                    <span className="font-medium text-slate-900 dark:text-slate-50">{machineLabel(m)}</span>
                  </div>
                  <MachineStatus machine={m} />
                </div>
                <dl className="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                  <dt className="text-slate-500">Serial Number</dt>
                  <dd className="font-mono">{m.serial_number || '-'}</dd>
                  <dt className="text-slate-500">Outlet / Brand / Company</dt>
                  <dd>{placement(m) || 'Belum ditempatkan'}</dd>
                  <dt className="text-slate-500">Lokasi</dt>
                  <dd>{m.location || '-'}</dd>
                  <dt className="text-slate-500">IP : Port</dt>
                  <dd className="font-mono">{m.ip_address ? `${m.ip_address}:${m.sdk_port || 4370}` : '-'}</dd>
                  <dt className="text-slate-500">Terakhir terhubung</dt>
                  <dd>{m.last_seen_at || '-'}</dd>
                  <dt className="text-slate-500">Jumlah absen</dt>
                  <dd className="font-semibold">{pm.total}</dd>
                  <dt className="text-slate-500">Absen pertama</dt>
                  <dd>{pm.first_at}</dd>
                  <dt className="text-slate-500">Absen terakhir</dt>
                  <dd>{pm.last_at}</dd>
                </dl>
                <div className="mt-2 text-right">
                  <Link
                    href={`/attendance-logs?machine_id=${pm.machine_id}&date_from=${filters.date_from}&date_to=${filters.date_to}`}
                    className="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                  >
                    Lihat log di mesin ini →
                  </Link>
                </div>
              </div>
            )
          })}
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
}) {
  const [detail, setDetail] = useState(null)

  const outletOptions = useMemo(
    () => (filters.brand_id ? outlets.filter((o) => o.brand_id === filters.brand_id) : outlets),
    [outlets, filters.brand_id]
  )

  const applyFilters = (next) => {
    const merged = {
      machine_id: filters.machine_id || '',
      brand_id: filters.brand_id || '',
      outlet_id: filters.outlet_id || '',
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

  const hasFilters = filters.machine_id || filters.brand_id || filters.outlet_id

  const columns = useMemo(
    () => [
      {
        id: 'employee',
        header: 'Karyawan',
        // Teks gabungan supaya kotak pencarian cocok ke nama, kode, maupun PIN.
        accessorFn: (r) => [r.name, r.employee_code, r.pin].filter(Boolean).join(' '),
        cell: ({ row }) => {
          const r = row.original
          return r.name ? (
            <div className="text-sm">
              <div className="font-medium">{r.name}</div>
              {r.employee_code && <div className="text-xs text-slate-500">{r.employee_code}</div>}
            </div>
          ) : (
            <Badge variant="warning" title="PIN ini tak cocok dengan karyawan mana pun (atau di luar wewenang Anda)">
              PIN tak dikenal
            </Badge>
          )
        },
      },
      {
        accessorKey: 'pin',
        header: 'PIN',
        cell: ({ row }) => <div className="font-mono text-sm">{row.getValue('pin')}</div>,
      },
      {
        accessorKey: 'machines_count',
        header: 'Jml Mesin',
        cell: ({ row }) => <div className="text-center text-sm font-semibold">{row.getValue('machines_count')}</div>,
      },
      {
        id: 'machine_names',
        header: 'Mesin',
        enableSorting: false,
        accessorFn: (r) => r.machines.map((pm) => machineLabel(machineIdentities[pm.machine_id])).join(' '),
        cell: ({ row }) => (
          <div className="flex max-w-md flex-wrap gap-1">
            {row.original.machines.map((pm) => {
              const m = machineIdentities[pm.machine_id]
              return (
                <Badge key={pm.machine_id} variant="secondary" title={`${m?.serial_number || ''} · ${placement(m)} · ${pm.total} absen`}>
                  {machineLabel(m)}
                </Badge>
              )
            })}
          </div>
        ),
      },
      {
        accessorKey: 'total',
        header: 'Total Absen',
        cell: ({ row }) => <div className="text-sm">{row.getValue('total')}</div>,
      },
      {
        accessorKey: 'last_at',
        header: 'Absen Terakhir',
        cell: ({ row }) => <div className="text-sm">{row.getValue('last_at')}</div>,
      },
      {
        id: 'actions',
        header: 'Action',
        cell: ({ row }) => (
          <Button size="sm" variant="outline" className="gap-1" onClick={() => setDetail(row.original)}>
            <Eye className="h-3 w-3" /> Detail
          </Button>
        ),
      },
    ],
    [machineIdentities]
  )

  // Satu baris CSV per pasangan karyawan x mesin, lengkap dengan identitas mesin.
  const handleExport = () => {
    const header = ['Karyawan', 'Kode', 'PIN', 'Mesin', 'Serial Number', 'Outlet', 'Brand', 'Company', 'Lokasi', 'IP', 'Jumlah Absen', 'Absen Pertama', 'Absen Terakhir']
    const lines = rows.flatMap((r) =>
      r.machines.map((pm) => {
        const m = machineIdentities[pm.machine_id] || {}
        return [
          r.name || 'PIN tak dikenal', r.employee_code, r.pin, machineLabel(m), m.serial_number,
          m.outlet_name, m.brand_name, m.company_name, m.location, m.ip_address,
          pm.total, pm.first_at, pm.last_at,
        ]
      })
    )
    const csv = [header, ...lines]
      .map((row) => row.map((cell) => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
      .join('\n')
    const link = document.createElement('a')
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }))
    link.download = `absen-per-mesin-${filters.date_from}_${filters.date_to}.csv`
    link.click()
  }

  const multiMachine = rows.filter((r) => r.machines_count > 1).length
  const unknownPins = rows.filter((r) => !r.name).length

  return (
    <Layout>
      <Head title="Absen per Mesin" />

      <div className="space-y-6">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Karyawan</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold">{rows.length - unknownPins}</div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Absen di &gt; 1 mesin</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold text-indigo-600">{multiMachine}</div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">PIN tak dikenal</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold text-amber-600">{unknownPins}</div></CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>Karyawan Absen di Mesin Mana</CardTitle>
                <CardDescription>
                  Log mesin {filters.date_from} s/d {filters.date_to} • absen foto & duplikat tidak dihitung • klik Detail untuk identitas mesin
                </CardDescription>
              </div>
              <Button onClick={handleExport} variant="outline" className="gap-2" disabled={rows.length === 0}>
                <Download className="h-4 w-4" /> Export CSV
              </Button>
            </div>
            <div className="flex flex-col gap-3 pt-2 sm:flex-row sm:flex-wrap sm:items-end">
              <div className="space-y-1.5">
                <label className="text-sm font-medium">Dari Tanggal</label>
                <Input type="date" value={filters.date_from || ''} onChange={(e) => applyFilters({ date_from: e.target.value })} className="sm:w-44" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium">Sampai Tanggal</label>
                <Input type="date" value={filters.date_to || ''} onChange={(e) => applyFilters({ date_to: e.target.value })} className="sm:w-44" />
              </div>
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium">Brand</label>
                <Select value={filters.brand_id || ''} onChange={(e) => applyFilters({ brand_id: e.target.value })}>
                  <option value="">Semua Brand</option>
                  {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </Select>
              </div>
              <div className="space-y-1.5 sm:w-48">
                <label className="text-sm font-medium">Outlet</label>
                <Select value={filters.outlet_id || ''} onChange={(e) => applyFilters({ outlet_id: e.target.value })}>
                  <option value="">Semua Outlet</option>
                  {outletOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                </Select>
              </div>
              <div className="space-y-1.5 sm:w-56">
                <label className="text-sm font-medium">Mesin</label>
                <Select value={filters.machine_id || ''} onChange={(e) => applyFilters({ machine_id: e.target.value })}>
                  <option value="">Semua Mesin</option>
                  {machines.map((m) => <option key={m.id} value={m.id}>{m.name || m.serial_number}</option>)}
                </Select>
              </div>
              {hasFilters && (
                <Button variant="outline" className="gap-2" onClick={resetFilters}>
                  <X className="h-4 w-4" /> Reset
                </Button>
              )}
            </div>
          </CardHeader>
          <CardContent>
            <DataTable columns={columns} data={rows} filterPlaceholder="Cari nama, kode, PIN, atau mesin..." />
          </CardContent>
        </Card>
      </div>

      {detail && (
        <PresenceDialog
          row={detail}
          machineIdentities={machineIdentities}
          filters={filters}
          onClose={() => setDetail(null)}
        />
      )}
    </Layout>
  )
}
