import React from 'react'
import { Head } from '@inertiajs/react'
import ThemeToggle from '@/components/ThemeToggle'

/**
 * Kerangka halaman tamu sederhana (lupa / atur ulang password): satu kartu di
 * tengah dengan latar bergradasi tipis. Login & Register punya layout sendiri
 * (panel brand di desktop).
 */
export default function AuthShell({ title, icon: Icon, heading, description, children, footer }) {
  return (
    <div className="relative flex min-h-screen items-start justify-center overflow-hidden bg-background px-4 py-16 sm:items-center sm:px-8">
      <Head title={title} />
      <div
        className="pointer-events-none absolute -top-40 left-1/2 h-96 w-[48rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-indigo-500/20 to-violet-500/20 blur-3xl"
        aria-hidden="true"
      />
      <ThemeToggle className="absolute right-4 top-4 z-10 text-muted-foreground" />

      <div className="relative w-full max-w-md space-y-6">
        <div className="space-y-2 text-center sm:text-left">
          <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30 sm:mx-0">
            <Icon className="h-6 w-6" />
          </span>
          <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{heading}</h1>
          {description && <p className="text-sm text-muted-foreground sm:text-base">{description}</p>}
        </div>

        <div className="rounded-2xl border bg-card p-5 shadow-sm sm:p-7">{children}</div>

        {footer && <div className="text-center text-sm text-muted-foreground">{footer}</div>}
      </div>
    </div>
  )
}
