import React, { useState, useEffect } from 'react'
import { Head, router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Cpu, Trash2, Plus, Pencil, Power, FileClock, Users, Clock, Loader2, Gauge, Fingerprint, ShieldCheck, Database, RefreshCw, Plug } from 'lucide-react'

const emptyForm = { serial_number: '', name: '', location: '', ip_address: '', sdk_port: 4370, is_active: true }

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Satu baris kapasitas (terpakai / maksimum) dengan progress bar.
function CapacityRow({ icon: Icon, label, used, max }) {
  const hasMax = max != null && max > 0
  const pct = hasMax ? Math.min(100, Math.round((used / max) * 100)) : 0
  const danger = pct >= 90
  const warn = pct >= 75 && pct < 90
  const barColor = danger ? 'bg-red-500' : warn ? 'bg-amber-500' : 'bg-emerald-500'
  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between text-xs">
        <span className="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
          <Icon className="h-3.5 w-3.5 text-slate-400" />
          {label}
        </span>
        <span className="font-mono text-slate-700 dark:text-slate-200">
          {used ?? '–'}{hasMax ? ` / ${max}` : ''}
          {hasMax && <span className="ml-1 text-slate-400">({pct}%)</span>}
        </span>
      </div>
      {hasMax && (
        <div className="h-1.5 w-full rounded-full bg-slate-200 dark:bg-slate-700">
          <div className={`h-1.5 rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  )
}

export default function Machines({ machines = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)
  const [syncingId, setSyncingId] = useState(null)
  // Kapasitas mesin (LIVE via TCP 4370), per machine id: { loading, error, data }
  const [caps, setCaps] = useState({})
  const [clearingId, setClearingId] = useState(null)
  const [probingId, setProbingId] = useState(null)

  // Uji koneksi jalur TCP 4370 (server -> mesin) sekarang juga, lalu perbarui badge.
  const probeTcp = async (machine) => {
    setProbingId(machine.id)
    try {
      const res = await fetch(`/api/machines/${machine.id}/probe-tcp`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      })
      const data = await res.json()
      if (res.ok && data.ok) {
        toast.success(
          `TCP 4370 tersambung ke "${machine.name}"` +
          (data.latency_ms != null ? ` (latensi ${data.latency_ms} ms).` : '.')
        )
      } else {
        toast.error(`Gagal menyambung ke "${machine.name}": ${data.error || 'Tidak terjangkau'}`)
      }
    } catch (err) {
      console.error('Failed to probe TCP:', err)
      toast.error('Gagal menghubungi mesin')
    } finally {
      setProbingId(null)
      // Segarkan badge status TCP dari hasil probe terbaru.
      router.reload({ only: ['machines'] })
    }
  }

  // Hapus SEMUA log presensi (records) di mesin. Permanen di perangkat.
  const clearAttendance = (machine) => {
    confirmToast({
      message: `Hapus PERMANEN semua log presensi di mesin "${machine.name}"?`,
      description:
        'Mengosongkan memori log di perangkat dan TIDAK BISA dibatalkan. Log yang sudah masuk ke aplikasi/Talenta tidak terpengaruh.',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: () => runClearAttendance(machine),
    })
  }

  const runClearAttendance = async (machine) => {
    setClearingId(machine.id)
    try {
      const res = await fetch(`/api/machines/${machine.id}/clear-attendance`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      })
      const data = await res.json()
      if (res.ok && data.ok) {
        toast.success(`Berhasil. Log presensi mesin: ${data.records_before ?? '?'} → ${data.records_after ?? 0}.`)
        await loadCapacity(machine)
      } else {
        toast.error(data.error || 'Gagal menghapus log mesin')
      }
    } catch (err) {
      console.error('Failed to clear attendance:', err)
      toast.error('Gagal menghubungi mesin')
    } finally {
      setClearingId(null)
    }
  }

  // Baca kapasitas LIVE dari mesin (user/admin/jari/log terpakai vs maksimum).
  const loadCapacity = async (machine) => {
    setCaps((prev) => ({ ...prev, [machine.id]: { loading: true } }))
    try {
      const res = await fetch(`/api/machines/${machine.id}/zk-info`, {
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      })
      const data = await res.json()
      if (res.ok && data.ok) {
        setCaps((prev) => ({ ...prev, [machine.id]: { data } }))
      } else {
        setCaps((prev) => ({ ...prev, [machine.id]: { error: data.error || 'Gagal membaca kapasitas mesin' } }))
      }
    } catch (err) {
      console.error('Failed to load capacity:', err)
      setCaps((prev) => ({ ...prev, [machine.id]: { error: 'Gagal menghubungi mesin' } }))
    }
  }

  const syncTime = async (machine) => {
    setSyncingId(machine.id)
    try {
      const res = await fetch(`/api/machines/${machine.id}/sync-time`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
      })
      const data = await res.json()
      const msg = data.message || (res.ok ? 'Perintah sync time diantrekan.' : 'Gagal sync time')
      if (res.ok) toast.success(msg)
      else toast.error(msg)
    } catch (err) {
      console.error('Failed to sync time:', err)
      toast.error('Gagal mengirim perintah sync time')
    } finally {
      setSyncingId(null)
    }
  }

  // Auto-refresh status online/offline tiap 30 detik.
  useEffect(() => {
    const interval = setInterval(() => {
      router.reload({ only: ['machines'] })
    }, 30000)
    return () => clearInterval(interval)
  }, [])

  const openCreate = () => {
    setEditingId(null)
    setFormData(emptyForm)
    setShowForm(true)
  }

  const openEdit = (machine) => {
    setEditingId(machine.id)
    setFormData({
      serial_number: machine.serial_number,
      name: machine.name ?? '',
      location: machine.location ?? '',
      ip_address: machine.ip_address ?? '',
      sdk_port: machine.sdk_port ?? 4370,
      is_active: machine.is_active,
    })
    setShowForm(true)
  }

  const closeForm = () => {
    setFormData(emptyForm)
    setEditingId(null)
    setShowForm(false)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const url = editingId ? `/api/machines/${editingId}` : '/api/machines'
    const method = editingId ? 'PUT' : 'POST'
    try {
      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(formData),
      })
      if (response.ok) {
        closeForm()
        router.reload({ only: ['machines'] })
      } else {
        const body = await response.json().catch(() => ({}))
        toast.error(body.message || 'Gagal menyimpan mesin')
      }
    } catch (err) {
      console.error('Failed to save machine:', err)
    }
  }

  const toggleActive = async (machine) => {
    try {
      await fetch(`/api/machines/${machine.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
          serial_number: machine.serial_number,
          name: machine.name,
          location: machine.location,
          is_active: !machine.is_active,
        }),
      })
      router.reload({ only: ['machines'] })
    } catch (err) {
      console.error('Failed to toggle machine:', err)
    }
  }

  const deleteMachine = (id) => {
    confirmToast({
      message: 'Hapus mesin ini?',
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        try {
          await fetch(`/api/machines/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf() },
          })
          router.reload({ only: ['machines'] })
        } catch (err) {
          console.error('Failed to delete machine:', err)
        }
      },
    })
  }

  return (
    <Layout>
      <Head title="Machines" />

      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Machines</h1>
          <Button onClick={showForm ? closeForm : openCreate} className="gap-2">
            <Plus className="h-4 w-4" />
            {showForm ? 'Cancel' : 'Add Machine'}
          </Button>
        </div>

        {/* Add / Edit Machine Form */}
        {showForm && (
          <Card>
            <CardHeader>
              <CardTitle>{editingId ? 'Edit Machine' : 'Register New Machine'}</CardTitle>
              <CardDescription>
                {editingId
                  ? 'Update detail mesin fingerprint'
                  : 'Add a new fingerprint machine to the system'}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Serial Number
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.serial_number}
                      onChange={(e) => setFormData({ ...formData, serial_number: e.target.value })}
                      placeholder="e.g., ABC123"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Name
                    </label>
                    <Input
                      type="text"
                      required
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      placeholder="e.g., Jakarta Office"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      Location
                    </label>
                    <Input
                      type="text"
                      value={formData.location}
                      onChange={(e) => setFormData({ ...formData, location: e.target.value })}
                      placeholder="e.g., Building A, Floor 1"
                    />
                  </div>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      IP Address (LAN) <span className="text-xs text-slate-400">— untuk sync sidik jari</span>
                    </label>
                    <Input
                      type="text"
                      value={formData.ip_address}
                      onChange={(e) => setFormData({ ...formData, ip_address: e.target.value })}
                      placeholder="e.g., 192.168.1.25"
                    />
                  </div>
                  <div className="space-y-2">
                    <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                      SDK Port
                    </label>
                    <Input
                      type="number"
                      value={formData.sdk_port}
                      onChange={(e) => setFormData({ ...formData, sdk_port: e.target.value })}
                      placeholder="4370"
                    />
                  </div>
                </div>
                <label className="flex items-center gap-2 text-sm font-medium text-slate-900 dark:text-slate-50">
                  <input
                    type="checkbox"
                    className="h-4 w-4"
                    checked={formData.is_active}
                    onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                  />
                  Aktif (terima data dari mesin ini)
                </label>
                <div className="flex gap-2">
                  <Button type="submit">{editingId ? 'Update Machine' : 'Save Machine'}</Button>
                  <Button type="button" variant="outline" onClick={closeForm}>
                    Cancel
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Machine List */}
        <div className="grid grid-cols-1 gap-4">
          {machines.length === 0 ? (
            <Card className="text-center py-12">
              <CardContent className="flex flex-col items-center gap-4">
                <Cpu className="h-12 w-12 text-slate-400" />
                <div>
                  <h3 className="font-semibold text-slate-900 dark:text-slate-50">No machines registered</h3>
                  <p className="text-sm text-slate-500 dark:text-slate-400">
                    Add your first fingerprint machine to get started
                  </p>
                </div>
              </CardContent>
            </Card>
          ) : (
            machines.map(machine => (
              <Card key={machine.id} className={machine.is_active ? '' : 'opacity-60'}>
                <CardContent className="pt-6">
                  <div className="flex justify-between items-start gap-4">
                    <div className="space-y-2 flex-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className="text-lg font-semibold text-slate-900 dark:text-slate-50">{machine.name}</h3>
                        <Badge variant={machine.is_online ? 'success' : 'secondary'}>
                          ADMS: {machine.is_online ? 'online' : 'offline'}
                        </Badge>
                        {machine.ip_address && (() => {
                          // Status jalur TCP 4370 (server -> mesin), independen dari ADMS.
                          // null = monitor mati / belum diprobe / hasil basi.
                          if (machine.tcp_ready === true) {
                            return (
                              <Badge variant="success" title={
                                (machine.tcp_latency_ms != null ? `latensi ${machine.tcp_latency_ms} ms · ` : '') +
                                (machine.tcp_checked_at ? `dicek ${new Date(machine.tcp_checked_at).toLocaleString()}` : '')
                              }>
                                TCP 4370: ready
                              </Badge>
                            )
                          }
                          if (machine.tcp_ready === false) {
                            return (
                              <Badge variant="destructive" title={machine.tcp_error || 'Tidak terjangkau'}>
                                TCP 4370: unreachable
                              </Badge>
                            )
                          }
                          return (
                            <Badge variant="secondary" title="Monitor TCP nonaktif atau belum diperiksa (aktifkan di Settings).">
                              TCP 4370: —
                            </Badge>
                          )
                        })()}
                        {!machine.is_active && <Badge variant="destructive">nonaktif</Badge>}
                      </div>
                      <p className="text-sm text-slate-600 dark:text-slate-400">Serial: {machine.serial_number}</p>
                      {machine.location && (
                        <p className="text-sm text-slate-500 dark:text-slate-400">Location: {machine.location}</p>
                      )}
                      <p className="text-sm text-slate-500 dark:text-slate-400">
                        IP: {machine.ip_address
                          ? <span className="font-mono">{machine.ip_address}:{machine.sdk_port || 4370}</span>
                          : <span className="text-amber-600">belum diisi (sync sidik jari nonaktif)</span>}
                      </p>
                      {machine.last_seen_at && (
                        <p className="text-xs text-slate-400 dark:text-slate-500">
                          Last seen: {new Date(machine.last_seen_at).toLocaleString()}
                        </p>
                      )}

                      {/* Statistik per mesin */}
                      <div className="flex flex-wrap gap-4 pt-2">
                        <div className="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300">
                          <FileClock className="h-4 w-4 text-slate-400" />
                          <span className="font-semibold">{machine.logs_count}</span> log
                        </div>
                        {machine.last_log_at && (
                          <div className="text-sm text-slate-500 dark:text-slate-400">
                            Log terakhir: {new Date(machine.last_log_at).toLocaleString()}
                          </div>
                        )}
                      </div>

                      {/* Kapasitas mesin (LIVE dari perangkat via TCP 4370) */}
                      <div className="pt-3">
                        {(() => {
                          const cap = caps[machine.id]
                          if (!machine.ip_address) {
                            return (
                              <p className="text-xs text-amber-600">
                                Kapasitas mesin butuh IP LAN terisi.
                              </p>
                            )
                          }
                          if (!cap) {
                            return (
                              <Button
                                onClick={() => loadCapacity(machine)}
                                variant="outline"
                                size="sm"
                                className="gap-2"
                              >
                                <Gauge className="h-4 w-4" />
                                Cek Kapasitas Mesin
                              </Button>
                            )
                          }
                          if (cap.loading) {
                            return (
                              <div className="flex items-center gap-2 text-sm text-slate-500">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Membaca kapasitas dari mesin…
                              </div>
                            )
                          }
                          if (cap.error) {
                            return (
                              <div className="flex items-center gap-3">
                                <p className="text-xs text-red-600">{cap.error}</p>
                                <Button onClick={() => loadCapacity(machine)} variant="outline" size="sm" className="gap-2">
                                  <RefreshCw className="h-3.5 w-3.5" />
                                  Coba lagi
                                </Button>
                              </div>
                            )
                          }
                          const d = cap.data
                          return (
                            <div className="space-y-3 rounded-lg border border-slate-200 dark:border-slate-700 p-3 max-w-md">
                              <div className="flex items-center justify-between">
                                <span className="flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">
                                  <Gauge className="h-4 w-4 text-slate-400" />
                                  Kapasitas Mesin
                                </span>
                                <Button onClick={() => loadCapacity(machine)} variant="ghost" size="sm" className="gap-1.5 h-7 px-2">
                                  <RefreshCw className="h-3.5 w-3.5" />
                                  Refresh
                                </Button>
                              </div>
                              <CapacityRow icon={Users} label="User" used={d.users} max={d.users_cap} />
                              <CapacityRow icon={ShieldCheck} label="User Admin" used={d.admin_count} max={null} />
                              <CapacityRow icon={Fingerprint} label="Sidik Jari" used={d.fingers} max={d.fingers_cap} />
                              <CapacityRow icon={Database} label="Log Presensi" used={d.records} max={d.records_cap} />
                              <div className="flex items-center justify-between gap-2 border-t border-slate-100 dark:border-slate-800 pt-2">
                                <span className="text-[11px] text-slate-400">
                                  Bebaskan kapasitas log mesin
                                </span>
                                <Button
                                  onClick={() => clearAttendance(machine)}
                                  disabled={clearingId === machine.id}
                                  variant="destructive"
                                  size="sm"
                                  className="gap-1.5 h-7"
                                >
                                  {clearingId === machine.id ? (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                  ) : (
                                    <Trash2 className="h-3.5 w-3.5" />
                                  )}
                                  Clear Log Mesin
                                </Button>
                              </div>
                              {(d.device_name || d.firmware) && (
                                <p className="text-[11px] text-slate-400 pt-1">
                                  {d.device_name}{d.device_name && d.firmware ? ' · ' : ''}
                                  {d.firmware && `firmware ${d.firmware}`}
                                </p>
                              )}
                            </div>
                          )
                        })()}
                      </div>
                    </div>

                    <div className="flex flex-col items-end gap-2">
                      <Button
                        onClick={() => probeTcp(machine)}
                        disabled={probingId === machine.id || !machine.ip_address}
                        title={machine.ip_address ? 'Uji koneksi jalur TCP 4370 ke mesin' : 'Isi IP LAN mesin dulu untuk menguji koneksi'}
                        variant="secondary"
                        size="sm"
                        className="gap-2"
                      >
                        {probingId === machine.id ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Plug className="h-4 w-4" />
                        )}
                        Connect TCP
                      </Button>
                      <Button
                        onClick={() => syncTime(machine)}
                        disabled={syncingId === machine.id}
                        variant="secondary"
                        size="sm"
                        className="gap-2"
                      >
                        {syncingId === machine.id ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                          <Clock className="h-4 w-4" />
                        )}
                        Sync Time
                      </Button>
                      <Button
                        onClick={() => toggleActive(machine)}
                        variant={machine.is_active ? 'outline' : 'default'}
                        size="sm"
                        className="gap-2"
                      >
                        <Power className="h-4 w-4" />
                        {machine.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      </Button>
                      <Button onClick={() => openEdit(machine)} variant="outline" size="sm" className="gap-2">
                        <Pencil className="h-4 w-4" />
                        Edit
                      </Button>
                      <Button
                        onClick={() => deleteMachine(machine.id)}
                        variant="destructive"
                        size="sm"
                        className="gap-2"
                      >
                        <Trash2 className="h-4 w-4" />
                        Delete
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            ))
          )}
        </div>
      </div>
    </Layout>
  )
}
