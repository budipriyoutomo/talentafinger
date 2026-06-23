import React, { useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Fingerprint, Download, Send, Loader2, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

/**
 * Kelola sidik jari satu karyawan berbasis DB (Opsi A):
 *  - TARIK : ambil template dari mesin sumber -> simpan ke DB.
 *  - SEBAR : dorong template dari DB ke mesin tujuan terpilih (TCP 4370).
 * PIN di tiap mesin diambil backend dari mapping karyawan.
 */
export default function EmployeeFingerprintDialog({ employee, machines = [], onClose }) {
  const withIp = machines.filter((m) => m.ip_address)
  const [sourceId, setSourceId] = useState(withIp[0]?.id || '')
  const [capturing, setCapturing] = useState(false)
  const [captureMsg, setCaptureMsg] = useState(null)

  const [targets, setTargets] = useState({})
  const [distributing, setDistributing] = useState(false)
  const [results, setResults] = useState(null)

  const count = employee.fingerprints_count ?? 0

  const toggleTarget = (id) => setTargets((p) => ({ ...p, [id]: !p[id] }))

  const refresh = () => router.reload({ only: ['employees'] })

  const capture = async () => {
    if (!sourceId) return
    setCapturing(true)
    setCaptureMsg(null)
    try {
      const res = await fetch(`/api/employees/${employee.id}/fingerprints/capture`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ source_machine_id: sourceId }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.ok) {
        setCaptureMsg({ ok: true, text: `Berhasil menarik ${body.fingers} jari (PIN ${body.pin}) dari ${body.source} ke DB.` })
        refresh()
      } else {
        setCaptureMsg({ ok: false, text: body.error || 'Gagal menarik template.' })
      }
    } catch (err) {
      setCaptureMsg({ ok: false, text: 'Gagal terhubung: ' + err.message })
    } finally {
      setCapturing(false)
    }
  }

  const distribute = async () => {
    const ids = withIp.filter((m) => targets[m.id]).map((m) => m.id)
    if (ids.length === 0) return toast.error('Centang minimal satu mesin tujuan.')
    setDistributing(true)
    setResults(null)
    try {
      const res = await fetch(`/api/employees/${employee.id}/fingerprints/distribute`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ target_machine_ids: ids }),
      })
      const body = await res.json().catch(() => ({}))
      if (body.results) {
        setResults(body)
      } else {
        toast.error(body.error || 'Sebar gagal.')
      }
    } catch (err) {
      toast.error('Sebar gagal: ' + err.message)
    } finally {
      setDistributing(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
      <div className="mt-10 w-full max-w-lg rounded-lg bg-white dark:bg-slate-900 shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 p-4">
          <div className="flex items-center gap-2">
            <Fingerprint className="h-5 w-5 text-indigo-500" />
            <div>
              <h3 className="font-semibold text-slate-900 dark:text-slate-50">Sidik Jari — {employee.name}</h3>
              <p className="text-xs text-slate-500 flex items-center gap-1.5 flex-wrap">
                Tersimpan di DB: <Badge variant="secondary">{count} jari</Badge>
                <Badge variant="secondary">
                  {Number(employee.device_privilege) !== 0 ? 'Admin' : 'User biasa'}
                </Badge>
                {employee.biometric_id && (
                  <Badge variant="secondary">Biometric ID: {employee.biometric_id}</Badge>
                )}
              </p>
            </div>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        <div className="space-y-6 p-4">
          {withIp.length === 0 && (
            <p className="text-sm text-amber-600">Belum ada mesin ber-IP. Isi IP mesin dulu di halaman Machines.</p>
          )}

          {/* TARIK ke DB */}
          <div className="space-y-2">
            <h4 className="text-sm font-medium flex items-center gap-2">
              <Download className="h-4 w-4 text-emerald-500" /> Tarik ke DB
            </h4>
            <p className="text-xs text-slate-500">
              Ambil sidik jari karyawan ini dari mesin berdasarkan <b>Biometric ID</b>
              {employee.biometric_id ? ` (${employee.biometric_id})` : ' (belum diisi → pakai mapping)'}. Menimpa template lama di DB.
            </p>
            <div className="flex items-end gap-2">
              <Select value={sourceId} onChange={(e) => setSourceId(e.target.value)} className="w-56">
                {withIp.length === 0 && <option value="">(tidak ada mesin ber-IP)</option>}
                {withIp.map((m) => (
                  <option key={m.id} value={m.id}>{m.name} ({m.ip_address})</option>
                ))}
              </Select>
              <Button onClick={capture} disabled={!sourceId || capturing} className="gap-2">
                {capturing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                {capturing ? 'Menarik...' : 'Tarik'}
              </Button>
            </div>
            {captureMsg && (
              <p className={`text-sm ${captureMsg.ok ? 'text-green-600' : 'text-red-600'}`}>{captureMsg.text}</p>
            )}
          </div>

          {/* SEBAR dari DB */}
          <div className="space-y-2 border-t border-slate-200 dark:border-slate-800 pt-4">
            <h4 className="text-sm font-medium flex items-center gap-2">
              <Send className="h-4 w-4 text-indigo-500" /> Sebar dari DB ke Mesin
            </h4>
            {count === 0 ? (
              <p className="text-xs text-amber-600">Belum ada template di DB. Tarik dulu dari mesin.</p>
            ) : (
              <>
                <p className="text-xs text-slate-500">Centang mesin tujuan. PIN diambil dari mapping karyawan di tiap mesin.</p>
                <div className="rounded-md border divide-y dark:divide-slate-800 dark:border-slate-800">
                  {withIp.map((m) => (
                    <label key={m.id} className="flex items-center gap-3 p-2.5 cursor-pointer">
                      <input type="checkbox" className="h-4 w-4" checked={!!targets[m.id]} onChange={() => toggleTarget(m.id)} />
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium text-slate-900 dark:text-slate-50">{m.name}</div>
                        <div className="text-xs text-slate-500 font-mono">{m.ip_address}:{m.sdk_port || 4370}</div>
                      </div>
                    </label>
                  ))}
                </div>
                <Button onClick={distribute} disabled={distributing} className="gap-2">
                  {distributing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  {distributing ? 'Menyebar...' : 'Sebar'}
                </Button>
              </>
            )}

            {results && (
              <div className="mt-2 space-y-1">
                {results.results.map((r, i) => (
                  <div key={i} className="text-xs flex items-center gap-2">
                    <span className={r.ok ? 'text-green-600' : 'text-red-600'}>{r.ok ? '✓' : '✗'}</span>
                    <span className="font-medium">{r.machine}</span>
                    <span className="text-slate-500">
                      {r.ok ? `${r.installed} jari terpasang (PIN ${r.pin})` : (r.error || 'gagal')}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
