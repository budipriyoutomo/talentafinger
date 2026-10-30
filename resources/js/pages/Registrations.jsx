import React, { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { toast } from 'sonner'
import Layout from '../layouts/Layout'
import { confirmToast } from '@/lib/confirm'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Check, Loader2, UserPlus, X } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

const TABS = [
  { key: 'pending', label: 'Menunggu persetujuan' },
  { key: 'unverified', label: 'Belum verifikasi email' },
  { key: 'rejected', label: 'Ditolak' },
]

/**
 * Antrian pendaftaran akun karyawan (/register). Server hanya mengirim
 * pendaftar yang karyawannya ada di outlet dalam scope user ini.
 */
export default function Registrations({ status = 'pending', registrations = [], counts = {} }) {
  const [busyId, setBusyId] = useState(null)
  const [rejecting, setRejecting] = useState(null) // { id, reason }

  const call = async (id, action, body = {}) => {
    setBusyId(id)
    try {
      const res = await fetch(`/api/registrations/${id}/${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
      })
      const data = await res.json().catch(() => ({}))
      if (res.ok) {
        toast.success(data.message)
        setRejecting(null)
        router.reload({ only: ['registrations', 'counts'] })
      } else {
        toast.error(data.errors ? Object.values(data.errors).flat().join('\n') : data.message || 'Gagal memproses')
      }
    } finally {
      setBusyId(null)
    }
  }

  const approve = (r) =>
    confirmToast({
      message: `Setujui akun ${r.name}?`,
      description: 'Akun langsung aktif dan pemberitahuan dikirim ke email karyawan.',
      confirmLabel: 'Setujui',
      onConfirm: () => call(r.id, 'approve'),
    })

  const switchTab = (key) => router.get('/registrations', key === 'pending' ? {} : { status: key }, { preserveScroll: true })

  return (
    <Layout>
      <Head title="Pendaftaran Akun Karyawan" />

      <div className="space-y-6">
        <p className="text-sm text-muted-foreground">
          Karyawan yang mendaftar sendiri lewat halaman /register dengan NIK + email Talenta. Hanya karyawan di outlet
          yang ditugaskan ke Anda, atau yang memilih outlet Anda saat mendaftar, yang tampil di sini. Saat disetujui,
          karyawan otomatis ditautkan ke outlet yang dipilihnya (bertanda “outlet baru” bila belum tercatat).
        </p>

        <div className="flex flex-wrap gap-2">
          {TABS.map((t) => (
            <Button key={t.key} size="sm" variant={status === t.key ? 'default' : 'outline'} onClick={() => switchTab(t.key)} className="gap-2">
              {t.label}
              <Badge variant="secondary" className="text-[10px]">{counts[t.key] ?? 0}</Badge>
            </Button>
          ))}
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <UserPlus className="h-5 w-5 text-indigo-500" />
              {TABS.find((t) => t.key === status)?.label}
            </CardTitle>
            <CardDescription>
              {status === 'unverified' && 'Belum membuat password lewat link di email. Dibuang otomatis setelah 7 hari.'}
              {status === 'pending' && 'Email sudah terverifikasi; akun aktif setelah Anda setujui.'}
              {status === 'rejected' && 'Dibuang otomatis 30 hari setelah ditolak; baru setelah itu karyawan bisa mendaftar ulang.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            {registrations.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">Tidak ada pendaftaran.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="py-2 pr-3 font-medium">Karyawan</th>
                      <th className="py-2 pr-3 font-medium">NIK</th>
                      <th className="py-2 pr-3 font-medium">Outlet dipilih</th>
                      <th className="py-2 pr-3 font-medium">Daftar</th>
                      <th className="py-2 pr-3 font-medium">{status === 'rejected' ? 'Ditolak' : 'Verifikasi'}</th>
                      {status === 'pending' && <th className="py-2 font-medium" />}
                    </tr>
                  </thead>
                  <tbody>
                    {registrations.map((r) => (
                      <React.Fragment key={r.id}>
                        <tr className="border-b align-top last:border-0">
                          <td className="py-2 pr-3">
                            <div className="font-medium">
                              {r.name}
                              {r.is_new_employee && (
                                <Badge
                                  variant="outline"
                                  className="ml-2 border-amber-500/40 text-[10px] text-amber-600 dark:text-amber-400"
                                  title="NIK belum ada di data karyawan. Disetujui = karyawan baru dibuat dari isian pendaftar."
                                >
                                  karyawan baru
                                </Badge>
                              )}
                              {!r.employee_active && (
                                <Badge variant="destructive" className="ml-2 text-[10px]">karyawan nonaktif</Badge>
                              )}
                            </div>
                            <div className="text-xs text-muted-foreground">{r.email}</div>
                            {r.job_position && <div className="text-xs text-muted-foreground">{r.job_position}</div>}
                            {r.matched_employee && !r.approval_blocker && (
                              <div className="mt-1 text-xs text-indigo-600 dark:text-indigo-400">
                                NIK cocok dengan karyawan <b>{r.matched_employee}</b>: akan ditautkan saat disetujui.
                              </div>
                            )}
                            {r.approval_blocker && (
                              <div className="mt-1 text-xs text-red-600 dark:text-red-400">{r.approval_blocker}</div>
                            )}
                          </td>
                          <td className="py-2 pr-3 font-mono text-xs">{r.nik ?? '—'}</td>
                          <td className="py-2 pr-3 text-xs">
                            {r.claimed_outlet ? (
                              <>
                                <div className="font-medium">
                                  {r.claimed_outlet.name}
                                  {r.claimed_outlet.is_new && (
                                    <Badge variant="outline" className="ml-2 border-amber-500/40 text-[10px] text-amber-600 dark:text-amber-400">
                                      outlet baru
                                    </Badge>
                                  )}
                                </div>
                                <div className="text-muted-foreground">
                                  {[r.claimed_outlet.company, r.claimed_outlet.brand].filter(Boolean).join(' · ')}
                                </div>
                              </>
                            ) : '—'}
                            <div className="mt-1 text-muted-foreground">
                              Tercatat: {r.outlets.length ? r.outlets.join(', ') : '—'}
                            </div>
                          </td>
                          <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs">{r.registered_at ?? '—'}</td>
                          <td className="py-2 pr-3 text-xs">
                            {status === 'rejected' ? (
                              <>
                                <div className="whitespace-nowrap font-mono">{r.reviewed_at}</div>
                                <div className="text-muted-foreground">oleh {r.reviewer ?? '—'}</div>
                                {r.rejection_reason && <div className="mt-1">{r.rejection_reason}</div>}
                              </>
                            ) : (
                              <span className="whitespace-nowrap font-mono">{r.email_verified_at ?? '—'}</span>
                            )}
                          </td>
                          {status === 'pending' && (
                            <td className="py-2 text-right">
                              <div className="flex justify-end gap-2">
                                <Button size="sm" className="gap-1" disabled={busyId === r.id || !r.employee_active || !!r.approval_blocker} onClick={() => approve(r)}>
                                  {busyId === r.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                                  Setujui
                                </Button>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="gap-1"
                                  disabled={busyId === r.id}
                                  onClick={() => setRejecting(rejecting?.id === r.id ? null : { id: r.id, reason: '' })}
                                >
                                  <X className="h-4 w-4" /> Tolak
                                </Button>
                              </div>
                            </td>
                          )}
                        </tr>
                        {rejecting?.id === r.id && (
                          <tr className="border-b bg-muted/40">
                            <td colSpan={6} className="px-3 py-3">
                              <form
                                className="flex flex-col gap-2 sm:flex-row"
                                onSubmit={(e) => {
                                  e.preventDefault()
                                  call(r.id, 'reject', { reason: rejecting.reason })
                                }}
                              >
                                <Input
                                  autoFocus
                                  maxLength={255}
                                  value={rejecting.reason}
                                  onChange={(e) => setRejecting({ ...rejecting, reason: e.target.value })}
                                  placeholder="Alasan penolakan (opsional, dikirim ke email karyawan)"
                                />
                                <Button type="submit" size="sm" variant="destructive" disabled={busyId === r.id}>
                                  Tolak pendaftaran
                                </Button>
                              </form>
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
