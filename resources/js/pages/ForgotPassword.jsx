import React, { useState } from 'react'
import { Link, useForm, usePage } from '@inertiajs/react'
import AuthShell from '@/components/AuthShell'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ArrowLeft, KeyRound, Loader2, Mail, MailCheck, RotateCcw, Send } from 'lucide-react'

/**
 * Minta link atur ulang password. Server selalu membalas pesan yang sama
 * (flash.success), jadi panel sukses tak pernah bilang emailnya terdaftar.
 */
export default function ForgotPassword({ expiresInMinutes = 60 }) {
  const { flash } = usePage().props
  const [sentTo, setSentTo] = useState('')
  const [showForm, setShowForm] = useState(false)
  const { data, setData, post, processing, errors } = useForm({ email: '' })

  const submit = (e) => {
    e.preventDefault()
    const email = data.email.trim()
    post('/forgot-password', {
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        setSentTo(email)
        setShowForm(false)
      },
    })
  }

  const back = (
    <Link href="/login" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
      <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke login
    </Link>
  )

  if (flash?.success && !showForm) {
    return (
      <AuthShell title="Lupa Password" icon={MailCheck} heading="Cek email Anda" footer={back}>
        <div className="space-y-5">
          {sentTo && (
            <p className="text-sm text-muted-foreground">
              Permintaan untuk <span className="break-all font-medium text-foreground">{sentTo}</span>
            </p>
          )}
          <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm leading-relaxed">
            {flash.success}
          </div>
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li>• Link berlaku {expiresInMinutes} menit dan hanya bisa dipakai sekali.</li>
            <li>• Permintaan baru bisa dikirim setelah 1 menit.</li>
            <li>• Akun yang belum selesai mendaftar atau dinonaktifkan tidak menerima link — hubungi admin.</li>
          </ul>
          <Button type="button" variant="outline" size="lg" className="w-full gap-2" onClick={() => setShowForm(true)}>
            <RotateCcw className="h-4 w-4" /> Kirim ulang / pakai email lain
          </Button>
        </div>
      </AuthShell>
    )
  }

  return (
    <AuthShell
      title="Lupa Password"
      icon={KeyRound}
      heading="Lupa password?"
      description="Masukkan email akun Anda. Kami kirimkan link untuk membuat password baru."
      footer={back}
    >
      <form onSubmit={submit} className="space-y-5">
        <div className="space-y-1.5">
          <label htmlFor="email" className="text-sm font-medium">Email</label>
          <div className="relative">
            <Mail className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id="email"
              type="email"
              inputMode="email"
              autoComplete="email"
              autoFocus
              value={data.email}
              onChange={(e) => setData('email', e.target.value)}
              aria-invalid={!!errors.email}
              className="h-11 pl-9 text-base sm:text-sm"
            />
          </div>
          {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
        </div>
        <Button type="submit" size="lg" disabled={processing} className="h-11 w-full gap-2">
          {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
          Kirim link
        </Button>
      </form>
    </AuthShell>
  )
}
