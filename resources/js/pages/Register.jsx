import React, { useState } from 'react'
import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import ThemeToggle from '@/components/ThemeToggle'
import {
  UserPlus, Loader2, Mail, Lock, IdCard, ScanFace, ArrowLeft, MailCheck, ShieldCheck, LogIn, RotateCcw,
} from 'lucide-react'

// Langkah pendaftaran; dipakai panel brand (desktop) dan panel sukses.
// Langkah persetujuan hanya bila setting auth.registration_require_approval aktif.
const stepsFor = (requiresApproval) => [
  { icon: IdCard, title: 'Isi NIK & email', text: 'Gunakan NIK dan email yang tercatat di Talenta.' },
  { icon: MailCheck, title: 'Verifikasi email', text: 'Klik link yang kami kirim. Berlaku 60 menit.' },
  requiresApproval
    ? { icon: ShieldCheck, title: 'Tunggu persetujuan', text: 'Pengelola outlet menyetujui, lalu Anda bisa login.' }
    : { icon: LogIn, title: 'Login', text: 'Setelah verifikasi, langsung login dengan email & password Anda.' },
]

function Field({ id, label, icon: Icon, error, hint, className = '', ...props }) {
  return (
    <div className={`space-y-1.5 ${className}`}>
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      <div className="relative">
        <Icon className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
        {/* text-base di HP mencegah iOS memperbesar halaman saat input difokus. */}
        <Input id={id} aria-invalid={!!error} className="h-11 pl-9 text-base sm:text-sm" {...props} />
      </div>
      {error ? <p className="text-xs text-destructive">{error}</p> : hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

/**
 * Setelah kirim, server SELALU membalas pesan yang sama (flash.success) apa
 * pun hasil pencocokan NIK + email — panel ini sengaja tak bilang "berhasil
 * terdaftar", hanya "bila cocok, cek email".
 */
function SuccessPanel({ message, email, steps, onAgain }) {
  return (
    <div className="space-y-6">
      <div className="flex flex-col items-center text-center sm:items-start sm:text-left">
        <span className="relative mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/30 dark:text-emerald-400">
          <span className="absolute inset-0 animate-ping rounded-2xl bg-emerald-500/10" aria-hidden="true" />
          <MailCheck className="relative h-8 w-8" />
        </span>
        <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">Cek email Anda</h1>
        {email && (
          <p className="mt-2 text-sm text-muted-foreground">
            Dikirim ke <span className="break-all font-medium text-foreground">{email}</span>
          </p>
        )}
      </div>

      <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm leading-relaxed text-foreground sm:p-5">
        {message}
      </div>

      <ol className="space-y-3">
        {steps.slice(1).map(({ icon: Icon, title, text }, i) => (
          <li key={title} className="flex gap-3 rounded-xl border bg-card p-4">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
              <Icon className="h-4 w-4" />
            </span>
            <div>
              <p className="text-sm font-semibold">{i + 1}. {title}</p>
              <p className="text-sm text-muted-foreground">{text}</p>
            </div>
          </li>
        ))}
      </ol>

      <p className="text-xs text-muted-foreground">
        Tidak menerima email dalam beberapa menit? Periksa folder spam, atau login dengan email & password tadi untuk
        mengirim ulang link. Bila tetap tidak ada, pastikan NIK & email sesuai data di Talenta.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        <Button asChild size="lg" className="w-full gap-2">
          <Link href="/login"><LogIn className="h-4 w-4" /> Ke halaman login</Link>
        </Button>
        <Button type="button" size="lg" variant="outline" className="w-full gap-2" onClick={onAgain}>
          <RotateCcw className="h-4 w-4" /> Daftar dengan data lain
        </Button>
      </div>
    </div>
  )
}

export default function Register({ requiresApproval = true }) {
  const { flash } = usePage().props
  const STEPS = stepsFor(requiresApproval)
  const [submittedEmail, setSubmittedEmail] = useState('')
  const [showForm, setShowForm] = useState(false)
  const { data, setData, post, processing, errors, reset } = useForm({
    nik: '',
    email: '',
    password: '',
    password_confirmation: '',
  })

  const submit = (e) => {
    e.preventDefault()
    const email = data.email.trim()
    post('/register', {
      // Tanpa ini halaman di-remount setelah redirect dan state (email) hilang.
      preserveState: true,
      preserveScroll: true,
      onSuccess: () => {
        setSubmittedEmail(email)
        setShowForm(false)
        reset()
      },
    })
  }

  const succeeded = !!flash?.success && !showForm

  return (
    <div className="relative grid min-h-screen bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
      <Head title="Daftar Akun Karyawan" />
      <ThemeToggle className="absolute right-4 top-4 z-10 text-muted-foreground" />

      {/* Panel brand (desktop saja) */}
      <div className="relative hidden overflow-hidden bg-gradient-to-br from-indigo-600 via-indigo-700 to-violet-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div
          className="pointer-events-none absolute inset-0 opacity-20"
          style={{
            backgroundImage: 'radial-gradient(circle at 1px 1px, rgba(255,255,255,0.35) 1px, transparent 0)',
            backgroundSize: '24px 24px',
          }}
          aria-hidden="true"
        />
        <div className="pointer-events-none absolute -right-24 -bottom-24 h-96 w-96 rounded-full bg-violet-400/30 blur-3xl" aria-hidden="true" />

        <div className="relative flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25 backdrop-blur">
            <ScanFace className="h-5 w-5" />
          </span>
          <span className="text-lg font-semibold tracking-tight">ADMS Middleware</span>
        </div>

        <div className="relative max-w-md space-y-8">
          <div className="space-y-3">
            <h2 className="text-4xl font-bold leading-tight tracking-tight">Akun absensi untuk karyawan.</h2>
            <p className="text-indigo-100/90">
              Satu akun untuk absen foto dan absen QR di outlet langsung dari HP Anda.
            </p>
          </div>
          <ol className="space-y-5">
            {STEPS.map(({ icon: Icon, title, text }, i) => (
              <li key={title} className="flex items-start gap-4">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20">
                  <Icon className="h-5 w-5" />
                </span>
                <div className="pt-0.5">
                  <p className="font-semibold">{i + 1}. {title}</p>
                  <p className="text-sm text-indigo-100/80">{text}</p>
                </div>
              </li>
            ))}
          </ol>
        </div>

        <p className="relative text-xs text-indigo-200/80">© {new Date().getFullYear()} ADMS Talenta</p>
      </div>

      {/* Konten */}
      <div className="flex items-start justify-center px-4 py-16 sm:items-center sm:px-8 sm:py-12">
        <div className="w-full max-w-lg">
          {succeeded ? (
            <SuccessPanel message={flash.success} email={submittedEmail} steps={STEPS} onAgain={() => setShowForm(true)} />
          ) : (
            <div className="space-y-8">
              <div className="space-y-2">
                <span className="mb-2 flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30 lg:hidden">
                  <ScanFace className="h-6 w-6" />
                </span>
                <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">Daftar akun karyawan</h1>
                <p className="text-sm text-muted-foreground sm:text-base">
                  Gunakan NIK dan email yang tercatat di Talenta. Link verifikasi akan dikirim ke email tersebut.
                </p>
              </div>

              {/* Ringkasan langkah untuk HP / tablet (panel brand tersembunyi) */}
              <ol className="grid grid-cols-3 gap-2 lg:hidden">
                {STEPS.map(({ icon: Icon, title }, i) => (
                  <li key={title} className="flex flex-col items-center gap-1.5 rounded-xl border bg-card p-3 text-center">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                      <Icon className="h-4 w-4" />
                    </span>
                    <span className="text-[11px] font-medium leading-tight sm:text-xs">{i + 1}. {title}</span>
                  </li>
                ))}
              </ol>

              <form onSubmit={submit} className="space-y-5 rounded-2xl border bg-card p-5 shadow-sm sm:p-7">
                <div className="grid gap-5 sm:grid-cols-2">
                  <Field
                    id="nik"
                    label="NIK / ID Karyawan"
                    icon={IdCard}
                    autoFocus
                    autoComplete="off"
                    value={data.nik}
                    onChange={(e) => setData('nik', e.target.value)}
                    error={errors.nik}
                  />
                  <Field
                    id="email"
                    label="Email"
                    icon={Mail}
                    type="email"
                    inputMode="email"
                    autoComplete="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                  />
                </div>
                <p className="-mt-2 text-xs text-muted-foreground">Email yang terdaftar di Talenta, nanti dipakai untuk login.</p>
                <div className="grid gap-5 sm:grid-cols-2">
                  <Field
                    id="password"
                    label="Password"
                    icon={Lock}
                    type="password"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
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
                </div>
                <p className="-mt-2 text-xs text-muted-foreground">Minimal 8 karakter, berisi huruf dan angka.</p>
                <Button type="submit" size="lg" disabled={processing} className="h-11 w-full gap-2">
                  {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserPlus className="h-4 w-4" />}
                  Daftar
                </Button>
              </form>

              <p className="text-center text-sm text-muted-foreground">
                Sudah punya akun?{' '}
                <Link href="/login" className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
                  <ArrowLeft className="h-3.5 w-3.5" /> Masuk di sini
                </Link>
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
