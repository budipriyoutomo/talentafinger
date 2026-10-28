import React from 'react'
import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import ThemeToggle from '@/components/ThemeToggle'
import { LogIn, Loader2, Mail, Lock, ScanFace, Cpu, Send, ShieldCheck } from 'lucide-react'

const FEATURES = [
  { icon: Cpu, text: 'Terima data absensi langsung dari mesin ZKTeco via ADMS' },
  { icon: Send, text: 'Kirim otomatis ke Mekari Talenta, terjadwal atau manual' },
  { icon: ShieldCheck, text: 'Akses berbasis role untuk admin, HR, dan karyawan' },
]

export default function Login({ registrationEnabled = false }) {
  // Mis. "Akun Anda dinonaktifkan" setelah sesi diputus EnsureAccountActive,
  // atau hasil klik link verifikasi pendaftaran.
  const { flash } = usePage().props
  const { data, setData, post, processing, errors } = useForm({
    email: '',
    password: '',
    remember: false,
  })

  const submit = (e) => {
    e.preventDefault()
    post('/login')
  }

  return (
    <div className="relative grid min-h-screen bg-background lg:grid-cols-2">
      <Head title="Login" />
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
            <h2 className="text-4xl font-bold leading-tight tracking-tight">
              Absensi mesin, tersinkron ke Talenta.
            </h2>
            <p className="text-indigo-100/90">
              Satu tempat untuk memantau mesin sidik jari, log kehadiran, dan pengiriman data karyawan.
            </p>
          </div>
          <ul className="space-y-4">
            {FEATURES.map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-start gap-3 text-sm text-indigo-50">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/20">
                  <Icon className="h-4 w-4" />
                </span>
                <span className="pt-1.5">{text}</span>
              </li>
            ))}
          </ul>
        </div>

        <p className="relative text-xs text-indigo-200/80">© {new Date().getFullYear()} ADMS Talenta</p>
      </div>

      {/* Form */}
      <div className="flex items-center justify-center px-4 py-12 sm:px-8">
        <div className="w-full max-w-sm space-y-8">
          <div className="space-y-2">
            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30 lg:hidden">
              <ScanFace className="h-5 w-5" />
            </span>
            <h1 className="text-2xl font-bold tracking-tight">Selamat datang kembali</h1>
            <p className="text-sm text-muted-foreground">Masuk untuk mengakses dashboard.</p>
          </div>

          {flash?.error && (
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
              {flash.error}
            </div>
          )}
          {flash?.success && (
            <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-700 dark:text-emerald-400">
              {flash.success}
            </div>
          )}

          <form onSubmit={submit} className="space-y-5">
            <div className="space-y-1.5">
              <label htmlFor="email" className="text-sm font-medium">Email</label>
              <div className="relative">
                <Mail className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="email"
                  type="email"
                  autoFocus
                  autoComplete="email"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                  placeholder="admin@adms.local"
                  aria-invalid={!!errors.email}
                  className="h-10 pl-9"
                />
              </div>
              {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
            </div>
            <div className="space-y-1.5">
              <label htmlFor="password" className="text-sm font-medium">Password</label>
              <div className="relative">
                <Lock className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  value={data.password}
                  onChange={(e) => setData('password', e.target.value)}
                  placeholder="••••••••"
                  aria-invalid={!!errors.password}
                  className="h-10 pl-9"
                />
              </div>
              {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
            </div>
            <label className="flex cursor-pointer items-center gap-2 text-sm text-muted-foreground select-none">
              <input
                type="checkbox"
                className="h-4 w-4 cursor-pointer rounded border-input accent-primary"
                checked={data.remember}
                onChange={(e) => setData('remember', e.target.checked)}
              />
              Ingat saya
            </label>
            <Button type="submit" size="lg" disabled={processing} className="w-full gap-2">
              {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogIn className="h-4 w-4" />}
              Masuk
            </Button>
          </form>

          {registrationEnabled && (
            <p className="text-center text-sm text-muted-foreground">
              Karyawan belum punya akun?{' '}
              <Link href="/register" className="font-medium text-primary hover:underline">
                Daftar di sini
              </Link>
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
