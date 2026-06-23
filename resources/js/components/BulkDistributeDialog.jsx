import React, { useState, useEffect } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Send, Loader2, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

/**
 * Sebar massal sidik jari DARI DB untuk banyak karyawan -> banyak mesin.
 * Memanggil /fingerprint/distribute-bulk (background) lalu polling status.
 */
export default function BulkDistributeDialog({ employees = [], machines = [], onClose }) {
  const withIp = machines.filter((m) => m.ip_address)
  const [targets, setTargets] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [jobId, setJobId] = useState(null)
  const [job, setJob] = useState(null)

  const running = job && (job.status === 'queued' || job.status === 'processing')

  const toggleTarget = (id) => setTargets((p) => ({ ...p, [id]: !p[id] }))

  // Polling status job selama belum selesai.
  useEffect(() => {
    if (!jobId) return
    let active = true
    const tick = async () => {
      try {
        const res = await fetch(`/api/fingerprint/distribute-jobs/${jobId}`, {
          headers: { Accept: 'application/json' },
        })
        const body = await res.json().catch(() => ({}))
        if (!active) return
        if (body.ok) {
          setJob(body)
          if (body.status === 'done' || body.status === 'failed') {
            setJobId(null)
            router.reload({ only: ['employees'] })
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
  }, [jobId])

  const submit = async () => {
    const ids = withIp.filter((m) => targets[m.id]).map((m) => m.id)
    if (ids.length === 0) return toast.error('Centang minimal satu mesin tujuan.')
    setSubmitting(true)
    setJob(null)
    try {
      const res = await fetch('/api/fingerprint/distribute-bulk', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({
          employee_ids: employees.map((e) => e.id),
          target_machine_ids: ids,
        }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        setJob({ status: 'queued', progress_total: body.total, progress_done: 0, items: [] })
        setJobId(body.job_id)
      } else {
        toast.error(body.error || 'Sebar massal gagal.')
      }
    } catch (err) {
      toast.error('Sebar massal gagal: ' + err.message)
    } finally {
      setSubmitting(false)
    }
  }

  const pct = job?.progress_total
    ? Math.round((job.progress_done / job.progress_total) * 100)
    : 0

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
      <div className="mt-10 w-full max-w-2xl rounded-lg bg-white dark:bg-slate-900 shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 p-4">
          <div className="flex items-center gap-2">
            <Send className="h-5 w-5 text-indigo-500" />
            <h3 className="font-semibold text-slate-900 dark:text-slate-50">
              Sebar Massal — {employees.length} karyawan
            </h3>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        <div className="space-y-4 p-4">
          {/* Daftar karyawan terpilih */}
          <div className="flex flex-wrap gap-1.5">
            {employees.map((e) => (
              <span key={e.id} className="inline-flex items-center gap-1 rounded-full bg-slate-100 dark:bg-slate-800 px-2.5 py-0.5 text-xs">
                {e.name}
                <span className="text-slate-500">· {e.fingerprints_count ?? 0} jari</span>
              </span>
            ))}
          </div>

          {/* Mesin tujuan */}
          <div className="space-y-2">
            <p className="text-sm font-medium">Mesin tujuan</p>
            {withIp.length === 0 ? (
              <p className="text-sm text-amber-600">Belum ada mesin ber-IP.</p>
            ) : (
              <div className="rounded-md border divide-y dark:divide-slate-800 dark:border-slate-800">
                {withIp.map((m) => (
                  <label key={m.id} className="flex items-center gap-3 p-2.5 cursor-pointer">
                    <input type="checkbox" className="h-4 w-4" checked={!!targets[m.id]} onChange={() => toggleTarget(m.id)} disabled={running} />
                    <div className="flex-1 min-w-0">
                      <div className="text-sm font-medium text-slate-900 dark:text-slate-50">{m.name}</div>
                      <div className="text-xs text-slate-500 font-mono">{m.ip_address}:{m.sdk_port || 4370}</div>
                    </div>
                  </label>
                ))}
              </div>
            )}
          </div>

          <div className="flex gap-2">
            <Button onClick={submit} disabled={submitting || running} className="gap-2">
              {submitting || running ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
              {running ? 'Diproses di background...' : `Sebar ${employees.length} Karyawan`}
            </Button>
            <Button variant="outline" onClick={onClose}>Tutup</Button>
          </div>
          {running && (
            <p className="text-xs text-slate-500">Berjalan di background — boleh tutup, proses tetap lanjut.</p>
          )}

          {/* Progres & hasil */}
          {job && (
            <div className="space-y-3 border-t border-slate-200 dark:border-slate-800 pt-3">
              {job.status === 'failed' ? (
                <p className="text-sm font-medium text-red-600">Job gagal: {job.error || 'tidak diketahui'}</p>
              ) : job.summary ? (
                <p className="text-sm font-medium">
                  Selesai. Berhasil {job.summary.ok}/{job.summary.employees} karyawan
                  {job.summary.failed > 0 && <span className="text-red-600"> · {job.summary.failed} gagal</span>}
                </p>
              ) : (
                <div className="space-y-1">
                  <p className="text-sm font-medium">
                    {job.status === 'queued' ? 'Menunggu antrean...' : 'Memproses...'} {job.progress_done}/{job.progress_total}
                  </p>
                  <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                    <div className="h-full rounded-full bg-indigo-500 transition-all" style={{ width: `${pct}%` }} />
                  </div>
                </div>
              )}

              <div className="space-y-2 max-h-64 overflow-y-auto">
                {(job.items || []).map((item, i) => (
                  <div key={i} className="rounded-md border dark:border-slate-800 p-2.5">
                    <div className="text-sm font-medium flex items-center gap-2">
                      <span className={item.ok ? 'text-green-600' : 'text-red-600'}>{item.ok ? '✓' : '✗'}</span>
                      {item.name || item.employee_id}
                      {item.error && <span className="text-red-600 text-xs">— {item.error}</span>}
                    </div>
                    {(item.results || []).length > 0 && (
                      <div className="mt-1 space-y-0.5 pl-5">
                        {item.results.map((r, j) => (
                          <div key={j} className="text-xs flex items-center gap-2">
                            <span className={r.ok ? 'text-green-600' : 'text-red-600'}>{r.ok ? '✓' : '✗'}</span>
                            <span className="font-medium">{r.machine}</span>
                            <span className="text-slate-500">
                              {r.ok ? `${r.installed} jari (PIN ${r.pin})` : (r.error || 'gagal')}
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
        </div>
      </div>
    </div>
  )
}
