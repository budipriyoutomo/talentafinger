import React from 'react'
import { Link, usePage } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { LayoutDashboard, Cpu, FileText, Users } from 'lucide-react'

export default function Layout({ children }) {
  const { url } = usePage()

  const isActive = (path) => url === path

  const navItems = [
    { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { href: '/machines', label: 'Machines', icon: Cpu },
    { href: '/attendance-logs', label: 'Logs', icon: FileText },
    { href: '/employee-mappings', label: 'Mappings', icon: Users },
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
