import React from 'react'
import { Head, Link, router, useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import ThemeToggle from '@/components/ThemeToggle'
import { ArrowLeft, KeyRound, Loader2, LogOut, ShieldAlert } from 'lucide-react'

// Halaman berdiri sendiri (tanpa sidebar) karena dipakai juga oleh akun
// karyawan dan oleh user yang dipaksa ganti password sebelum boleh ke mana pun.
export default function ChangePassword({ forced = false, home = '/dashboard' }) {
  const { data, setData, put, processing, errors, reset } = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
  })

  const submit = (e) => {
    e.preventDefault()
    put('/password', { onError: () => reset('current_password', 'password', 'password_confirmation') })
  }

  const field = (id, label, autoComplete) => (
    <div className="space-y-1.5">
      <label htmlFor={id} className="text-sm font-medium">{label}</label>
      <Input
        id={id}
        type="password"
        autoComplete={autoComplete}
        value={data[id]}
        onChange={(e) => setData(id, e.target.value)}
        aria-invalid={!!errors[id]}
        className="h-10"
      />
      {errors[id] && <p className="text-xs text-destructive">{errors[id]}</p>}
    </div>
  )

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-background px-4 py-12">
      <Head title="Ganti Password" />
      <ThemeToggle className="absolute right-4 top-4 text-muted-foreground" />

      <div className="w-full max-w-sm space-y-6">
        <div className="space-y-2">
          <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
            <KeyRound className="h-5 w-5" />
          </span>
          <h1 className="text-2xl font-bold tracking-tight">Ganti Password</h1>
          <p className="text-sm text-muted-foreground">
            Minimal 8 karakter, berisi huruf dan angka.
          </p>
        </div>

        {forced && (
          <div className="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300">
            <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
            <span>Password Anda masih sementara. Ganti dulu sebelum melanjutkan.</span>
          </div>
        )}

        <form onSubmit={submit} className="space-y-4">
          {field('current_password', 'Password saat ini', 'current-password')}
          {field('password', 'Password baru', 'new-password')}
          {field('password_confirmation', 'Ulangi password baru', 'new-password')}

          <Button type="submit" size="lg" disabled={processing} className="w-full gap-2">
            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
            Simpan Password
          </Button>
        </form>

        <div className="flex justify-between text-sm">
          {forced ? (
            <span />
          ) : (
            <Link href={home} className="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground">
              <ArrowLeft className="h-4 w-4" /> Kembali
            </Link>
          )}
          <button
            type="button"
            onClick={() => router.post('/logout')}
            className="inline-flex items-center gap-1 text-muted-foreground hover:text-red-600"
          >
            <LogOut className="h-4 w-4" /> Keluar
          </button>
        </div>
      </div>
    </div>
  )
}
