import React from 'react'
import { Head, useForm } from '@inertiajs/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { LogIn, Loader2, Lock } from 'lucide-react'

export default function Login() {
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
    <div className="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4">
      <Head title="Login" />
      <Card className="w-full max-w-sm">
        <CardHeader className="space-y-1">
          <div className="flex items-center gap-2">
            <div className="rounded-md bg-indigo-500/10 p-2">
              <Lock className="h-5 w-5 text-indigo-500" />
            </div>
            <CardTitle className="text-xl">ADMS Middleware</CardTitle>
          </div>
          <CardDescription>Masuk untuk mengakses dashboard.</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Email</label>
              <Input
                type="email"
                autoFocus
                value={data.email}
                onChange={(e) => setData('email', e.target.value)}
                placeholder="admin@adms.local"
              />
              {errors.email && <p className="text-xs text-red-600">{errors.email}</p>}
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Password</label>
              <Input
                type="password"
                value={data.password}
                onChange={(e) => setData('password', e.target.value)}
                placeholder="••••••••"
              />
              {errors.password && <p className="text-xs text-red-600">{errors.password}</p>}
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer select-none">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={data.remember}
                onChange={(e) => setData('remember', e.target.checked)}
              />
              Ingat saya
            </label>
            <Button type="submit" disabled={processing} className="w-full gap-2">
              {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogIn className="h-4 w-4" />}
              Masuk
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
