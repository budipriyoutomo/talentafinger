import React, { useCallback, useEffect, useState } from 'react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Loader2, RotateCcw, Smartphone, X } from 'lucide-react'

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content

/**
 * Perangkat (HP/browser) yang dipakai karyawan untuk absen foto / QR.
 * Reset dipakai saat karyawan ganti HP: perangkat lama tak dihitung aktif,
 * jadi absen dari HP baru tidak ditandai "HP baru". Server memeriksa izin
 * employee.manage + scope outlet, dan mencatat reset ke audit log.
 */
export default function EmployeeDevicesDialog({ employee, onClose }) {
  const [devices, setDevices] = useState(null)
  const [busyId, setBusyId] = useState(null)

  const load = useCallback(async () => {
    try {
      const res = await fetch(`/api/employees/${employee.id}/devices`, { headers: { Accept: 'application/json' } })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        toast.error(data.message || 'Gagal memuat perangkat')
        setDevices([])
        return
      }
      setDevices(data.devices || [])
    } catch (err) {
      toast.error('Gagal memuat perangkat: ' + err.message)
      setDevices([])
    }
  }, [employee.id])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && !busyId && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busyId, onClose])

  const reset = (device) => {
    confirmToast({
      message: 'Reset perangkat ini?',
      description: 'Dipakai saat karyawan ganti HP. Perangkat ini tak lagi dihitung aktif.',
      confirmLabel: 'Reset',
      onConfirm: async () => {
        setBusyId(device.id)
        try {
          const res = await fetch(`/api/employees/${employee.id}/devices/${device.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
          })
          const data = await res.json().catch(() => ({}))
          if (res.ok) {
            toast.success(data.message)
            await load()
          } else {
            toast.error(data.message || 'Gagal mereset perangkat')
          }
        } catch (err) {
          toast.error('Gagal mereset perangkat: ' + err.message)
        } finally {
          setBusyId(null)
        }
      },
    })
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      onClick={(e) => e.target === e.currentTarget && !busyId && onClose()}
    >
      <div className="mt-10 w-full max-w-lg rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <Smartphone className="h-5 w-5 text-indigo-500" />
            <div>
              <h3 className="font-semibold text-foreground">Perangkat Absen — {employee.name}</h3>
              <p className="text-xs text-muted-foreground">HP/browser yang dipakai untuk absen foto & QR</p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup" disabled={!!busyId}>
            <X className="h-4 w-4" />
          </Button>
        </div>

        <div className="space-y-3 p-4">
          {devices === null ? (
            <div className="flex justify-center p-6">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : devices.length === 0 ? (
            <p className="p-6 text-center text-sm text-muted-foreground">Belum ada perangkat tercatat.</p>
          ) : (
            <ul className="divide-y divide-slate-200 dark:divide-slate-800">
              {devices.map((d) => (
                <li key={d.id} className="flex items-start justify-between gap-3 py-3">
                  <div className="min-w-0 space-y-1 text-sm">
                    <div className="flex items-center gap-2">
                      <Badge variant={d.revoked_at ? 'secondary' : 'success'}>
                        {d.revoked_at ? 'Direset' : 'Aktif'}
                      </Badge>
                      <span className="text-xs text-muted-foreground">terakhir {d.last_seen_at}</span>
                    </div>
                    <p className="break-words text-xs text-muted-foreground" title={d.user_agent || ''}>
                      {d.user_agent || '(browser tak dikenal)'}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      pertama {d.first_seen_at}
                      {d.revoked_at && ` • direset ${d.revoked_at}`}
                    </p>
                  </div>
                  {!d.revoked_at && (
                    <Button
                      variant="outline"
                      size="sm"
                      className="shrink-0 gap-1"
                      disabled={!!busyId}
                      onClick={() => reset(d)}
                    >
                      {busyId === d.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <RotateCcw className="h-3 w-3" />}
                      Reset
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
          <p className="text-xs text-muted-foreground">
            Hapus cookie, mode incognito, atau ganti browser juga terbaca sebagai perangkat baru.
          </p>
        </div>
      </div>
    </div>
  )
}
