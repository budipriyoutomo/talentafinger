import React from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { confirmToast } from '@/lib/confirm'
import { Button } from '@/components/ui/button'
import { LayoutDashboard, Cpu, FileText, UserCircle, Fingerprint, Settings, LogOut } from 'lucide-react'

export default function Layout({ children }) {
  const { url, props } = usePage()
  const user = props?.auth?.user

  const isActive = (path) => url === path

  const logout = () => {
    confirmToast({
      message: 'Keluar dari aplikasi?',
      confirmLabel: 'Keluar',
      onConfirm: () => router.post('/logout'),
    })
  }

  const navItems = [
    { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/machines', label: 'Machines', icon: Cpu },
    { href: '/attendance-logs', label: 'Logs', icon: FileText },
    { href: '/employee-management', label: 'Employees', icon: UserCircle },
    { href: '/fingerprints', label: 'Sidik Jari', icon: Fingerprint },
    { href: '/settings', label: 'Pengaturan', icon: Settings },
  ]

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      <nav className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center h-16">
            <Link href="/dashboard" className="text-xl font-bold text-slate-900 dark:text-slate-50">
              ADMS
            </Link>
            <div className="flex items-center gap-2">
              {navItems.map(({ href, label, icon: Icon }) => (
                <Link key={href} href={href}>
                  <Button
                    variant={isActive(href) ? 'default' : 'ghost'}
                    size="sm"
                    className="flex items-center gap-2"
                  >
                    <Icon className="h-4 w-4" />
                    <span className="hidden sm:inline">{label}</span>
                  </Button>
                </Link>
              ))}
              {user && (
                <div className="flex items-center gap-2 pl-2 ml-1 border-l border-slate-200 dark:border-slate-800">
                  <span className="hidden md:inline text-sm text-slate-500" title={user.email}>
                    {user.name}
                  </span>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={logout}
                    className="flex items-center gap-2 text-slate-500"
                    title="Logout"
                  >
                    <LogOut className="h-4 w-4" />
                    <span className="hidden sm:inline">Keluar</span>
                  </Button>
                </div>
              )}
            </div>
          </div>
        </div>
      </nav>

      <main className="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        {children}
      </main>
    </div>
  )
}
