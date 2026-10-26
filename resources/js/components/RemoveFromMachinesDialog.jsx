import React, { useEffect, useMemo, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Trash2, Loader2, X, AlertTriangle } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

/**
 * Hapus user + sidik jari karyawan (biasanya yang resign) dari mesin terpilih via
 * TCP 4370. Selalu manual. Backend membuat 1 job per mesin; tiap job dipolling di
 * /api/fingerprint/delete-jobs/{id}.
 */
export default function RemoveFromMachinesDialog({ employees = [], machines = [], onClose }) {
  const withPin = employees.filter((e) => e.biometric_id)
  const withoutPin = employees.filter((e) => !e.biometric_id)
  const withIp = machines.filter((m) => m.ip_address)

  // Default: mesin di outlet karyawan terpilih.
  const employeeOutletIds = useMemo(
    () => new Set(withPin.flatMap((e) => (e.outlets ?? []).map((o) => o.id))),
    [withPin]
  )
  const [targets, setTargets] = useState(() =>
    Object.fromEntries(withIp.filter((m) => employeeOutletIds.has(m.outlet_id)).map((m) => [m.id, true]))
  )
  const [submitting, setSubmitting] = useState(false)
  const [jobs, setJobs] = useState([]) // [{job_id, machine, status, progress_done, progress_total, summary, error}]

  const running = jobs.some((j) => j.status === 'queued' || j.status === 'processing')
  const started = jobs.length > 0

  useEffect(() => {
    if (!running) return
    let active = true
    const tick = async () => {
      const updates = await Promise.all(
        jobs.map(async (j) => {
          if (j.status === 'done' || j.status === 'failed') return j
          try {
            const res = await fetch(`/api/fingerprint/delete-jobs/${j.job_id}`, { headers: { Accept: 'application/json' } })
            const body = await res.json().catch(() => ({}))
            return body.ok ? { ...j, ...body } : j
          } catch {
            return j
          }
        })
      )
      if (!active) return
      setJobs(updates)
      if (!updates.some((j) => j.status === 'queued' || j.status === 'processing')) {
        router.reload({ only: ['employees'] })
      }
    }
    const id = setInterval(tick, 1500)
    return () => {
      active = false
      clearInterval(id)
    }
  }, [running, jobs])

  const submit = async () => {
    const ids = withIp.filter((m) => targets[m.id]).map((m) => m.id)
    if (ids.length === 0) return toast.error('Centang minimal satu mesin.')
    setSubmitting(true)
    try {
      const res = await fetch('/api/employees/remove-from-machines', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ employee_ids: withPin.map((e) => e.id), machine_ids: ids }),
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok || !body.ok) throw new Error(body.error || body.message || 'Gagal menghapus dari mesin.')
      setJobs(body.jobs.map((j) => ({ ...j, status: 'queued', progress_done: 0, progress_total: j.total })))
      if (body.skipped_machines?.length) toast.warning(`Mesin tanpa IP dilewati: ${body.skipped_machines.join(', ')}`)
    } catch (err) {
      toast.error(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm">
      <div className="mt-10 w-full max-w-2xl rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-center justify-between border-b border-border p-4">
          <div className="flex items-center gap-2">
            <Trash2 className="h-5 w-5 text-red-500" />
            <h3 className="font-semibold">Hapus dari Mesin — {withPin.length} karyawan</h3>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        <div className="space-y-4 p-4">
          <p className="flex items-start gap-2 rounded-md bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
            User beserta sidik jarinya dihapus PERMANEN dari mesin terpilih (tidak bisa dibatalkan). Data karyawan,
            template sidik jari di DB, dan log absensi tidak ikut terhapus.
          </p>

          <div className="flex max-h-32 flex-wrap gap-1.5 overflow-y-auto">
            {withPin.map((e) => (
              <span key={e.id} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs dark:bg-slate-800">
                {e.name}
                <span className="font-mono text-muted-foreground">· PIN {e.biometric_id}</span>
              </span>
            ))}
          </div>
          {withoutPin.length > 0 && (
            <p className="text-xs text-muted-foreground">
              {withoutPin.length} karyawan dilewati karena belum punya Biometric ID (tak pernah terdaftar di mesin).
            </p>
          )}

          <div className="space-y-2">
            <p className="text-sm font-medium">Mesin</p>
            {withIp.length === 0 ? (
              <p className="text-sm text-amber-600">Belum ada mesin ber-IP.</p>
            ) : (
              <div className="max-h-64 divide-y overflow-y-auto rounded-md border dark:divide-slate-800 dark:border-slate-800">
                {withIp.map((m) => (
                  <label key={m.id} className="flex cursor-pointer items-center gap-3 p-2.5">
                    <input
                      type="checkbox"
                      className="h-4 w-4"
                      checked={!!targets[m.id]}
                      disabled={started}
                      onChange={() => setTargets((p) => ({ ...p, [m.id]: !p[m.id] }))}
                    />
                    <div className="min-w-0 flex-1">
                      <div className="text-sm font-medium">{m.name}</div>
                      <div className="font-mono text-xs text-muted-foreground">{m.ip_address}:{m.sdk_port || 4370}</div>
                    </div>
                    {employeeOutletIds.has(m.outlet_id) && <span className="text-xs text-muted-foreground">outlet karyawan</span>}
                  </label>
                ))}
              </div>
            )}
          </div>

          <div className="flex gap-2">
            {!started && (
              <Button variant="destructive" onClick={submit} disabled={submitting || withPin.length === 0} className="gap-2">
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                Hapus {withPin.length} karyawan dari mesin
              </Button>
            )}
            <Button variant="outline" onClick={onClose}>Tutup</Button>
          </div>
          {running && <p className="text-xs text-muted-foreground">Berjalan di background — boleh tutup, proses tetap lanjut.</p>}

          {started && (
            <div className="space-y-2 border-t border-border pt-3">
              {jobs.map((j) => {
                const pct = j.progress_total ? Math.round((j.progress_done / j.progress_total) * 100) : 0
                return (
                  <div key={j.job_id} className="rounded-md border p-2.5 dark:border-slate-800">
                    <div className="flex items-center justify-between text-sm">
                      <span className="font-medium">{j.machine}</span>
                      <span className="text-xs text-muted-foreground">
                        {j.status === 'failed'
                          ? <span className="text-red-600">gagal: {j.error || '-'}</span>
                          : j.summary
                            ? <>berhasil {j.summary.ok}/{j.summary.pins}{j.summary.failed > 0 && <span className="text-red-600"> · {j.summary.failed} gagal</span>}</>
                            : `${j.status === 'queued' ? 'antre' : 'memproses'} ${j.progress_done}/${j.progress_total}`}
                      </span>
                    </div>
                    {!j.summary && j.status !== 'failed' && (
                      <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                        <div className="h-full rounded-full bg-red-500 transition-all" style={{ width: `${pct}%` }} />
                      </div>
                    )}
                    {(j.items || []).filter((i) => !i.ok).map((i) => (
                      <div key={i.pin} className="mt-1 text-xs text-red-600">PIN {i.pin}: {i.error || 'tidak terhapus'}</div>
                    ))}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
