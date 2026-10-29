import React from 'react'
import { Link, useForm } from '@inertiajs/react'
import AuthShell from '@/components/AuthShell'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ArrowLeft, Loader2, Lock, LockKeyhole, Mail, Save } from 'lucide-react'

function Field({ id, label, icon: Icon, error, ...props }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      <div className="relative">
        <Icon className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input id={id} aria-invalid={!!error} className="h-11 pl-9 text-base sm:text-sm" {...props} />
      </div>
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

/** Tujuan link dari email lupa password: buat password baru. */
export default function ResetPassword({ token, email = '' }) {
  const { data, setData, post, processing, errors } = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  })

  const submit = (e) => {
    e.preventDefault()
    post('/reset-password', { preserveScroll: true })
  }

  return (
    <AuthShell
      title="Atur Ulang Password"
      icon={LockKeyhole}
      heading="Buat password baru"
      description="Setelah disimpan, semua sesi login lain di akun ini otomatis keluar."
      footer={
        <Link href="/forgot-password" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
          <ArrowLeft className="h-3.5 w-3.5" /> Minta link baru
        </Link>
      }
    >
      <form onSubmit={submit} className="space-y-5">
        {errors.email && (
          <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
            {errors.email}
          </div>
        )}
        <Field
          id="email"
          label="Email"
          icon={Mail}
          type="email"
          autoComplete="email"
          value={data.email}
          onChange={(e) => setData('email', e.target.value)}
          readOnly={!!email}
          className="h-11 pl-9 text-base read-only:bg-muted sm:text-sm"
        />
        <Field
          id="password"
          label="Password baru"
          icon={Lock}
          type="password"
          autoComplete="new-password"
          autoFocus
          value={data.password}
          onChange={(e) => setData('password', e.target.value)}
          error={errors.password}
        />
        <Field
          id="password_confirmation"
          label="Ulangi password baru"
          icon={Lock}
          type="password"
          autoComplete="new-password"
          value={data.password_confirmation}
          onChange={(e) => setData('password_confirmation', e.target.value)}
          error={errors.password_confirmation}
        />
        <p className="-mt-2 text-xs text-muted-foreground">Minimal 8 karakter, berisi huruf dan angka.</p>
        <Button type="submit" size="lg" disabled={processing} className="h-11 w-full gap-2">
          {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          Simpan password
        </Button>
      </form>
    </AuthShell>
  )
}
