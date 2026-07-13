import React, { useEffect, useMemo, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Download, Send, Loader2, X, ChevronLeft, ChevronRight, RefreshCw, ListChecks, AlertTriangle, SlidersHorizontal } from 'lucide-react'

// Pilihan jeda auto-refresh (detik).
const REFRESH_INTERVAL = 15

// Tab = preset status_sync yang dikirim ke server ('' = semua).
const TABS = [
  { key: '', label: 'Semua Log', icon: ListChecks },
  { key: 'failed', label: 'Gagal', icon: AlertTriangle },
]

// Format timestamp jadi "YYYY-MM-DD HH:mm:ss" (waktu lokal).
const formatTimestamp = (value) => {
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return value ?? ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} `
    + `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

export default function AttendanceLogs({ logs = [], machines = [], brands = [], outlets = [], filters = {}, pagination = {} }) {
  const [sendingId, setSendingId] = useState(null)
  const [sendingAll, setSendingAll] = useState(false)
  const [autoRefresh, setAutoRefresh] = useState(false)
  const [resendingFailed, setResendingFailed] = useState(false)
  // Panel filter disembunyikan default; otomatis terbuka bila ada filter aktif dari URL.
  const [showFilters, setShowFilters] = useState(
    () => !!(filters.machine_id || filters.brand_id || filters.outlet_id || filters.date_from || filters.date_to)
  )

  // Refresh tabel berkala tanpa kehilangan filter/scroll. Hanya muat ulang
  // 'logs' & 'pagination' (filter dipertahankan dari URL aktif).
  const sendingRef = useRef(false)
  sendingRef.current = sendingId !== null || sendingAll || resendingFailed
  useEffect(() => {
    if (!autoRefresh) return
    const id = setInterval(() => {
      // Jangan tabrak proses kirim yang sedang berjalan.
      if (sendingRef.current) return
      router.reload({ only: ['logs', 'pagination'], preserveScroll: true })
    }, REFRESH_INTERVAL * 1000)
    return () => clearInterval(id)
  }, [autoRefresh])

  // Outlet yang ditampilkan mengikuti brand terpilih (kalau ada).
  const outletOptions = useMemo(
    () => (filters.brand_id ? outlets.filter((o) => o.brand_id === filters.brand_id) : outlets),
    [outlets, filters.brand_id]
  )

  // Terapkan filter ke server (gabungkan status tab + machine_id + brand/outlet + tanggal).
  const applyFilters = (next) => {
    const merged = {
      status: filters.status || '',
      machine_id: filters.machine_id || '',
      brand_id: filters.brand_id || '',
      outlet_id: filters.outlet_id || '',
      date_from: filters.date_from || '',
      date_to: filters.date_to || '',
      ...next,
    }
    // Ganti brand -> reset outlet supaya tak nyangkut di outlet brand lain.
    if (Object.prototype.hasOwnProperty.call(next, 'brand_id')) {
      merged.outlet_id = ''
    }
    const params = Object.fromEntries(
      Object.entries(merged).filter(([, v]) => v)
    )
    // Ganti filter selalu balik ke halaman 1 (page tidak diikutkan).
    router.get('/attendance-logs', params, {
      preserveState: true,
      preserveScroll: true,
      only: ['logs', 'filters', 'pagination'],
    })
  }

  // Pindah halaman tanpa mengubah filter aktif.
  const goToPage = (page) => {
    const params = Object.fromEntries(
      Object.entries({
        status: filters.status || '',
        machine_id: filters.machine_id || '',
        brand_id: filters.brand_id || '',
        outlet_id: filters.outlet_id || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        page,
      }).filter(([, v]) => v)
    )
    router.get('/attendance-logs', params, {
      preserveState: true,
      preserveScroll: true,
      only: ['logs', 'pagination'],
    })
  }

  // Ganti tab (status). Reset ke halaman 1; filter lain dipertahankan.
  const activeTab = filters.status || ''
  const switchTab = (status) => {
    if (status === activeTab) return
    applyFilters({ status })
  }

  // Reset semua filter tapi tetap di tab (status) yang aktif.
  const resetFilters = () => {
    const params = filters.status ? { status: filters.status } : {}
    router.get('/attendance-logs', params, {
      preserveScroll: true,
      only: ['logs', 'filters', 'pagination'],
    })
  }

  // "Filter" di sini = filter selain tab status (mesin/brand/outlet/tanggal).
  const hasFilters =
    filters.machine_id || filters.brand_id || filters.outlet_id || filters.date_from || filters.date_to

  const csrf = () => document.querySelector('meta[name="csrf-token"]').content

  const sendLog = async (id) => {
    setSendingId(id)
    try {
      const res = await fetch(`/api/attendance-logs/${id}/send`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      })
      const data = await res.json()
      if (res.ok) toast.success(data.message)
      else toast.error(data.message || 'Gagal mengirim')
      router.reload({ only: ['logs', 'pagination'] })
    } catch (err) {
      toast.error('Gagal mengirim: ' + err.message)
    } finally {
      setSendingId(null)
    }
  }

  const sendAllPending = () => {
    confirmToast({
      message: 'Kirim semua data pending ke Mekari Talenta?',
      confirmLabel: 'Kirim',
      onConfirm: runSendAllPending,
    })
  }

  const runSendAllPending = async () => {
    setSendingAll(true)
    try {
      const res = await fetch('/api/attendance-logs/send-pending', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      })
      const data = await res.json()
      if (res.ok) toast.success(data.message)
      else toast.error(data.message || 'Gagal mengirim')
      router.reload({ only: ['logs', 'pagination'] })
    } catch (err) {
      toast.error('Gagal mengirim: ' + err.message)
    } finally {
      setSendingAll(false)
    }
  }

  const resendAllFailed = () => {
    confirmToast({
      message: 'Kirim ulang semua data gagal ke Mekari Talenta?',
      description: 'Semua log berstatus failed akan dicoba dikirim ulang.',
      confirmLabel: 'Kirim Ulang',
      onConfirm: runResendAllFailed,
    })
  }

  const runResendAllFailed = async () => {
    setResendingFailed(true)
    try {
      const res = await fetch('/api/attendance-logs/send-failed', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      })
      const data = await res.json()
      if (res.ok) toast.success(data.message)
      else toast.error(data.message || 'Gagal mengirim ulang')
      router.reload({ only: ['logs', 'pagination'] })
    } catch (err) {
      toast.error('Gagal mengirim ulang: ' + err.message)
    } finally {
      setResendingFailed(false)
    }
  }

  const columns = useMemo(
    () => [
      {
        accessorKey: 'timestamp',
        header: 'Timestamp',
        cell: ({ row }) => (
          <div className="text-sm">
            {formatTimestamp(row.getValue('timestamp'))}
          </div>
        ),
      },
      {
        accessorKey: 'machine_id',
        header: 'Machine',
        cell: ({ row }) => {
          const machineId = row.getValue('machine_id')
          const machine = machines.find(m => m.id === machineId)
          return <div className="text-sm">{machine?.name || 'Unknown'}</div>
        },
      },
      {
        accessorKey: 'biometric_id_lokal',
        header: 'Biometric ID',
        cell: ({ row }) => (
          <div className="font-mono text-sm">{row.getValue('biometric_id_lokal')}</div>
        ),
      },
      {
        accessorKey: 'employee_name',
        header: 'Employee',
        cell: ({ row }) => <div className="text-sm">{row.getValue('employee_name') || 'N/A'}</div>,
      },
      {
        accessorKey: 'status_sync',
        header: 'Status',
        cell: ({ row }) => {
          const status = row.getValue('status_sync')
          const variants = {
            pending: 'warning',
            sent: 'success',
            failed: 'destructive',
            duplicate: 'info',
          }
          return <Badge variant={variants[status] || 'info'}>{status}</Badge>
        },
      },
      {
        accessorKey: 'error_message',
        header: 'Error',
        cell: ({ row }) => {
          const error = row.getValue('error_message')
          return error ? (
            <details className="text-xs">
              <summary className="cursor-pointer font-medium text-red-600">View</summary>
              <p className="mt-1 text-slate-600 dark:text-slate-400 whitespace-normal">{error}</p>
            </details>
          ) : (
            '-'
          )
        },
      },
      {
        id: 'actions',
        header: 'Action',
        cell: ({ row }) => {
          const log = row.original
          const status = log.status_sync
          if (status === 'sent' || status === 'duplicate') {
            return <span className="text-xs text-slate-400">-</span>
          }
          return (
            <Button
              size="sm"
              onClick={() => sendLog(log.id)}
              disabled={sendingId === log.id}
              className="gap-1"
            >
              {sendingId === log.id ? (
                <Loader2 className="h-3 w-3 animate-spin" />
              ) : (
                <Send className="h-3 w-3" />
              )}
              Kirim
            </Button>
          )
        },
      },
    ],
    [machines, sendingId]
  )

  const handleExport = () => {
    const csv = [
      ['Timestamp', 'Machine', 'Biometric ID', 'Employee', 'Status', 'Error'],
      ...logs.map(log => [
        formatTimestamp(log.timestamp),
        machines.find(m => m.id === log.machine_id)?.name || 'Unknown',
        log.biometric_id_lokal,
        log.employee_name || 'N/A',
        log.status_sync,
        log.error_message || '',
      ]),
    ]
      // Escape tanda kutip dalam nilai (RFC 4180): " -> "".
      .map(row => row.map(cell => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(','))
      .join('\n')

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `attendance-logs-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
  }

  // Total = semua baris yang cocok filter (lintas halaman); sisanya per halaman.
  const stats = {
    total: pagination.total ?? logs.length,
    sent: logs.filter(l => l.status_sync === 'sent').length,
    failed: logs.filter(l => l.status_sync === 'failed').length,
    successRate: logs.length > 0 ? Math.round((logs.filter(l => l.status_sync === 'sent').length / logs.length) * 100) : 0,
  }

  const hasPages = (pagination.last_page ?? 1) > 1

  return (
    <Layout>
      <Head title="Attendance Logs" />

      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Attendance Logs</h1>
          <div className="flex gap-2">
            <Button
              onClick={() => setAutoRefresh((v) => !v)}
              variant={autoRefresh ? 'default' : 'outline'}
              className="gap-2"
              title={autoRefresh ? `Auto-refresh tiap ${REFRESH_INTERVAL} detik (klik untuk berhenti)` : 'Aktifkan auto-refresh tabel'}
            >
              <RefreshCw className={`h-4 w-4 ${autoRefresh ? 'animate-spin' : ''}`} />
              {autoRefresh ? `Auto ${REFRESH_INTERVAL}s` : 'Auto-refresh'}
            </Button>
            <Button onClick={sendAllPending} disabled={sendingAll} className="gap-2">
              {sendingAll ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <Send className="h-4 w-4" />
              )}
              Kirim Semua Pending
            </Button>
            <Button onClick={handleExport} variant="outline" className="gap-2">
              <Download className="h-4 w-4" />
              Export CSV
            </Button>
          </div>
        </div>

        {/* Tab switcher: Semua Log vs Gagal */}
        <div className="flex gap-2 border-b border-slate-200 dark:border-slate-800">
          {TABS.map(({ key, label, icon: Icon }) => (
            <Button
              key={key}
              variant="ghost"
              onClick={() => switchTab(key)}
              className={`gap-2 rounded-none border-b-2 ${
                activeTab === key
                  ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-slate-500'
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Button>
          ))}
        </div>

        {/* Data Table */}
        <Card>
          <CardHeader>
            <div className="space-y-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <CardTitle>{activeTab === 'failed' ? 'Data Gagal Kirim' : 'Log Viewer'}</CardTitle>
                  <CardDescription>
                    {activeTab === 'failed'
                      ? 'Semua absensi berstatus failed • klik "Kirim" untuk coba ulang'
                      : 'Click column headers to sort • Use search to filter'}
                  </CardDescription>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  {activeTab === 'failed' && (
                    <Button
                      onClick={resendAllFailed}
                      disabled={resendingFailed || (pagination.total ?? 0) === 0}
                      className="gap-2"
                    >
                      {resendingFailed ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <RefreshCw className="h-4 w-4" />
                      )}
                      Kirim Ulang Semua Gagal
                    </Button>
                  )}
                  <Button
                    variant={showFilters ? 'default' : 'outline'}
                    className="gap-2"
                    onClick={() => setShowFilters((v) => !v)}
                  >
                    <SlidersHorizontal className="h-4 w-4" />
                    Filter
                    {hasFilters && (
                      <span className="ml-0.5 inline-flex h-2 w-2 rounded-full bg-indigo-400" title="Filter aktif" />
                    )}
                  </Button>
                </div>
              </div>
              {showFilters && (
              <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                <div className="space-y-1.5 sm:w-56">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Filter by Machine
                  </label>
                  <Select
                    value={filters.machine_id || ''}
                    onChange={(e) => applyFilters({ machine_id: e.target.value })}
                  >
                    <option value="">Semua Mesin</option>
                    {machines.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name || m.serial_number}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5 sm:w-48">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Brand
                  </label>
                  <Select
                    value={filters.brand_id || ''}
                    onChange={(e) => applyFilters({ brand_id: e.target.value })}
                  >
                    <option value="">Semua Brand</option>
                    {brands.map((b) => (
                      <option key={b.id} value={b.id}>
                        {b.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5 sm:w-48">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Outlet
                  </label>
                  <Select
                    value={filters.outlet_id || ''}
                    onChange={(e) => applyFilters({ outlet_id: e.target.value })}
                  >
                    <option value="">Semua Outlet</option>
                    {outletOptions.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Dari Tanggal
                  </label>
                  <Input
                    type="date"
                    value={filters.date_from || ''}
                    onChange={(e) => applyFilters({ date_from: e.target.value })}
                    className="sm:w-44"
                  />
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Sampai Tanggal
                  </label>
                  <Input
                    type="date"
                    value={filters.date_to || ''}
                    onChange={(e) => applyFilters({ date_to: e.target.value })}
                    className="sm:w-44"
                  />
                </div>
                {hasFilters && (
                  <Button
                    variant="outline"
                    className="gap-2"
                    onClick={resetFilters}
                  >
                    <X className="h-4 w-4" />
                    Reset
                  </Button>
                )}
              </div>
              )}
            </div>
          </CardHeader>
          <CardContent>
            <DataTable columns={columns} data={logs} filterPlaceholder="Filter by employee or biometric ID..." />

            {/* Paginasi server-side. Kotak pencarian di atas hanya menyaring halaman ini. */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4">
              <p className="text-sm text-slate-500">
                {pagination.total > 0
                  ? `Menampilkan ${pagination.from}–${pagination.to} dari ${pagination.total} log`
                  : 'Tidak ada log'}
              </p>
              {hasPages && (
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="gap-1"
                    disabled={pagination.current_page <= 1}
                    onClick={() => goToPage(pagination.current_page - 1)}
                  >
                    <ChevronLeft className="h-4 w-4" /> Sebelumnya
                  </Button>
                  <span className="text-sm text-slate-500">
                    Hal {pagination.current_page} / {pagination.last_page}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    className="gap-1"
                    disabled={pagination.current_page >= pagination.last_page}
                    onClick={() => goToPage(pagination.current_page + 1)}
                  >
                    Berikutnya <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Total Logs</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total}</div>
              <p className="text-xs text-slate-400">semua yang cocok filter</p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Sent <span className="text-xs font-normal text-slate-400">(hal. ini)</span></CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats.sent}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Failed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats.failed}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Success Rate</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats.successRate}%</div>
            </CardContent>
          </Card>
        </div>
      </div>
    </Layout>
  )
}
