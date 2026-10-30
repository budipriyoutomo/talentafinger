import React, { useCallback, useEffect, useMemo, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Select } from '@/components/ui/select'
import { Combobox } from '@/components/ui/combobox'
import { confirmToast } from '@/lib/confirm'
import {
  RefreshCw, Download, Upload, Loader2, Link2, UserPlus, EyeOff, X, MapPin, History, AlertTriangle,
} from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

async function api(url, { method = 'GET', body } = {}) {
  const res = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(method !== 'GET' ? { 'X-CSRF-TOKEN': csrf() } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    const msg = data.error || data.message || (data.errors && Object.values(data.errors).flat().join('\n'))
    throw new Error(msg || `Request gagal (HTTP ${res.status})`)
  }
  return data
}

const fmtTime = (iso) =>
  iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-'

const SYNC_STATE = {
  pending: { label: 'Menunggu sync', variant: 'info' },
  synced: { label: 'Tersinkron', variant: 'success' },
  conflict: { label: 'Konflik', variant: 'warning' },
  ignored: { label: 'Diabaikan', variant: 'secondary' },
  error: { label: 'Error', variant: 'destructive' },
}

const MATCH_STATUS = {
  linked: 'Tertaut',
  new: 'Dibuat dari Talenta',
  resigned: 'Resign',
  conflict: 'Konflik',
}

// Tab filter tabel. fn menerima 1 baris hasil /api/talenta/employees.
const FILTERS = [
  { key: 'all', label: 'Semua', fn: () => true },
  { key: 'conflict', label: 'Konflik', fn: (r) => r.sync_state === 'conflict' },
  { key: 'error', label: 'Error', fn: (r) => r.sync_state === 'error' },
  { key: 'pending', label: 'Menunggu sync', fn: (r) => r.sync_state === 'pending' },
  { key: 'new', label: 'Dibuat dari Talenta', fn: (r) => r.match_status === 'new' },
  { key: 'resigned', label: 'Resign', fn: (r) => r.status === 'Resigned' },
  { key: 'no_barcode', label: 'Tanpa barcode', fn: (r) => !r.barcode && !r.missing },
  { key: 'missing', label: 'Hilang dari Talenta', fn: (r) => r.missing },
  { key: 'ignored', label: 'Diabaikan', fn: (r) => r.sync_state === 'ignored' },
]

const RUN_STATUS_VARIANT = { success: 'success', failed: 'destructive', running: 'info', queued: 'secondary' }

function runText(run) {
  if (!run) return 'Belum pernah'
  if (run.type === 'pull') return `${run.total_fetched} diambil, ${run.count_changed} baru/berubah`
  return `${run.count_created} dibuat · ${run.count_updated} diupdate · ${run.count_deactivated} dinonaktifkan · ${run.count_conflict} konflik · ${run.count_error} error`
}

function StatCard({ label, value, hint, tone = 'default', onClick }) {
  const toneClass = {
    default: 'text-foreground',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-red-600 dark:text-red-400',
    success: 'text-emerald-600 dark:text-emerald-400',
  }[tone]
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={!onClick}
      className="rounded-xl border bg-card p-4 text-left transition enabled:hover:border-indigo-400 disabled:cursor-default"
    >
      <div className="text-xs font-medium text-muted-foreground">{label}</div>
      <div className={`mt-1 text-2xl font-semibold ${toneClass}`}>{value ?? 0}</div>
      {hint && <div className="mt-0.5 text-xs text-muted-foreground">{hint}</div>}
    </button>
  )
}

export default function TalentaSyncPanel({ employees = [] }) {
  const [summary, setSummary] = useState(null)
  const [rows, setRows] = useState([])
  const [loadingRows, setLoadingRows] = useState(true)
  const [filter, setFilter] = useState('all')
  const [starting, setStarting] = useState(false)
  const [linkRow, setLinkRow] = useState(null)
  const [showBranches, setShowBranches] = useState(false)
  const [showRuns, setShowRuns] = useState(false)

  const loadSummary = useCallback(async () => {
    try {
      setSummary(await api('/api/talenta/employees/summary'))
    } catch (err) {
      toast.error(err.message)
    }
  }, [])

  const loadRows = useCallback(async () => {
    setLoadingRows(true)
    try {
      setRows(await api('/api/talenta/employees'))
    } catch (err) {
      toast.error(err.message)
    } finally {
      setLoadingRows(false)
    }
  }, [])

  useEffect(() => {
    loadSummary()
    loadRows()
  }, [loadSummary, loadRows])

  // Selama run berjalan: polling ringkasan; begitu selesai muat ulang tabel & daftar karyawan.
  const running = !!summary?.running
  useEffect(() => {
    if (!running) return
    const id = setInterval(loadSummary, 3000)
    return () => clearInterval(id)
  }, [running, loadSummary])

  const [wasRunning, setWasRunning] = useState(false)
  useEffect(() => {
    if (running) setWasRunning(true)
    if (!running && wasRunning) {
      setWasRunning(false)
      loadRows()
      router.reload({ only: ['employees'] })
      const failed = [summary?.last_pull, summary?.last_sync].find((r) => r?.status === 'failed')
      failed ? toast.error(`Sync gagal: ${failed.error_message}`) : toast.success('Sync karyawan Talenta selesai.')
    }
  }, [running]) // eslint-disable-line react-hooks/exhaustive-deps

  const start = (mode) => {
    const run = async () => {
      setStarting(true)
      try {
        await api('/api/talenta/employees/run', { method: 'POST', body: { mode } })
        toast.success('Sync dimulai di background.')
        await loadSummary()
      } catch (err) {
        toast.error(err.message)
      } finally {
        setStarting(false)
      }
    }
    if (mode === 'pull') return run()
    confirmToast({
      message: mode === 'both' ? 'Tarik dari Talenta lalu sync ke karyawan?' : 'Sync tabel sementara ke karyawan?',
      description:
        'Karyawan baru dibuat, nama/jabatan diperbarui otomatis, karyawan resign dinonaktifkan. Mesin tidak disentuh.',
      confirmLabel: 'Jalankan',
      onConfirm: run,
    })
  }

  const replaceRow = (row) => setRows((list) => list.map((r) => (r.id === row.id ? row : r)))

  const resolve = async (row, action, body) => {
    try {
      const res = await api(`/api/talenta/employees/${row.id}/${action}`, { method: 'POST', body })
      replaceRow(res.row)
      loadSummary()
      router.reload({ only: ['employees'] })
      return true
    } catch (err) {
      toast.error(err.message)
      return false
    }
  }

  const createNew = (row) =>
    confirmToast({
      message: `Buat karyawan baru "${row.full_name}"?`,
      description: 'Pastikan ini bukan duplikat karyawan yang sudah ada — kalau ya, pakai Tautkan.',
      confirmLabel: 'Buat',
      onConfirm: async () => (await resolve(row, 'create')) && toast.success('Karyawan dibuat.'),
    })

  const ignore = (row) =>
    confirmToast({
      message: `Abaikan "${row.full_name}"?`,
      description: 'Tidak diproses lagi sampai datanya berubah di Talenta.',
      confirmLabel: 'Abaikan',
      onConfirm: () => resolve(row, 'ignore'),
    })

  const counts = useMemo(
    () => Object.fromEntries(FILTERS.map((f) => [f.key, rows.filter(f.fn).length])),
    [rows]
  )
  const visibleRows = useMemo(
    () => rows.filter(FILTERS.find((f) => f.key === filter)?.fn ?? (() => true)),
    [rows, filter]
  )

  const columns = useMemo(
    () => [
      {
        accessorKey: 'full_name',
        header: 'Karyawan Talenta',
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="min-w-[180px]">
              <div className="font-medium">{r.full_name}</div>
              <div className="text-xs text-muted-foreground">
                {[r.job_position, r.organization_name].filter(Boolean).join(' · ') || '-'}
              </div>
            </div>
          )
        },
      },
      {
        id: 'ids',
        header: 'Barcode / Employee ID',
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="font-mono text-xs">
              <div>{r.barcode ?? <span className="text-amber-600">tanpa barcode</span>}</div>
              <div className="text-muted-foreground">{r.employee_code ?? '-'}</div>
            </div>
          )
        },
      },
      {
        accessorKey: 'branch_name',
        header: 'Cabang',
        cell: ({ row }) => <div className="text-sm">{row.original.branch_name ?? '-'}</div>,
      },
      {
        accessorKey: 'status',
        header: 'Status Talenta',
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="space-y-1">
              <Badge variant={r.status === 'Resigned' ? 'warning' : 'success'}>
                {r.status === 'Resigned' ? 'Resign' : 'Aktif'}
              </Badge>
              {r.resign_date && <div className="text-xs text-muted-foreground">{r.resign_date}</div>}
              {r.missing && <Badge variant="secondary">Hilang dari Talenta</Badge>}
            </div>
          )
        },
      },
      {
        id: 'local',
        header: 'Karyawan Lokal',
        cell: ({ row }) => {
          const r = row.original
          const e = r.employee
          if (!e) return <span className="text-sm text-muted-foreground">-</span>
          const nameDiff = e.name !== r.full_name
          const badgeDiff = r.barcode && e.talenta_employee_id && e.talenta_employee_id !== r.barcode
          return (
            <div className="min-w-[160px] text-sm">
              <div className={nameDiff ? 'text-amber-700 dark:text-amber-400' : ''}>{e.name}</div>
              <div className="font-mono text-xs">
                Talenta ID:{' '}
                <span className={badgeDiff ? 'font-semibold text-red-600' : 'text-muted-foreground'}>
                  {e.talenta_employee_id ?? '-'}
                </span>
              </div>
              <div className="mt-0.5 flex flex-wrap gap-1">
                {!e.linked && <Badge variant="outline">kandidat</Badge>}
                {!e.is_active && <Badge variant="destructive">nonaktif</Badge>}
                {e.outlets_count === 0 && <Badge variant="secondary">tanpa outlet</Badge>}
              </div>
            </div>
          )
        },
      },
      {
        accessorKey: 'sync_state',
        header: 'Sync',
        cell: ({ row }) => {
          const r = row.original
          const s = SYNC_STATE[r.sync_state] ?? { label: r.sync_state, variant: 'outline' }
          return (
            <div className="max-w-[240px] space-y-1">
              <Badge variant={s.variant}>{s.label}</Badge>
              {r.sync_state === 'synced' && r.match_status && (
                <div className="text-xs text-muted-foreground">{MATCH_STATUS[r.match_status] ?? r.match_status}</div>
              )}
              {r.sync_error && <div className="text-xs text-amber-700 dark:text-amber-400">{r.sync_error}</div>}
            </div>
          )
        },
      },
      {
        id: 'actions',
        header: 'Aksi',
        cell: ({ row }) => {
          const r = row.original
          if (!['conflict', 'error', 'ignored'].includes(r.sync_state)) return null
          return (
            <div className="flex flex-col gap-1.5">
              <Button size="sm" variant="outline" className="gap-1" onClick={() => setLinkRow(r)}>
                <Link2 className="h-3 w-3" /> Tautkan
              </Button>
              <Button size="sm" variant="outline" className="gap-1" onClick={() => createNew(r)}>
                <UserPlus className="h-3 w-3" /> Buat Baru
              </Button>
              {r.sync_state !== 'ignored' && (
                <Button size="sm" variant="ghost" className="gap-1" onClick={() => ignore(r)}>
                  <EyeOff className="h-3 w-3" /> Abaikan
                </Button>
              )}
            </div>
          )
        },
      },
    ],
    [] // eslint-disable-line react-hooks/exhaustive-deps
  )

  const ss = summary?.sync_state ?? {}
  const ms = summary?.match_status ?? {}

  return (
    <div className="space-y-6">
      {/* Kontrol & status run */}
      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
              <CardTitle>Sinkron Karyawan dari Talenta</CardTitle>
              <CardDescription>
                Tahap 1 menarik data Talenta ke tabel sementara. Tahap 2 menyalinnya ke daftar karyawan: karyawan baru
                dibuat, nama/jabatan diperbarui, yang resign dinonaktifkan. Talenta ID (badgeno absensi) yang sudah
                terisi tidak pernah ditimpa otomatis.
              </CardDescription>
            </div>
            <div className="flex w-full flex-wrap gap-2 sm:w-auto">
              <Button onClick={() => start('both')} disabled={running || starting} className="gap-2">
                {running || starting ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                {running ? 'Sedang berjalan...' : 'Tarik & Sync'}
              </Button>
              <Button variant="outline" onClick={() => start('pull')} disabled={running || starting} className="gap-2">
                <Download className="h-4 w-4" /> Tarik saja
              </Button>
              <Button variant="outline" onClick={() => start('sync')} disabled={running || starting} className="gap-2">
                <Upload className="h-4 w-4" /> Sync saja
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent className="space-y-2 text-sm">
          {[summary?.last_pull, summary?.last_sync].map((run, i) => (
            <div key={i} className="flex flex-wrap items-center gap-2">
              <span className="w-full font-medium sm:w-28">{i === 0 ? 'Tarik terakhir' : 'Sync terakhir'}</span>
              {run ? (
                <>
                  <Badge variant={RUN_STATUS_VARIANT[run.status] ?? 'outline'}>{run.status}</Badge>
                  <span className="text-muted-foreground">
                    {fmtTime(run.finished_at ?? run.created_at)} · {run.triggered_by ?? '-'} · {runText(run)}
                  </span>
                  {run.error_message && <span className="text-red-600">{run.error_message}</span>}
                </>
              ) : (
                <span className="text-muted-foreground">Belum pernah</span>
              )}
            </div>
          ))}
          <div className="flex flex-wrap items-center gap-2 pt-1 text-xs text-muted-foreground">
            Auto sync:{' '}
            {summary?.auto_sync?.enabled ? (
              <Badge variant="success">aktif · tiap {summary.auto_sync.interval} menit</Badge>
            ) : (
              <Badge variant="secondary">nonaktif</Badge>
            )}
            <a href="/settings" className="underline">ubah di Pengaturan</a>
          </div>
        </CardContent>
      </Card>

      {/* Ringkasan */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-5">
        <StatCard label="Total di Talenta" value={summary?.total} onClick={() => setFilter('all')} />
        <StatCard label="Tertaut" value={(ms.linked ?? 0) + (ms.new ?? 0)} tone="success" hint={`${ms.new ?? 0} dibuat dari Talenta`} onClick={() => setFilter('new')} />
        <StatCard label="Konflik" value={ss.conflict} tone={ss.conflict ? 'warning' : 'default'} onClick={() => setFilter('conflict')} />
        <StatCard label="Error" value={ss.error} tone={ss.error ? 'danger' : 'default'} onClick={() => setFilter('error')} />
        <StatCard label="Menunggu sync" value={ss.pending} onClick={() => setFilter('pending')} />
        <StatCard label="Resign" value={counts.resigned} onClick={() => setFilter('resigned')} />
        <StatCard label="Tanpa barcode" value={summary?.without_barcode} hint="absensi tak bisa dikirim" tone={summary?.without_barcode ? 'warning' : 'default'} onClick={() => setFilter('no_barcode')} />
        <StatCard label="Hilang dari Talenta" value={summary?.missing} onClick={() => setFilter('missing')} />
        <StatCard label="Lokal tak ada di Talenta" value={summary?.local_not_in_talenta} hint="lihat filter di tab Karyawan" />
        <StatCard label="Tertaut tanpa outlet" value={summary?.linked_without_outlet} hint="atur Mapping Cabang" tone={summary?.linked_without_outlet ? 'warning' : 'default'} onClick={() => setShowBranches(true)} />
      </div>

      {/* Mapping cabang & riwayat */}
      <div className="flex flex-wrap gap-2">
        <Button variant={showBranches ? 'default' : 'outline'} className="gap-2" onClick={() => setShowBranches((v) => !v)}>
          <MapPin className="h-4 w-4" /> Mapping Cabang → Outlet
        </Button>
        <Button variant={showRuns ? 'default' : 'outline'} className="gap-2" onClick={() => setShowRuns((v) => !v)}>
          <History className="h-4 w-4" /> Riwayat Run
        </Button>
      </div>
      {showBranches && <BranchMappingCard onSaved={() => { loadSummary(); router.reload({ only: ['employees'] }) }} />}
      {showRuns && <RunHistoryCard running={running} />}

      {/* Tabel tabel sementara */}
      <Card>
        <CardHeader>
          <CardTitle>Data Talenta</CardTitle>
          <CardDescription>
            Kolom lokal oranye = nama berbeda (akan diperbarui saat sync); Talenta ID merah = badgeno bentrok (konflik).
          </CardDescription>
          <div className="flex flex-wrap gap-1.5 pt-2">
            {FILTERS.map((f) => (
              <Button
                key={f.key}
                size="sm"
                variant={filter === f.key ? 'default' : 'outline'}
                onClick={() => setFilter(f.key)}
              >
                {f.label}
                <span className="ml-1 text-xs opacity-70">{counts[f.key] ?? 0}</span>
              </Button>
            ))}
          </div>
        </CardHeader>
        <CardContent>
          {loadingRows ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" /> Memuat...
            </div>
          ) : rows.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Belum ada data. Klik <b>Tarik saja</b> untuk mengambil data dari Talenta tanpa mengubah karyawan.
            </p>
          ) : (
            <DataTable columns={columns} data={visibleRows} filterPlaceholder="Cari nama Talenta..." />
          )}
        </CardContent>
      </Card>

      {linkRow && (
        <LinkDialog
          row={linkRow}
          employees={employees}
          onClose={() => setLinkRow(null)}
          onSubmit={async (employeeId, overwriteBadge) => {
            const ok = await resolve(linkRow, 'link', { employee_id: employeeId, overwrite_badge: overwriteBadge })
            if (ok) {
              toast.success('Karyawan ditautkan.')
              setLinkRow(null)
            }
          }}
        />
      )}
    </div>
  )
}

/** Tautkan baris Talenta ke karyawan lokal pilihan admin. */
function LinkDialog({ row, employees, onClose, onSubmit }) {
  const [employeeId, setEmployeeId] = useState(row.employee?.id ?? '')
  const [overwriteBadge, setOverwriteBadge] = useState(false)
  const [saving, setSaving] = useState(false)

  const options = useMemo(
    () =>
      employees.map((e) => ({
        value: e.id,
        label: e.name,
        description: `Talenta ID ${e.talenta_employee_id ?? '-'} · PIN ${e.biometric_id ?? '-'}${e.talenta_user_id ? ' · sudah tertaut' : ''}`,
        keywords: [e.talenta_employee_id, e.employee_code, e.biometric_id].filter(Boolean).join(' '),
      })),
    [employees]
  )
  const target = employees.find((e) => e.id === employeeId)
  const badgeDiffers = target && row.barcode && target.talenta_employee_id !== row.barcode

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-2 backdrop-blur-sm sm:p-4"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="mt-4 w-full sm:mt-16 max-w-xl rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-border p-4">
          <div>
            <h3 className="font-semibold">Tautkan ke karyawan lokal</h3>
            <p className="text-sm text-muted-foreground">
              {row.full_name} · barcode <span className="font-mono">{row.barcode ?? '-'}</span> ·{' '}
              <span className="font-mono">{row.employee_code ?? '-'}</span>
            </p>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>
        <div className="space-y-4 p-4">
          {row.sync_error && (
            <p className="rounded-md bg-amber-50 p-2.5 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
              {row.sync_error}
            </p>
          )}
          <div className="space-y-2">
            <label className="text-sm font-medium">Karyawan lokal</label>
            <Combobox
              options={options}
              value={employeeId}
              onChange={setEmployeeId}
              placeholder="Pilih karyawan..."
              searchPlaceholder="Cari nama / Talenta ID / PIN..."
            />
          </div>
          {badgeDiffers && (
            <label className="flex items-start gap-2 rounded-md border border-red-200 p-3 text-sm dark:border-red-500/30">
              <input
                type="checkbox"
                className="mt-0.5 h-4 w-4"
                checked={overwriteBadge}
                onChange={(e) => setOverwriteBadge(e.target.checked)}
              />
              <span>
                <span className="flex items-center gap-1 font-medium text-red-700 dark:text-red-300">
                  <AlertTriangle className="h-4 w-4" />
                  Timpa Talenta ID lokal ({target.talenta_employee_id ?? 'kosong'}) dengan barcode Talenta ({row.barcode})
                </span>
                <span className="block text-xs text-muted-foreground">
                  Talenta ID dipakai sebagai badgeno saat kirim absensi. Salah pilih = absensi masuk ke orang lain.
                  Biarkan tidak dicentang bila ragu.
                </span>
              </span>
            </label>
          )}
          <div className="flex gap-2 border-t border-border pt-4">
            <Button
              disabled={!employeeId || saving}
              onClick={async () => {
                setSaving(true)
                await onSubmit(employeeId, overwriteBadge)
                setSaving(false)
              }}
              className="gap-2"
            >
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Link2 className="h-4 w-4" />}
              Tautkan
            </Button>
            <Button variant="outline" onClick={onClose}>Batal</Button>
          </div>
        </div>
      </div>
    </div>
  )
}

/** D11: cabang Talenta -> outlet lokal. */
function BranchMappingCard({ onSaved }) {
  const [branches, setBranches] = useState(null)
  const [outlets, setOutlets] = useState([])
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api('/api/talenta/employees/branches')
      .then((d) => {
        setBranches(d.branches)
        setOutlets(d.outlets)
      })
      .catch((err) => toast.error(err.message))
  }, [])

  const setOutlet = (branchId, outletId) =>
    setBranches((list) => list.map((b) => (b.branch_id === branchId ? { ...b, outlet_id: outletId || null } : b)))

  const save = async (apply) => {
    setSaving(true)
    try {
      const res = await api('/api/talenta/employees/branches', {
        method: 'PUT',
        body: {
          apply,
          mappings: branches.map((b) => ({ branch_id: b.branch_id, branch_name: b.branch_name, outlet_id: b.outlet_id })),
        },
      })
      toast.success(apply ? `Mapping disimpan. ${res.filled} karyawan diberi outlet.` : 'Mapping disimpan.')
      onSaved?.()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Mapping Cabang → Outlet</CardTitle>
        <CardDescription>
          Dipakai mengisi outlet HANYA untuk karyawan yang belum punya outlet (karyawan baru dari sync, atau yang
          tertaut tapi belum ditempatkan). Karyawan yang sudah punya outlet tidak diubah.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {branches === null ? (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Memuat...
          </div>
        ) : branches.length === 0 ? (
          <p className="text-sm text-muted-foreground">Belum ada data cabang. Tarik data dari Talenta dulu.</p>
        ) : (
          <>
            <div className="divide-y rounded-md border dark:divide-slate-800 dark:border-slate-800">
              {branches.map((b) => (
                <div key={b.branch_id} className="grid grid-cols-1 items-center gap-2 p-2.5 md:grid-cols-2">
                  <div>
                    <div className="text-sm font-medium">{b.branch_name ?? b.branch_id}</div>
                    <div className="text-xs text-muted-foreground">{b.employees} karyawan · ID {b.branch_id}</div>
                  </div>
                  <Select value={b.outlet_id ?? ''} onChange={(e) => setOutlet(b.branch_id, e.target.value)}>
                    <option value="">— tidak dipetakan —</option>
                    {outlets.map((o) => (
                      <option key={o.id} value={o.id}>
                        {o.name}{o.brand_name ? ` · ${o.brand_name}` : ''}
                      </option>
                    ))}
                  </Select>
                </div>
              ))}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button disabled={saving} onClick={() => save(true)} className="gap-2">
                {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                Simpan & Terapkan ke karyawan tanpa outlet
              </Button>
              <Button variant="outline" disabled={saving} onClick={() => save(false)}>Simpan saja</Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}

function RunHistoryCard({ running }) {
  const [runs, setRuns] = useState(null)

  useEffect(() => {
    api('/api/talenta/employees/runs').then(setRuns).catch((err) => toast.error(err.message))
  }, [running])

  return (
    <Card>
      <CardHeader>
        <CardTitle>Riwayat Run</CardTitle>
        <CardDescription>30 run terakhir (tarik & sync).</CardDescription>
      </CardHeader>
      <CardContent>
        {runs === null ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : runs.length === 0 ? (
          <p className="text-sm text-muted-foreground">Belum ada run.</p>
        ) : (
          <div className="divide-y text-sm dark:divide-slate-800">
            {runs.map((r) => (
              <div key={r.id} className="flex flex-wrap items-center gap-2 py-2">
                <Badge variant="outline">{r.type === 'pull' ? 'Tarik' : 'Sync'}</Badge>
                <Badge variant={RUN_STATUS_VARIANT[r.status] ?? 'outline'}>{r.status}</Badge>
                <span className="text-muted-foreground">{fmtTime(r.created_at)} · {r.triggered_by ?? '-'}</span>
                <span>{runText(r)}</span>
                {r.error_message && <span className="text-red-600">{r.error_message}</span>}
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
