import React, { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Camera, Check, Loader2, MapPin, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Label status yang dipahami supervisor (bukan nilai mentah DB).
export const STATUS_LABEL = {
  pending_review: 'Perlu review',
  pending: 'Pending',
  sent: 'Terkirim',
  failed: 'Gagal',
  rejected: 'Ditolak',
  duplicate: 'Duplikat',
}

export const STATUS_VARIANT = {
  pending_review: 'info',
  pending: 'warning',
  sent: 'success',
  failed: 'destructive',
  rejected: 'destructive',
  duplicate: 'secondary',
}

/**
 * Detail absen manual pakai foto + aksi review supervisor.
 *
 * Foto diambil dari route terlindung (/api/attendance-logs/{id}/photo), bukan
 * URL publik. Tombol Setujui/Tolak hanya tampil bila status masih
 * 'pending_review' DAN user punya izin attendance.review — server tetap yang
 * memutuskan (policy + scope outlet).
 */
export default function ManualReviewDialog({ log, canReview, onClose, onReviewed }) {
  const manual = log.manual || {}
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(null) // 'approve' | 'reject' | null
  const [photoFailed, setPhotoFailed] = useState(false)

  const reviewable = canReview && log.status_sync === 'pending_review'

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && !busy && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busy, onClose])

  const submit = async (action) => {
    if (action === 'reject' && !note.trim()) {
      toast.error('Isi alasan penolakan dulu.')
      return
    }
    setBusy(action)
    try {
      const res = await fetch(`/api/attendance-logs/${log.id}/review`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ action, note: note.trim() || null }),
      })
      const data = await res.json().catch(() => ({}))
      if (res.ok) {
        toast.success(data.message)
        onReviewed()
      } else {
        toast.error(data.message || 'Gagal menyimpan review')
      }
    } catch (err) {
      toast.error('Gagal menyimpan review: ' + err.message)
    } finally {
      setBusy(null)
    }
  }

  const hasCoords = manual.latitude != null && manual.longitude != null

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4"
      onClick={(e) => e.target === e.currentTarget && !busy && onClose()}
    >
      <div className="mt-10 w-full max-w-lg rounded-lg bg-white shadow-xl dark:bg-slate-900">
        <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <Camera className="h-5 w-5 text-indigo-500" />
            <div>
              <h3 className="font-semibold text-slate-900 dark:text-slate-50">
                Absen Foto — {log.employee_name || 'Karyawan'}
              </h3>
              <p className="text-xs text-slate-500">{log.timestamp_label}</p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} aria-label="Tutup" disabled={!!busy}>
            <X className="h-4 w-4" />
          </Button>
        </div>

        <div className="space-y-4 p-4">
          <div className="overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-800 dark:bg-slate-950">
            {photoFailed ? (
              <p className="p-8 text-center text-sm text-slate-500">Foto tidak bisa dimuat.</p>
            ) : (
              <img
                src={manual.photo_url}
                alt={`Foto absen ${log.employee_name || ''}`}
                className="mx-auto max-h-96 w-auto object-contain"
                onError={() => setPhotoFailed(true)}
              />
            )}
          </div>

          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt className="text-slate-500">Status</dt>
            <dd>
              <Badge variant={STATUS_VARIANT[log.status_sync] || 'secondary'}>
                {STATUS_LABEL[log.status_sync] || log.status_sync}
              </Badge>
            </dd>
            <dt className="text-slate-500">Outlet</dt>
            <dd className="text-slate-900 dark:text-slate-100">{manual.outlet_name || '-'}</dd>
            <dt className="text-slate-500">Jarak dari outlet</dt>
            <dd className="text-slate-900 dark:text-slate-100">
              {manual.distance_meters != null ? `±${manual.distance_meters} m` : '-'}
              {manual.accuracy_meters != null && (
                <span className="text-slate-500"> (akurasi GPS ±{manual.accuracy_meters} m)</span>
              )}
            </dd>
            <dt className="text-slate-500">Lokasi</dt>
            <dd>
              {hasCoords ? (
                <a
                  href={`https://www.google.com/maps?q=${manual.latitude},${manual.longitude}`}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1 text-indigo-600 hover:underline dark:text-indigo-400"
                >
                  <MapPin className="h-3.5 w-3.5" /> Lihat di peta
                </a>
              ) : '-'}
            </dd>
            {manual.reviewed_at && (
              <>
                <dt className="text-slate-500">Direview</dt>
                <dd className="text-slate-900 dark:text-slate-100">
                  {manual.reviewed_by || '-'}
                  {manual.review_note && (
                    <span className="block text-slate-500">“{manual.review_note}”</span>
                  )}
                </dd>
              </>
            )}
          </dl>

          {reviewable && (
            <div className="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
              <label className="block text-sm font-medium text-slate-900 dark:text-slate-50" htmlFor="review-note">
                Catatan <span className="font-normal text-slate-500">(wajib bila ditolak)</span>
              </label>
              <textarea
                id="review-note"
                value={note}
                onChange={(e) => setNote(e.target.value)}
                maxLength={500}
                rows={2}
                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                placeholder="Mis. foto bukan karyawan ybs."
              />
              <div className="flex justify-end gap-2">
                <Button variant="outline" className="gap-1" disabled={!!busy} onClick={() => submit('reject')}>
                  {busy === 'reject' ? <Loader2 className="h-4 w-4 animate-spin" /> : <X className="h-4 w-4" />}
                  Tolak
                </Button>
                <Button className="gap-1" disabled={!!busy} onClick={() => submit('approve')}>
                  {busy === 'approve' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                  Setujui
                </Button>
              </div>
              <p className="text-xs text-slate-500">
                Disetujui = masuk antrean kirim ke Talenta (auto-send / Kirim Semua Pending).
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
