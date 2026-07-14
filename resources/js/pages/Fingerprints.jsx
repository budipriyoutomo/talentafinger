import React, { useState, useMemo, useEffect } from 'react'
import { Head } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
import { usePermissions } from '@/lib/permissions'
import { Send, Fingerprint, Loader2, RefreshCw, X, Trash2, UserPlus, Check } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

export default function Fingerprints({ machines = [], employeesByPin = {} }) {
  // Tiga izin berbeda bermain di halaman ini: sebar (sync), hapus dari mesin
  // (delete), dan simpan ke master karyawan (employee.manage).
  const { can } = usePermissions()
  const canSync = can('fingerprint.sync')
  const canDelete = can('fingerprint.delete')
  const canManageEmployee = can('employee.manage')

  const withIp = machines.filter((m) => m.ip_address)
  const [sourceId, setSourceId] = useState(withIp[0]?.id || '')
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(false)
  const [loadErr, setLoadErr] = useState('')

  // Pilihan baris untuk sebar massal: map PIN -> true.
  const [selected, setSelected] = useState({})

  // PIN yang sedang disebar (selalu array, 1 atau banyak) + mesin tujuan +
  // status proses & hasil.
  const [pushPins, setPushPins] = useState(null)
  const [targets, setTargets] = useState({})
  const [syncing, setSyncing] = useState(false)
  const [results, setResults] = useState(null)
  // Job background yang sedang dipolling (null = tidak ada).
  const [pollJobId, setPollJobId] = useState(null)
  // PIN yang sedang dihapus dari mesin (null = tidak ada).
  const [deletingPin, setDeletingPin] = useState(null)

  // Simpan ke master: opsi sekalian tarik sidik jari, PIN yang sedang disimpan,
  // dan hasil simpan per PIN (pin -> {created, fingers}).
  const [captureOnSave, setCaptureOnSave] = useState(true)
  const [savingPin, setSavingPin] = useState(null)
  const [savedPins, setSavedPins] = useState({})
  const [bulkSaving, setBulkSaving] = useState(false)

  // Hapus massal (background job): job id yang dipolling, status job, flag proses.
  const [deleteJobId, setDeleteJobId] = useState(null)
  const [deleteJob, setDeleteJob] = useState(null)
  const [deletingBulk, setDeletingBulk] = useState(false)

  const sourceMachine = machines.find((m) => m.id === sourceId)

  // Seleksi mencakup SEMUA user (untuk hapus / simpan ke master).
  const allPins = useMemo(() => users.map((u) => u.pin), [users])
  const selectedPins = useMemo(
    () => allPins.filter((pin) => selected[pin]),
    [allPins, selected]
  )
  // Sebar hanya berlaku untuk user yang punya sidik jari.
  const selectedPinsWithFingers = useMemo(
    () => selectedPins.filter((pin) => (users.find((u) => u.pin === pin)?.fingers ?? 0) > 0),
    [selectedPins, users]
  )
  const allSelected = allPins.length > 0 && selectedPins.length === allPins.length

  const loadUsers = async () => {
    if (!sourceId) return
    setLoading(true)
    setLoadErr('')
    setUsers([])
    setSelected({})
    setPushPins(null)
    setResults(null)
    setPollJobId(null)
    setSavedPins({})
    setDeleteJob(null)
    setDeleteJobId(null)
    try {
      const res = await fetch(`/api/machines/${sourceId}/zk-users`, {
        headers: { Accept: 'application/json' },
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        setUsers(body.users || [])
      } else {
        setLoadErr(body.error || 'Gagal memuat user dari mesin.')
      }
    } catch (err) {
      setLoadErr('Gagal terhubung ke mesin: ' + err.message)
    } finally {
      setLoading(false)
    }
  }

  const toggleSelect = (pin) =>
    setSelected((prev) => ({ ...prev, [pin]: !prev[pin] }))

  const toggleSelectAll = () =>
    setSelected(allSelected ? {} : Object.fromEntries(allPins.map((p) => [p, true])))

  const clearSelection = () => setSelected({})

  // Buka panel sebar untuk satu atau banyak PIN.
  const openPush = (pins) => {
    setPushPins(pins)
    setTargets({})
    setResults(null)
    setPollJobId(null)
  }

  const toggleTarget = (machineId) =>
    setTargets((prev) => ({ ...prev, [machineId]: !prev[machineId] }))

  // Polling status job background selama belum selesai.
  useEffect(() => {
    if (!pollJobId) return
    let active = true
    const tick = async () => {
      try {
        const res = await fetch(`/api/fingerprint/sync-jobs/${pollJobId}`, {
          headers: { Accept: 'application/json' },
        })
        const body = await res.json().catch(() => ({}))
        if (!active) return
        if (body.ok) {
          setResults(body)
          if (body.status === 'done' || body.status === 'failed') {
            setSyncing(false)
            setPollJobId(null)
          }
        }
      } catch {
        // Abaikan error sesaat; percobaan polling berikutnya akan menyusul.
      }
    }
    tick()
    const interval = setInterval(tick, 1500)
    return () => {
      active = false
      clearInterval(interval)
    }
  }, [pollJobId])

  const submitSync = async () => {
    const machineIds = withIp.filter((m) => targets[m.id] && m.id !== sourceId).map((m) => m.id)
    if (machineIds.length === 0) return toast.error('Centang minimal satu mesin tujuan.')
    if (!pushPins || pushPins.length === 0) return

    setSyncing(true)
    setResults(null)
    setPollJobId(null)
    try {
      const res = await fetch('/api/fingerprint/sync-bulk', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
          source_machine_id: sourceId,
          pins: pushPins,
          target_machine_ids: machineIds,
        }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        // Tampilkan state awal lalu mulai polling sampai job selesai.
        setResults({ status: 'queued', progress_total: body.total, progress_done: 0, items: [] })
        setPollJobId(body.job_id)
      } else {
        toast.error(body.error || 'Sync gagal.')
        setSyncing(false)
      }
    } catch (err) {
      toast.error('Sync gagal: ' + err.message)
      setSyncing(false)
    }
  }

  // Hapus 1 user (beserta sidik jarinya) dari mesin sumber.
  const deleteUser = (user) => {
    confirmToast({
      message: `Hapus user PIN ${user.pin}${user.name ? ` (${user.name})` : ''} dari mesin ${sourceMachine?.name}?`,
      description:
        'Seluruh sidik jari user ini di mesin tersebut ikut terhapus. Permanen di perangkat (mapping & log di aplikasi tidak terpengaruh).',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: () => runDeleteUser(user),
    })
  }

  const runDeleteUser = async (user) => {
    setDeletingPin(user.pin)
    try {
      const res = await fetch(`/api/machines/${sourceId}/zk-users/${encodeURIComponent(user.pin)}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      })
      const body = await res.json().catch(() => ({}))
      if (res.ok && body.ok && body.deleted) {
        // Buang dari daftar & batalkan pilihannya bila tercentang.
        setUsers((prev) => prev.filter((u) => u.pin !== user.pin))
        setSelected((prev) => {
          const next = { ...prev }
          delete next[user.pin]
          return next
        })
      } else if (body.ok && !body.deleted) {
        toast.warning('Mesin tidak mengonfirmasi penghapusan user. Coba muat ulang daftar.')
      } else {
        toast.error(body.error || 'Gagal menghapus user dari mesin.')
      }
    } catch (err) {
      toast.error('Gagal terhubung ke mesin: ' + err.message)
    } finally {
      setDeletingPin(null)
    }
  }

  // Simpan satu user mesin ke master karyawan (dedup by Biometric ID = PIN).
  // capture: ikut tarik sidik jari ke DB bila user punya jari. Return true bila sukses.
  const saveToMaster = async (user) => {
    setSavingPin(user.pin)
    try {
      const res = await fetch('/api/employees/import-from-machine', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
          biometric_id: user.pin,
          name: user.name || null,
          source_machine_id: sourceId,
          capture: captureOnSave && user.fingers > 0,
        }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        setSavedPins((prev) => ({
          ...prev,
          [user.pin]: {
            created: body.created,
            fingers: body.capture?.ok ? body.capture.fingers : 0,
            captureError: body.capture && !body.capture.ok ? body.capture.error : null,
          },
        }))
        return true
      }
      toast.error(body.error || (body.errors ? Object.values(body.errors).flat().join('\n') : 'Gagal menyimpan ke master.'))
      return false
    } catch (err) {
      toast.error('Gagal menyimpan ke master: ' + err.message)
      return false
    } finally {
      setSavingPin(null)
    }
  }

  // Simpan semua user terpilih ke master (berurutan; capture bisa lama per user).
  const saveSelectedToMaster = async () => {
    setBulkSaving(true)
    for (const pin of selectedPins) {
      const u = users.find((x) => x.pin === pin)
      if (u) await saveToMaster(u)
    }
    setBulkSaving(false)
  }

  // Hapus massal user terpilih dari mesin sumber (background job + polling).
  const deleteSelected = () => {
    if (selectedPins.length === 0) return
    confirmToast({
      message: `Hapus ${selectedPins.length} user dari mesin ${sourceMachine?.name}?`,
      description:
        'Seluruh sidik jari user-user ini di mesin tersebut ikut terhapus. Permanen di perangkat (master, mapping, & log di aplikasi tidak terpengaruh).',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: runDeleteSelected,
    })
  }

  const runDeleteSelected = async () => {
    setDeletingBulk(true)
    setDeleteJob(null)
    try {
      const res = await fetch('/api/fingerprint/delete-bulk', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ machine_id: sourceId, pins: selectedPins }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        setDeleteJob({ status: 'queued', progress_total: body.total, progress_done: 0, items: [] })
        setDeleteJobId(body.job_id)
      } else {
        toast.error(body.error || 'Hapus massal gagal.')
        setDeletingBulk(false)
      }
    } catch (err) {
      toast.error('Hapus massal gagal: ' + err.message)
      setDeletingBulk(false)
    }
  }

  // Polling status hapus massal; saat selesai, buang user terhapus dari daftar.
  useEffect(() => {
    if (!deleteJobId) return
    let active = true
    const tick = async () => {
      try {
        const res = await fetch(`/api/fingerprint/delete-jobs/${deleteJobId}`, {
          headers: { Accept: 'application/json' },
        })
        const body = await res.json().catch(() => ({}))
        if (!active) return
        if (body.ok) {
          setDeleteJob(body)
          if (body.status === 'done' || body.status === 'failed') {
            setDeletingBulk(false)
            setDeleteJobId(null)
            const deletedPins = (body.items || []).filter((it) => it.ok).map((it) => String(it.pin))
            if (deletedPins.length) {
              setUsers((prev) => prev.filter((u) => !deletedPins.includes(String(u.pin))))
              setSelected((prev) => {
                const next = { ...prev }
                deletedPins.forEach((p) => { delete next[p] })
                return next
              })
            }
          }
        }
      } catch {
        // abaikan error sesaat
      }
    }
    tick()
    const interval = setInterval(tick, 1500)
    return () => {
      active = false
      clearInterval(interval)
    }
  }, [deleteJobId])

  const columns = useMemo(
    () => [
      {
        id: 'select',
        header: () => (
          <input
            type="checkbox"
            className="h-4 w-4"
            checked={allSelected}
            onChange={toggleSelectAll}
            title="Pilih semua (yang punya sidik jari)"
          />
        ),
        cell: ({ row }) => (
          <input
            type="checkbox"
            className="h-4 w-4"
            checked={!!selected[row.original.pin]}
            onChange={() => toggleSelect(row.original.pin)}
          />
        ),
      },
      {
        accessorKey: 'pin',
        header: 'PIN',
        cell: ({ row }) => <div className="font-mono">{row.getValue('pin')}</div>,
      },
      {
        accessorKey: 'name',
        header: 'Nama',
        cell: ({ row }) => row.getValue('name') || <span className="text-slate-400">-</span>,
      },
      {
        id: 'brand_outlet',
        header: 'Brand - Outlet',
        cell: ({ row }) => {
          const emp = employeesByPin[String(row.original.pin)]
          if (!emp) {
            return <span className="text-xs text-amber-600">belum terdaftar</span>
          }
          const outlets = emp.outlets ?? []
          if (outlets.length === 0) {
            return <span className="text-xs text-slate-400">tanpa outlet</span>
          }
          return (
            <div className="flex flex-col gap-0.5">
              {outlets.map((o, i) => (
                <div key={i} className="text-sm">
                  {[o.brand_name, o.outlet_name].filter(Boolean).join(' - ')}
                </div>
              ))}
            </div>
          )
        },
      },
      {
        accessorKey: 'fingers',
        header: 'Jumlah Jari',
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <Fingerprint className="h-4 w-4 text-indigo-500" />
            {row.getValue('fingers')}
          </div>
        ),
      },
      {
        id: 'actions',
        header: 'Aksi',
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            {row.original.fingers > 0 ? (
              canSync && (
                <Button onClick={() => openPush([row.original.pin])} size="sm" className="gap-2">
                  <Send className="h-3 w-3" />
                  Sebar
                </Button>
              )
            ) : (
              <span className="text-xs text-slate-400">tanpa sidik jari</span>
            )}
            {!canSync && row.original.fingers > 0 && (
              <span className="text-xs text-slate-400">{row.original.fingers} jari</span>
            )}
            {savedPins[row.original.pin] ? (
              <span className="inline-flex items-center gap-1 text-xs text-green-600" title={
                savedPins[row.original.pin].captureError
                  ? `Tersimpan, tapi tarik sidik jari gagal: ${savedPins[row.original.pin].captureError}`
                  : 'Tersimpan ke master'
              }>
                <Check className="h-3.5 w-3.5" />
                {savedPins[row.original.pin].created ? 'Tersimpan' : 'Diperbarui'}
                {savedPins[row.original.pin].fingers > 0 && ` · ${savedPins[row.original.pin].fingers} jari`}
                {savedPins[row.original.pin].captureError && <span className="text-amber-600"> · jari gagal</span>}
              </span>
            ) : (
              canManageEmployee && (
                <Button
                  onClick={() => saveToMaster(row.original)}
                  disabled={savingPin === row.original.pin}
                  variant="outline"
                  size="sm"
                  className="gap-1.5"
                >
                  {savingPin === row.original.pin ? (
                    <Loader2 className="h-3 w-3 animate-spin" />
                  ) : (
                    <UserPlus className="h-3 w-3" />
                  )}
                  Simpan ke Master
                </Button>
              )
            )}
            {canDelete && (
              <Button
                onClick={() => deleteUser(row.original)}
                disabled={deletingPin === row.original.pin}
                variant="destructive"
                size="sm"
                className="gap-1.5"
              >
                {deletingPin === row.original.pin ? (
                  <Loader2 className="h-3 w-3 animate-spin" />
                ) : (
                  <Trash2 className="h-3 w-3" />
                )}
                Hapus
              </Button>
            )}
          </div>
        ),
      },
    ],
    [selected, allSelected, allPins, deletingPin, savingPin, savedPins, sourceId, captureOnSave, employeesByPin]
  )

  const pushUsers = useMemo(
    () => (pushPins ? users.filter((u) => pushPins.includes(u.pin)) : []),
    [pushPins, users]
  )
  const bulkMode = (pushPins?.length || 0) > 1

  return (
    <Layout>
      <Head title="Sidik Jari" />

      <div className="space-y-6">
        <Card>
          <CardContent className="pt-6 text-sm text-slate-600 dark:text-slate-300">
            Sinkronisasi sidik jari antar mesin lewat koneksi langsung (TCP 4370). Pilih mesin sumber,
            muat daftar karyawannya, lalu <b>centang beberapa karyawan</b> dan klik <b>Sebar Terpilih</b>{' '}
            untuk menyalin sidik jari mereka sekaligus ke mesin lain (atau tombol <b>Sebar</b> per baris
            untuk satu orang).
            {withIp.length === 0 && (
              <span className="block mt-2 text-amber-600">
                Belum ada mesin dengan IP. Isi IP mesin dulu di halaman Machines.
              </span>
            )}
          </CardContent>
        </Card>

        {/* Pilih mesin sumber + muat user */}
        <Card>
          <CardHeader>
            <CardTitle>Mesin Sumber</CardTitle>
            <CardDescription>Ambil daftar sidik jari langsung dari mesin ini.</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap items-end gap-3">
              <div className="space-y-2">
                <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Mesin</label>
                <Select
                  value={sourceId}
                  onChange={(e) => setSourceId(e.target.value)}
                  className="w-64"
                >
                  {withIp.length === 0 && <option value="">(tidak ada mesin ber-IP)</option>}
                  {withIp.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.ip_address})
                    </option>
                  ))}
                </Select>
              </div>
              <Button onClick={loadUsers} disabled={!sourceId || loading} className="gap-2">
                {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                {loading ? 'Memuat...' : 'Muat dari Mesin'}
              </Button>
            </div>
            {loadErr && <p className="text-sm text-red-600 mt-3">{loadErr}</p>}
          </CardContent>
        </Card>

        {/* Panel sebar (1 atau banyak PIN) */}
        {pushPins && pushPins.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>
                {bulkMode
                  ? `Sebar Sidik Jari — ${pushPins.length} karyawan`
                  : `Sebar Sidik Jari — PIN ${pushPins[0]}`}
              </CardTitle>
              <CardDescription>
                {bulkMode ? (
                  <>Dari {sourceMachine?.name}. Centang mesin tujuan.</>
                ) : (
                  <>
                    {pushUsers[0]?.name ? `${pushUsers[0].name} · ` : ''}
                    {pushUsers[0]?.fingers} jari · dari {sourceMachine?.name}. Centang mesin tujuan.
                  </>
                )}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {bulkMode && (
                <div className="mb-4 flex flex-wrap gap-1.5">
                  {pushUsers.map((u) => (
                    <span
                      key={u.pin}
                      className="inline-flex items-center gap-1 rounded-full bg-slate-100 dark:bg-slate-800 px-2.5 py-0.5 text-xs"
                    >
                      <span className="font-mono">{u.pin}</span>
                      {u.name && <span className="text-slate-500">· {u.name}</span>}
                    </span>
                  ))}
                </div>
              )}
              <div className="border rounded-md divide-y dark:divide-slate-800 dark:border-slate-800 mb-4">
                {withIp.filter((m) => m.id !== sourceId).map((m) => (
                  <label key={m.id} className="flex items-center gap-3 p-3 cursor-pointer">
                    <input
                      type="checkbox"
                      className="h-4 w-4"
                      checked={!!targets[m.id]}
                      onChange={() => toggleTarget(m.id)}
                    />
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-slate-900 dark:text-slate-50">{m.name}</div>
                      <div className="text-xs text-slate-500 font-mono">{m.ip_address}:{m.sdk_port || 4370}</div>
                    </div>
                  </label>
                ))}
              </div>
              <div className="flex gap-2">
                <Button onClick={submitSync} disabled={syncing} className="gap-2">
                  {syncing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  {syncing ? 'Diproses di background...' : `Sebar ${pushPins.length} Karyawan`}
                </Button>
                <Button variant="outline" onClick={() => setPushPins(null)}>Tutup</Button>
              </div>
              {syncing && (
                <p className="text-xs text-slate-500 mt-2">
                  Berjalan di background — boleh tutup panel ini, proses tetap lanjut.
                </p>
              )}

              {/* Progres & hasil per PIN */}
              {results && (
                <div className="mt-4 space-y-3">
                  {results.status === 'failed' ? (
                    <p className="text-sm font-medium text-red-600">
                      Job gagal: {results.error || 'kesalahan tidak diketahui'}
                    </p>
                  ) : results.summary ? (
                    <p className="text-sm font-medium">
                      Selesai. Sumber {results.source} → {(results.targets || []).join(', ')}.{' '}
                      Berhasil {results.summary.ok}/{results.summary.pins} karyawan
                      {results.summary.failed > 0 && (
                        <span className="text-red-600"> · {results.summary.failed} gagal</span>
                      )}
                    </p>
                  ) : (
                    <div className="space-y-1">
                      <p className="text-sm font-medium">
                        {results.status === 'queued' ? 'Menunggu antrean...' : 'Memproses...'}{' '}
                        {results.progress_done}/{results.progress_total} karyawan
                      </p>
                      <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                        <div
                          className="h-full rounded-full bg-indigo-500 transition-all"
                          style={{
                            width: `${results.progress_total
                              ? Math.round((results.progress_done / results.progress_total) * 100)
                              : 0}%`,
                          }}
                        />
                      </div>
                    </div>
                  )}
                  <div className="space-y-2">
                    {(results.items || []).map((item, i) => (
                      <div key={i} className="rounded-md border dark:border-slate-800 p-3">
                        <div className="flex items-center gap-2 text-sm font-medium">
                          <span className="font-mono">{item.pin}</span>
                          {item.name && <span className="text-slate-500">· {item.name}</span>}
                          {!item.ok && (
                            <span className="text-red-600">— {item.error || 'gagal'}</span>
                          )}
                        </div>
                        {item.ok && (
                          <div className="mt-1 space-y-0.5 pl-1">
                            {item.results.map((r, j) => (
                              <div key={j} className="text-xs flex items-center gap-2">
                                <span className={r.ok ? 'text-green-600' : 'text-red-600'}>
                                  {r.ok ? '✓' : '✗'}
                                </span>
                                <span className="font-medium">{r.machine}</span>
                                <span className="text-slate-500">
                                  {r.ok ? `${r.installed} jari terpasang` : (r.error || 'gagal')}
                                </span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {/* Progres hapus massal (background job) */}
        {deleteJob && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Hapus Massal dari {sourceMachine?.name}</CardTitle>
            </CardHeader>
            <CardContent>
              {deleteJob.status === 'failed' ? (
                <p className="text-sm font-medium text-red-600">
                  Job gagal: {deleteJob.error || 'kesalahan tidak diketahui'}
                </p>
              ) : deleteJob.summary ? (
                <p className="text-sm font-medium">
                  Selesai. Terhapus {deleteJob.summary.ok}/{deleteJob.summary.pins} user
                  {deleteJob.summary.failed > 0 && (
                    <span className="text-red-600"> · {deleteJob.summary.failed} gagal</span>
                  )}
                </p>
              ) : (
                <div className="space-y-1">
                  <p className="text-sm font-medium">
                    {deleteJob.status === 'queued' ? 'Menunggu antrean...' : 'Menghapus...'}{' '}
                    {deleteJob.progress_done}/{deleteJob.progress_total} user
                  </p>
                  <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                    <div
                      className="h-full rounded-full bg-red-500 transition-all"
                      style={{
                        width: `${deleteJob.progress_total
                          ? Math.round((deleteJob.progress_done / deleteJob.progress_total) * 100)
                          : 0}%`,
                      }}
                    />
                  </div>
                </div>
              )}
              {deletingBulk && (
                <p className="text-xs text-slate-500 mt-2">
                  Berjalan di background — boleh tinggalkan halaman, proses tetap lanjut.
                </p>
              )}
              {(deleteJob.items || []).some((it) => !it.ok) && (
                <div className="mt-3 space-y-1">
                  {deleteJob.items.filter((it) => !it.ok).map((it, i) => (
                    <div key={i} className="text-xs flex items-center gap-2">
                      <span className="text-red-600">✗</span>
                      <span className="font-mono">{it.pin}</span>
                      <span className="text-slate-500">{it.error || 'gagal dihapus'}</span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {/* Daftar user dari mesin */}
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <CardTitle>Karyawan di Mesin {sourceMachine ? `— ${sourceMachine.name}` : ''}</CardTitle>
                <CardDescription>
                  {users.length} user terbaca
                  {selectedPins.length > 0 && ` · ${selectedPins.length} dipilih`}
                </CardDescription>
                {canManageEmployee && (
                  <label className="mt-2 flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300 cursor-pointer">
                    <input
                      type="checkbox"
                      className="h-3.5 w-3.5"
                      checked={captureOnSave}
                      onChange={(e) => setCaptureOnSave(e.target.checked)}
                    />
                    Sekalian tarik sidik jari ke DB saat simpan ke master
                  </label>
                )}
              </div>
              {selectedPins.length > 0 && (
                <div className="flex flex-wrap gap-2">
                  {canSync && (
                    <Button
                      onClick={() => openPush(selectedPinsWithFingers)}
                      disabled={selectedPinsWithFingers.length === 0}
                      className="gap-2"
                    >
                      <Send className="h-4 w-4" />
                      Sebar Terpilih ({selectedPinsWithFingers.length})
                    </Button>
                  )}
                  {canManageEmployee && (
                    <Button onClick={saveSelectedToMaster} disabled={bulkSaving} variant="outline" className="gap-2">
                      {bulkSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
                      Simpan Terpilih ke Master ({selectedPins.length})
                    </Button>
                  )}
                  {canDelete && (
                    <Button onClick={deleteSelected} disabled={deletingBulk} variant="destructive" className="gap-2">
                      {deletingBulk ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                      Hapus Terpilih ({selectedPins.length})
                    </Button>
                  )}
                  <Button variant="outline" onClick={clearSelection} className="gap-2">
                    <X className="h-4 w-4" />
                    Bersihkan
                  </Button>
                </div>
              )}
            </div>
          </CardHeader>
          <CardContent>
            <DataTable columns={columns} data={users} filterPlaceholder="Cari PIN / nama..." />
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
