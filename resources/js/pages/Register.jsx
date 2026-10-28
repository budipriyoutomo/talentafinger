import React from 'react'
import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import ThemeToggle from '@/components/ThemeToggle'
import { UserPlus, Loader2, Mail, Lock, IdCard, ScanFace, ArrowLeft } from 'lucide-react'

/**
 * Registrasi mandiri akun karyawan. Server selalu membalas pesan yang sama
 * (flash.success) apa pun hasil pencocokan NIK + email, jadi halaman ini tak
 * pernah menampilkan "NIK tidak ditemukan" dsb.
 */
function Field({ id, label, icon: Icon, error, hint, ...props }) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      <div className="relative">
        <Icon className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        <Input id={id} aria-invalid={!!error} className="h-10 pl-9" {...props} />
      </div>
      {error ? <p className="text-xs text-destructive">{error}</p> : hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

export default function Register() {
  const { flash } = usePage().props
  const { data, setData, post, processing, errors, reset } = useForm({
    nik: '',
    email: '',
    password: '',
    password_confirmation: '',
  })

  const submit = (e) => {
    e.preventDefault()
    post('/register', { onSuccess: () => reset() })
  }

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-background px-4 py-12">
      <Head title="Daftar Akun Karyawan" />
      <ThemeToggle className="absolute right-4 top-4 z-10 text-muted-foreground" />

      <div className="w-full max-w-sm space-y-8">
        <div className="space-y-2">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
            <ScanFace className="h-5 w-5" />
          </span>
          <h1 className="text-2xl font-bold tracking-tight">Daftar akun karyawan</h1>
          <p className="text-sm text-muted-foreground">
            Gunakan NIK dan email yang tercatat di Talenta. Link verifikasi akan dikirim ke email tersebut.
          </p>
        </div>

        {flash?.success && (
          <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-400">
            {flash.success}
          </div>
        )}

        <form onSubmit={submit} className="space-y-5">
          <Field
            id="nik"
            label="NIK / ID Karyawan"
            icon={IdCard}
            autoFocus
            value={data.nik}
            onChange={(e) => setData('nik', e.target.value)}
            error={errors.nik}
          />
          <Field
            id="email"
            label="Email"
            icon={Mail}
            type="email"
            autoComplete="email"
            value={data.email}
            onChange={(e) => setData('email', e.target.value)}
            error={errors.email}
            hint="Email yang terdaftar di Talenta. Dipakai untuk login."
          />
          <Field
            id="password"
            label="Password"
            icon={Lock}
            type="password"
            autoComplete="new-password"
            value={data.password}
            onChange={(e) => setData('password', e.target.value)}
            error={errors.password}
            hint="Minimal 8 karakter, berisi huruf dan angka."
          />
          <Field
            id="password_confirmation"
            label="Ulangi Password"
            icon={Lock}
            type="password"
            autoComplete="new-password"
            value={data.password_confirmation}
            onChange={(e) => setData('password_confirmation', e.target.value)}
            error={errors.password_confirmation}
          />
          <Button type="submit" size="lg" disabled={processing} className="w-full gap-2">
            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
            Daftar
          </Button>
        </form>

        <p className="text-center text-sm text-muted-foreground">
          <Link href="/login" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
            <ArrowLeft className="h-3.5 w-3.5" /> Kembali ke login
          </Link>
        </p>
      </div>
    </div>
  )
}
