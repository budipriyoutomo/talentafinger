import React, { useCallback, useEffect, useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import { confirmToast } from '@/lib/confirm'
import { Button } from '@/components/ui/button'
import ThemeToggle from '@/components/ThemeToggle'
import { LogOut, Menu } from 'lucide-react'
import Sidebar, { navItems } from './Sidebar'

const COLLAPSED_KEY = 'adms.sidebar.collapsed'

export default function Layout({ children }) {
  const { url, props } = usePage()
  const user = props?.auth?.user

  const current = navItems.find((item) => item.href === url.split('?')[0])
  const pageTitle = current ? (current.title ?? current.label) : ''

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

  const logout = () => {
    confirmToast({
      message: 'Keluar dari aplikasi?',
      confirmLabel: 'Keluar',
      onConfirm: () => router.post('/logout'),
    })
  }

  return (
    <div className="flex min-h-screen bg-slate-50 dark:bg-slate-950">
      <Sidebar
        collapsed={collapsed}
        onToggleCollapse={() => setCollapsed((v) => !v)}
        mobileOpen={mobileOpen}
        onCloseMobile={closeMobile}
      />

      {/* min-w-0: tanpa ini kolom konten menolak menyusut dan tabel lebar
          (Logs/Fingerprints/Machines) memaksa scroll horizontal di body. */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-border bg-card px-4 sm:px-6">
          <button
            type="button"
            onClick={() => setMobileOpen(true)}
            aria-label="Buka menu"
            aria-expanded={mobileOpen}
            className="-ml-2 rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-accent-foreground md:hidden"
          >
            <Menu className="h-5 w-5" />
          </button>

          <h1 className="truncate text-lg font-semibold">{pageTitle}</h1>

          <div className="ml-auto flex items-center gap-2">
            <ThemeToggle className="text-muted-foreground" />

            {user && (
              <>
                <span className="hidden text-sm text-muted-foreground md:inline" title={user.email}>
                  {user.name}
                </span>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={logout}
                  className="flex items-center gap-2 text-muted-foreground"
                  title="Logout"
                >
                  <LogOut className="h-4 w-4" />
                  <span className="hidden sm:inline">Keluar</span>
                </Button>
              </>
            )}
          </div>
        </header>

        <main className="flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>
      </div>
    </div>
  )
}
