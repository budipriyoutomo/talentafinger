import React, { useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Eye, EyeOff, Loader2, UserPlus, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

/**
 * Buat akun login role Karyawan dari data karyawan (POST /api/users/from-employees).
 * Email akun = email karyawan (hasil sync Talenta). Semua akun memakai password
 * awal yang sama dan wajib diganti saat login pertama. Karyawan yang tak memenuhi
 * syarat dilewati server beserta alasannya.
 */
export default function CreateEmployeeUsersDialog({ employees = [], onClose }) {
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState(null) // { created, skipped: [{name, reason}], message }

  const submit = async (e) => {
    e.preventDefault()
    setSubmitting(true)
    try {
      const res = await fetch('/api/users/from-employees', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ employee_ids: employees.map((emp) => emp.id), password }),
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok) {
        toast.error(body?.errors ? Object.values(body.errors).flat().join('\n') : body.message || 'Gagal membuat akun')
        return
      }
      setResult(body)
      if (body.created > 0) {
        toast.success(body.message)
        router.reload({ only: ['employees'] })
      } else {
        toast.warning(body.message)
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
      onMouseDown={(e) => { if (e.target === e.currentTarget && !submitting) onClose() }}
    >
      <div className="my-10 w-full max-w-xl rounded-2xl border bg-card text-card-foreground shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-border p-5">
          <div className="space-y-1">
            <h3 className="flex items-center gap-2 text-lg font-semibold text-foreground">
              <UserPlus className="h-5 w-5 text-indigo-500" /> Buat Akun User
            </h3>
            <p className="text-sm text-muted-foreground">
              {employees.length} karyawan akan dibuatkan akun role <b>Karyawan</b> (login pakai email karyawan)
              untuk absen foto / QR.
            </p>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose} disabled={submitting}><X className="h-4 w-4" /></Button>
        </div>

        {result ? (
          <div className="space-y-4 p-5">
            <p className="text-sm font-medium text-foreground">{result.message}</p>
            {result.skipped?.length > 0 && (
              <div className="space-y-1.5">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Dilewati</p>
                <ul className="max-h-60 divide-y divide-border overflow-y-auto rounded-md border text-sm">
                  {result.skipped.map((s, i) => (
                    <li key={i} className="flex justify-between gap-3 px-3 py-1.5">
                      <span className="truncate">{s.name}</span>
                      <span className="shrink-0 text-xs text-amber-600 dark:text-amber-400">{s.reason}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="flex justify-end border-t border-border pt-4">
              <Button onClick={onClose}>Tutup</Button>
            </div>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4 p-5">
            <ul className="max-h-60 divide-y divide-border overflow-y-auto rounded-md border text-sm">
              {employees.map((emp) => (
                <li key={emp.id} className="flex justify-between gap-3 px-3 py-1.5">
                  <span className="truncate font-medium">{emp.name}</span>
                  <span className="truncate text-xs text-muted-foreground">{emp.email}</span>
                </li>
              ))}
            </ul>

            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">Password awal</label>
              <div className="relative">
                <Input
                  type={showPassword ? 'text' : 'password'}
                  required
                  minLength={8}
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Minimal 8 karakter, huruf & angka"
                  className="pr-10"
                />
                <button
                  type="button"
                  className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}
                >
                  {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              <p className="text-xs text-muted-foreground">
                Sama untuk semua akun di atas dan bersifat sementara: karyawan wajib menggantinya saat login pertama.
                Sampaikan ke karyawan lewat jalur yang aman.
              </p>
            </div>

            <div className="flex justify-end gap-2 border-t border-border pt-4">
              <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button>
              <Button type="submit" className="gap-2" disabled={submitting || employees.length === 0}>
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
                Buat {employees.length} Akun
              </Button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
