import React, { useCallback, useEffect, useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { confirmToast } from '@/lib/confirm'
import { Button } from '@/components/ui/button'
import ThemeToggle from '@/components/ThemeToggle'
import { KeyRound, LogOut, Menu } from 'lucide-react'
import Sidebar from './Sidebar'
import { useNavigation } from '@/lib/navigation'

const COLLAPSED_KEY = 'adms.sidebar.collapsed'

export default function Layout({ children }) {
  const { props } = usePage()
  const user = props?.auth?.user

  const { active } = useNavigation()
  const pageTitle = active ? (active.title ?? active.label) : ''

  // Dibaca saat inisialisasi state (bukan di useEffect) supaya sidebar tidak
  // sempat berkedip lebar dulu sebelum menyusut.
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.localStorage.getItem(COLLAPSED_KEY) === '1'
  })
  const [mobileOpen, setMobileOpen] = useState(false)

  useEffect(() => {
    window.localStorage.setItem(COLLAPSED_KEY, collapsed ? '1' : '0')
  }, [collapsed])

  const closeMobile = useCallback(() => setMobileOpen(false), [])

  const initials = (user?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('')

  const logout = () => {
    confirmToast({
      message: 'Keluar dari aplikasi?',
      confirmLabel: 'Keluar',
      onConfirm: () => router.post('/logout'),
    })
  }

  return (
    <div className="flex min-h-screen bg-background">
      <Sidebar
        collapsed={collapsed}
        onToggleCollapse={() => setCollapsed((v) => !v)}
        mobileOpen={mobileOpen}
        onCloseMobile={closeMobile}
      />

      {/* min-w-0: tanpa ini kolom konten menolak menyusut dan tabel lebar
          (Logs/Fingerprints/Machines) memaksa scroll horizontal di body. */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border bg-card/80 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-card/70 sm:px-6">
          <button
            type="button"
            onClick={() => setMobileOpen(true)}
            aria-label="Buka menu"
            aria-expanded={mobileOpen}
            className="-ml-2 rounded-lg p-2 text-muted-foreground hover:bg-accent hover:text-accent-foreground md:hidden"
          >
            <Menu className="h-5 w-5" />
          </button>

          <h1 className="truncate text-lg font-semibold tracking-tight">{pageTitle}</h1>

          <div className="ml-auto flex items-center gap-1 sm:gap-2">
            <ThemeToggle className="text-muted-foreground" />

            {user && (
              <>
                <div className="mx-1 hidden h-6 w-px bg-border sm:block" aria-hidden="true" />
                <div className="flex items-center gap-2.5" title={user.email}>
                  <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-semibold text-white">
                    {initials || '?'}
                  </span>
                  <span className="hidden leading-tight md:block">
                    <span className="block text-sm font-medium">{user.name}</span>
                    <span className="block max-w-[180px] truncate text-xs text-muted-foreground">{user.email}</span>
                  </span>
                </div>
                <Button asChild variant="ghost" size="sm" className="text-muted-foreground" title="Ganti password">
                  <Link href="/password" aria-label="Ganti password">
                    <KeyRound className="h-4 w-4" />
                  </Link>
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={logout}
                  className="flex items-center gap-2 text-muted-foreground hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                  title="Logout"
                >
                  <LogOut className="h-4 w-4" />
                  <span className="hidden sm:inline">Keluar</span>
                </Button>
              </>
            )}
          </div>
        </header>

        <main className="animate-in-page mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {children}
        </main>
      </div>
    </div>
  )
}
