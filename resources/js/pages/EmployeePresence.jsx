import React, { useEffect, useMemo, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Combobox } from '@/components/ui/combobox'
import { Cpu, Download, Eye, MapPin, X } from 'lucide-react'

// Identitas mesin dalam satu baris teks: "Outlet · Brand · Company".
const placement = (m) => [m?.outlet_name, m?.brand_name, m?.company_name].filter(Boolean).join(' · ')

const machineLabel = (m) => m?.name || m?.serial_number || 'Mesin tak dikenal'

// "2026-10-14 08:00:00" -> "08:00:00" (tanggal sudah di kolom sendiri).
const timeOf = (dt) => (dt ? dt.slice(11) : '-')

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

/** Detail satu baris: absen karyawan di satu mesin pada satu hari + identitas mesinnya. */
function PresenceDialog({ row, machineIdentities, onClose }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const m = machineIdentities[row.machine_id] || {}

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      onClick={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className="mt-10 w-full max-w-2xl rounded-lg bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <MapPin className="h-5 w-5 text-indigo-500" />
            <div>
              <h3 className="font-semibold text-slate-900 dark:text-slate-50">
                {row.name || 'PIN tak dikenal'}
              </h3>
              <p className="text-xs text-slate-500">
                PIN {row.pin}{row.employee_code ? ` · ${row.employee_code}` : ''} · {row.date}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup">
            <X className="h-4 w-4" />
          </Button>
        </div>

        <div className="p-4">
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
            <dt className="text-slate-500">Absen masuk</dt>
            <dd>{row.check_in}</dd>
            <dt className="text-slate-500">Absen keluar</dt>
            <dd>{row.total > 1 ? row.check_out : '-'}</dd>
            <dt className="text-slate-500">Jumlah tap hari itu</dt>
            <dd className="font-semibold">{row.total}</dd>
          </dl>
          <div className="mt-3 text-right">
            <Link
              href={`/attendance-logs?machine_id=${row.machine_id}&date_from=${row.date}&date_to=${row.date}`}
              className="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
            >
              Lihat log di mesin ini →
            </Link>
          </div>
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

  const hasFilters = filters.machine_id || filters.brand_id || filters.outlet_id || filters.employee_id

  const columns = useMemo(
    () => [
      {
        accessorKey: 'date',
        header: 'Tanggal',
        cell: ({ row }) => <div className="whitespace-nowrap text-sm">{row.getValue('date')}</div>,
      },
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
              <div className="text-xs text-slate-500">{[r.employee_code, `PIN ${r.pin}`].filter(Boolean).join(' · ')}</div>
            </div>
          ) : (
            <div className="text-sm">
              <Badge variant="warning" title="PIN ini tak cocok dengan karyawan mana pun (atau di luar wewenang Anda)">
                PIN tak dikenal
              </Badge>
              <div className="mt-0.5 font-mono text-xs text-slate-500">PIN {r.pin}</div>
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
            <div className="text-sm" title={m?.serial_number}>
              <div className="font-medium">{machineLabel(m)}</div>
              {placement(m) && <div className="text-xs text-slate-500">{placement(m)}</div>}
            </div>
          )
        },
      },
      {
        accessorKey: 'check_in',
        header: 'Absen Masuk',
        cell: ({ row }) => <div className="font-mono text-sm">{timeOf(row.original.check_in)}</div>,
      },
      {
        accessorKey: 'check_out',
        header: 'Absen Keluar',
        // Satu tap saja hari itu = belum ada absen keluar.
        cell: ({ row }) => (
          <div className="font-mono text-sm" title={`${row.original.total} tap`}>
            {row.original.total > 1 ? timeOf(row.original.check_out) : <span className="text-slate-400">-</span>}
          </div>
        ),
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

  // Satu baris CSV per karyawan x mesin x hari, lengkap dengan identitas mesin.
  const handleExport = () => {
    const header = ['Tanggal', 'Karyawan', 'Kode', 'PIN', 'Mesin', 'Serial Number', 'Outlet', 'Brand', 'Company', 'Absen Masuk', 'Absen Keluar', 'Jumlah Tap']
    const lines = rows.map((r) => {
      const m = machineIdentities[r.machine_id] || {}
      return [
        r.date, r.name || 'PIN tak dikenal', r.employee_code, r.pin, machineLabel(m), m.serial_number,
        m.outlet_name, m.brand_name, m.company_name,
        timeOf(r.check_in), r.total > 1 ? timeOf(r.check_out) : '', r.total,
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

  // Kartu ringkasan dihitung per PIN unik (satu PIN muncul di banyak baris).
  const stats = useMemo(() => {
    const byPin = new Map()
    rows.forEach((r) => {
      const entry = byPin.get(r.pin) || { known: !!r.name, machines: new Set() }
      entry.machines.add(r.machine_id)
      byPin.set(r.pin, entry)
    })
    const pins = [...byPin.values()]
    return {
      employees: pins.filter((p) => p.known).length,
      multiMachine: pins.filter((p) => p.machines.size > 1).length,
      unknownPins: pins.filter((p) => !p.known).length,
    }
  }, [rows])

  return (
    <Layout>
      <Head title="Absen per Mesin" />

      <div className="space-y-6">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Karyawan</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold">{stats.employees}</div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Absen di &gt; 1 mesin</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold text-indigo-600">{stats.multiMachine}</div></CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">PIN tak dikenal</CardTitle></CardHeader>
            <CardContent><div className="text-2xl font-bold text-amber-600">{stats.unknownPins}</div></CardContent>
          </Card>
        </div>

        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <CardTitle>Karyawan Absen di Mesin Mana</CardTitle>
                <CardDescription>
                  Log mesin {filters.date_from} s/d {filters.date_to} • per karyawan × mesin × hari • masuk = tap pertama, keluar = tap terakhir • absen foto & duplikat tidak dihitung
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
              <div className="space-y-1.5 sm:w-64">
                <label className="text-sm font-medium">Karyawan</label>
                <Combobox
                  options={employeeOptions}
                  value={filters.employee_id || ''}
                  onChange={(v) => applyFilters({ employee_id: v })}
                  placeholder="Semua Karyawan"
                  searchPlaceholder="Cari nama, kode, atau PIN..."
                  emptyText="Karyawan tidak ditemukan."
                />
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
            <DataTable
              columns={columns}
              data={rows}
              getRowId={(r) => r.key}
              filterPlaceholder="Cari nama, kode, PIN, tanggal, atau mesin..."
            />
          </CardContent>
        </Card>
      </div>

      {detail && (
        <PresenceDialog
          row={detail}
          machineIdentities={machineIdentities}
          onClose={() => setDetail(null)}
        />
      )}
    </Layout>
  )
}
