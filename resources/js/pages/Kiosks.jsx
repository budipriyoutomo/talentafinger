import React, { useEffect, useMemo, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Combobox } from '@/components/ui/combobox'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AlertTriangle, Copy, KeyRound, Loader2, Pencil, Plus, Power, QrCode, RotateCcw, Trash2, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

async function api(url, method = 'GET', body) {
  const res = await fetch(url, {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || `Gagal (HTTP ${res.status})`)
  }
  return data
}

// "ABCDEFGH" -> "ABCD-EFGH" supaya mudah dibacakan; tablet mengabaikan tanda hubung.
const formatCode = (code) => (code?.length === 8 ? `${code.slice(0, 4)}-${code.slice(4)}` : code)

const formatTime = (iso) => (iso ? new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '—')

function statusOf(kiosk) {
  if (!kiosk.is_active) return { label: 'Nonaktif', variant: 'destructive' }
  if (!kiosk.is_activated) return { label: 'Belum diaktivasi', variant: 'warning' }
  return kiosk.is_online ? { label: 'Online', variant: 'success' } : { label: 'Offline', variant: 'secondary' }
}

function Dialog({ title, icon: Icon = QrCode, onClose, busy, children }) {
  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && !busy && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busy, onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      onClick={(e) => e.target === e.currentTarget && !busy && onClose()}
    >
      <div className="mt-10 w-full max-w-md rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-center justify-between border-b border-border p-4">
          <h3 className="flex items-center gap-2 font-semibold text-foreground">
            <Icon className="h-5 w-5 text-indigo-500" /> {title}
          </h3>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup" disabled={busy}>
            <X className="h-4 w-4" />
          </Button>
        </div>
        <div className="space-y-4 p-4">{children}</div>
      </div>
    </div>
  )
}

/** Form tambah (kiosk null) atau ubah nama (kiosk terisi). Outlet tak bisa dipindah. */
function KioskFormDialog({ kiosk, outlets, onClose, onSaved }) {
  const [name, setName] = useState(kiosk?.name ?? '')
  const [outletId, setOutletId] = useState(kiosk?.outlet_id ?? '')
  const [busy, setBusy] = useState(false)

  const options = useMemo(
    () => outlets.map((o) => ({
      value: o.id,
      label: o.name,
      description: [o.brand_name, !o.has_coordinates && 'belum ada koordinat'].filter(Boolean).join(' · '),
    })),
    [outlets],
  )
  const selected = outlets.find((o) => o.id === outletId)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    try {
      const data = kiosk
        ? await api(`/api/kiosks/${kiosk.id}`, 'PUT', { name })
        : await api('/api/kiosks', 'POST', { name, outlet_id: outletId })
      onSaved(data)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog title={kiosk ? 'Ubah Kiosk' : 'Tambah Kiosk'} icon={kiosk ? Pencil : Plus} onClose={onClose} busy={busy}>
      <form onSubmit={submit} className="space-y-4">
        <div className="space-y-2">
          <label className="text-sm font-medium text-foreground">Nama tablet</label>
          <Input required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} placeholder="mis. Kasir Depan" />
        </div>
        {kiosk ? (
          <p className="text-sm text-muted-foreground">
            Outlet: <span className="font-medium text-foreground">{kiosk.outlet_name}</span>. Untuk pindah outlet,
            hapus kiosk ini dan buat yang baru.
          </p>
        ) : (
          <div className="space-y-2">
            <label className="text-sm font-medium text-foreground">Outlet</label>
            <Combobox
              options={options}
              value={outletId}
              onChange={setOutletId}
              placeholder="Pilih outlet…"
              searchPlaceholder="Cari outlet…"
            />
            {selected && !selected.has_coordinates && (
              <p className="flex gap-1.5 text-xs text-amber-600 dark:text-amber-400">
                <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                Outlet ini belum punya titik koordinat — absen QR akan diblokir sampai koordinat diisi di Struktur Organisasi.
              </p>
            )}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={busy}>Batal</Button>
          <Button type="submit" disabled={busy || !name.trim() || (!kiosk && !outletId)} className="gap-2">
            {busy && <Loader2 className="h-4 w-4 animate-spin" />}
            {kiosk ? 'Simpan' : 'Buat & Dapatkan Kode'}
          </Button>
        </div>
      </form>
    </Dialog>
  )
}

/** Kode aktivasi plaintext — hanya ditampilkan sekali, tepat setelah dibuat/reset. */
function ActivationCodeDialog({ activation, onClose }) {
  const kioskUrl = `${window.location.origin}/kiosk`
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(activation.activation_code)
      toast.success('Kode disalin')
    } catch {
      toast.error('Gagal menyalin, catat kode secara manual.')
    }
  }

  return (
    <Dialog title={`Kode Aktivasi — ${activation.kiosk.name}`} icon={KeyRound} onClose={onClose}>
      <div className="rounded-xl border border-border bg-muted/40 p-4 text-center">
        <p className="select-all font-mono text-4xl font-bold tracking-[0.2em] text-foreground">
          {formatCode(activation.activation_code)}
        </p>
        <p className="mt-2 text-xs text-muted-foreground">
          Berlaku sampai {formatTime(activation.activation_expires_at)} · sekali pakai
        </p>
      </div>
      <ol className="list-decimal space-y-1 pl-5 text-sm text-foreground">
        <li>Di tablet outlet <b>{activation.kiosk.outlet_name}</b>, buka <span className="font-mono">{kioskUrl}</span></li>
        <li>Masukkan kode di atas, lalu tekan <b>Aktifkan Tablet</b>.</li>
      </ol>
      <p className="text-xs text-amber-600 dark:text-amber-400">
        Kode tidak bisa dilihat lagi setelah jendela ini ditutup. Bila hilang/kedaluwarsa, pakai “Reset aktivasi”.
      </p>
      {!activation.kiosk.outlet_has_coordinates && (
        <p className="flex gap-1.5 text-xs text-amber-600 dark:text-amber-400">
          <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
          Outlet belum punya titik koordinat — absen QR diblokir sampai koordinat diisi.
        </p>
      )}
      <div className="flex justify-end gap-2">
        <Button variant="outline" onClick={copy} className="gap-2"><Copy className="h-4 w-4" /> Salin kode</Button>
        <Button onClick={onClose}>Selesai</Button>
      </div>
    </Dialog>
  )
}

/**
 * Admin: daftar tablet kiosk absen QR per outlet + aktivasi/reset.
 * Server menyaring ke outlet dalam scope user (AttendanceKioskPolicy).
 */
export default function Kiosks({ kiosks = [], outlets = [] }) {
  const [form, setForm] = useState(null) // null | { kiosk: null | kiosk }
  const [activation, setActivation] = useState(null)
  const [busyId, setBusyId] = useState(null)

  const reload = () => router.reload({ only: ['kiosks'] })

  // Status online/offline ikut segar tanpa muat ulang halaman.
  useEffect(() => {
    const id = setInterval(reload, 30000)
    return () => clearInterval(id)
  }, [])

  const run = async (kiosk, fn) => {
    setBusyId(kiosk.id)
    try {
      await fn()
    } catch (err) {
      toast.error(err.message)
    } finally {
      setBusyId(null)
      reload()
    }
  }

  const toggleActive = (kiosk) => run(kiosk, async () => {
    await api(`/api/kiosks/${kiosk.id}`, 'PUT', { is_active: !kiosk.is_active })
    toast.success(kiosk.is_active ? 'Kiosk dinonaktifkan' : 'Kiosk diaktifkan')
  })

  const reset = (kiosk) => confirmToast({
    message: `Reset aktivasi "${kiosk.name}"?`,
    description: 'Tablet yang sekarang langsung terputus dan semua QR lama tidak berlaku. Anda akan mendapat kode aktivasi baru.',
    confirmLabel: 'Reset',
    destructive: true,
    onConfirm: () => run(kiosk, async () => setActivation(await api(`/api/kiosks/${kiosk.id}/reset`, 'POST'))),
  })

  const remove = (kiosk) => confirmToast({
    message: `Hapus kiosk "${kiosk.name}"?`,
    description: 'Riwayat absen QR dari kiosk ini tetap tersimpan.',
    confirmLabel: 'Hapus',
    destructive: true,
    onConfirm: () => run(kiosk, async () => {
      await api(`/api/kiosks/${kiosk.id}`, 'DELETE')
      toast.success('Kiosk dihapus')
    }),
  })

  const onSaved = (data) => {
    setForm(null)
    if (data.activation_code) setActivation(data)
    else toast.success('Kiosk disimpan')
    reload()
  }

  return (
    <Layout>
      <Head title="Kiosk QR" />

      <div className="space-y-6">
        <Card>
          <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
            <div className="space-y-1.5">
              <CardTitle>Kiosk Absen QR</CardTitle>
              <CardDescription>
                Tablet di outlet yang menampilkan QR berganti tiap beberapa detik. Karyawan scan pakai HP sendiri,
                lalu kirim selfie + lokasi; hasilnya menunggu review di menu Logs.
              </CardDescription>
            </div>
            <Button onClick={() => setForm({ kiosk: null })} className="shrink-0 gap-2">
              <Plus className="h-4 w-4" /> Tambah Kiosk
            </Button>
          </CardHeader>
          <CardContent>
            {kiosks.length === 0 ? (
              <div className="flex flex-col items-center gap-3 py-10 text-center">
                <QrCode className="h-12 w-12 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">Belum ada kiosk. Tambahkan satu untuk tiap tablet outlet.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Nama</TableHead>
                      <TableHead>Outlet</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Terakhir terlihat</TableHead>
                      <TableHead className="text-right">Aksi</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {kiosks.map((kiosk) => {
                      const status = statusOf(kiosk)
                      const busy = busyId === kiosk.id
                      return (
                        <TableRow key={kiosk.id} className={kiosk.is_active ? '' : 'opacity-60'}>
                          <TableCell className="font-medium">{kiosk.name}</TableCell>
                          <TableCell>
                            <div className="flex items-center gap-1.5">
                              {kiosk.outlet_name || '—'}
                              {!kiosk.outlet_has_coordinates && (
                                <span title="Outlet belum punya titik koordinat — absen QR diblokir">
                                  <AlertTriangle className="h-3.5 w-3.5 text-amber-500" />
                                </span>
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            <Badge variant={status.variant}>{status.label}</Badge>
                            {!kiosk.is_activated && kiosk.activation_pending && (
                              <p className="mt-1 text-xs text-muted-foreground">
                                kode berlaku s/d {formatTime(kiosk.activation_expires_at)}
                              </p>
                            )}
                          </TableCell>
                          <TableCell className="text-sm text-muted-foreground">{formatTime(kiosk.last_seen_at)}</TableCell>
                          <TableCell>
                            <div className="flex flex-wrap justify-end gap-1.5">
                              <Button size="sm" variant="outline" className="gap-1.5" onClick={() => setForm({ kiosk })} disabled={busy}>
                                <Pencil className="h-3.5 w-3.5" /> Edit
                              </Button>
                              <Button size="sm" variant="outline" className="gap-1.5" onClick={() => toggleActive(kiosk)} disabled={busy}>
                                <Power className="h-3.5 w-3.5" /> {kiosk.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                              </Button>
                              <Button size="sm" variant="outline" className="gap-1.5" onClick={() => reset(kiosk)} disabled={busy}>
                                {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
                                Reset aktivasi
                              </Button>
                              <Button size="sm" variant="destructive" className="gap-1.5" onClick={() => remove(kiosk)} disabled={busy}>
                                <Trash2 className="h-3.5 w-3.5" /> Hapus
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {form && (
        <KioskFormDialog kiosk={form.kiosk} outlets={outlets} onClose={() => setForm(null)} onSaved={onSaved} />
      )}
      {activation && <ActivationCodeDialog activation={activation} onClose={() => setActivation(null)} />}
    </Layout>
  )
}
