import React, { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Loader2, X } from 'lucide-react'

/**
 * Minta SATU alasan untuk menolak banyak absen sekaligus (tab "Perlu Review").
 * Alasan wajib, sama seperti tolak satu per satu di ManualReviewDialog.
 */
export default function BulkRejectDialog({ count, busy, onCancel, onSubmit }) {
  const [note, setNote] = useState('')

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && !busy && onCancel()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busy, onCancel])

  const submit = () => {
    if (!note.trim()) {
      toast.error('Isi alasan penolakan dulu.')
      return
    }
    onSubmit(note.trim())
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      onClick={(e) => e.target === e.currentTarget && !busy && onCancel()}
    >
      <div className="mt-24 w-full max-w-md rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 p-4 dark:border-slate-800">
          <h3 className="font-semibold text-foreground">Tolak {count} absen terpilih</h3>
          <Button variant="ghost" size="sm" onClick={onCancel} aria-label="Tutup" disabled={busy}>
            <X className="h-4 w-4" />
          </Button>
        </div>
        <div className="space-y-3 p-4">
          <label className="block text-sm font-medium text-foreground" htmlFor="bulk-reject-note">
            Alasan penolakan <span className="font-normal text-muted-foreground">(dipakai untuk semua absen terpilih)</span>
          </label>
          <textarea
            id="bulk-reject-note"
            autoFocus
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={500}
            rows={3}
            className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            placeholder="Mis. foto bukan karyawan ybs."
          />
          <div className="flex justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={onCancel}>Batal</Button>
            <Button className="gap-1" disabled={busy} onClick={submit}>
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <X className="h-4 w-4" />}
              Tolak {count} Absen
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
