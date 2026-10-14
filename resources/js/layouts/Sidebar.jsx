import React, { useEffect, useMemo } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { cn } from '@/lib/utils'
import { usePermissions } from '@/lib/permissions'
import {
  LayoutDashboard,
  Cpu,
  FileText,
  MapPin,
  UserCircle,
  Fingerprint,
  Settings,
  PanelLeftClose,
  PanelLeftOpen,
  ScanFace,
} from 'lucide-react'

// `label` = teks di sidebar (pendek). `title` = judul halaman di topbar, dipakai
// hanya kalau judulnya beda dari label (mis. "Logs" vs "Attendance Logs").
// `permission` = izin minimum untuk melihat menunya; halaman itu sendiri tetap
// dijaga di server (buka URL-nya langsung tanpa izin = 403).
export const navItems = [
  { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { href: '/machines', label: 'Machines', icon: Cpu, permission: 'machine.view' },
  { href: '/attendance-logs', label: 'Logs', title: 'Attendance Logs', icon: FileText, permission: 'attendance.view' },
  { href: '/employee-presence', label: 'Absen per Mesin', title: 'Karyawan Absen di Mesin Mana', icon: MapPin, permission: 'attendance.view' },
  { href: '/employee-management', label: 'Employees', icon: UserCircle, permission: 'employee.view' },
  { href: '/fingerprints', label: 'Sidik Jari', icon: Fingerprint, permission: 'fingerprint.view' },
  { href: '/settings', label: 'Pengaturan', icon: Settings, permission: 'setting.manage' },
]

export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onCloseMobile }) {
  const { url } = usePage()
  const pathname = url.split('?')[0]
  const { can } = usePermissions()

  const visibleItems = useMemo(
    () => navItems.filter((item) => !item.permission || can(item.permission)),
    [can]
  )

  // Drawer ditutup saat pindah halaman dan saat Esc ditekan.
  useEffect(() => {
    onCloseMobile()
  }, [url])

  useEffect(() => {
    if (!mobileOpen) return

    const onKeyDown = (e) => {
      if (e.key === 'Escape') onCloseMobile()
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [mobileOpen, onCloseMobile])

  return (
    <>
      {/* Overlay khusus mobile. z-40 — harus DI BAWAH dialog (z-50), kalau tidak
          drawer menutupi BulkDistributeDialog / EmployeeFingerprintDialog. */}
      {mobileOpen && (
        <div
          onClick={onCloseMobile}
          className="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm md:hidden"
          aria-hidden="true"
        />
      )}

      <aside
        className={cn(
          // Mobile: drawer melayang selebar w-64 yang di-slide dari kiri.
          // Desktop (md+): kolom statis, lebarnya ikut state collapsed.
          'fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground shadow-xl transition-[width,transform] duration-200 md:sticky md:top-0 md:h-screen md:translate-x-0 md:shadow-none',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
          collapsed ? 'md:w-16' : 'md:w-64'
        )}
      >
        <div
          className={cn(
            'flex h-16 shrink-0 items-center justify-between border-b border-sidebar-border px-4',
            collapsed && 'md:justify-center md:px-0'
          )}
        >
          <Link
            href="/dashboard"
            className={cn('flex items-center gap-2.5', collapsed && 'md:hidden')}
          >
            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-sm shadow-indigo-500/30">
              <ScanFace className="h-4.5 w-4.5" />
            </span>
            <span className="leading-tight">
              <span className="block text-base font-bold tracking-tight">ADMS</span>
              <span className="block text-[11px] font-medium text-muted-foreground">Talenta Middleware</span>
            </span>
          </Link>

          <button
            type="button"
            onClick={onToggleCollapse}
            aria-label={collapsed ? 'Lebarkan menu' : 'Ciutkan menu'}
            className="hidden rounded-md p-2 text-muted-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground md:block"
          >
            {collapsed ? <PanelLeftOpen className="h-4 w-4" /> : <PanelLeftClose className="h-4 w-4" />}
          </button>
        </div>

        <nav aria-label="Menu utama" className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
          <p className={cn('px-3 pb-2 text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase', collapsed && 'md:hidden')}>
            Menu
          </p>
          {visibleItems.map(({ href, label, icon: Icon }) => {
            const active = pathname === href

            return (
              <Link
                key={href}
                href={href}
                aria-current={active ? 'page' : undefined}
                title={collapsed ? label : undefined}
                className={cn(
                  'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                  collapsed && 'md:justify-center md:px-0',
                  active
                    ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground'
                    : 'font-medium text-muted-foreground hover:bg-muted hover:text-foreground'
                )}
              >
                {active && (
                  <span className="absolute top-1.5 bottom-1.5 left-0 w-1 rounded-r-full bg-primary" aria-hidden="true" />
                )}
                <Icon className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'opacity-80 group-hover:opacity-100')} />
                {/* Label tetap tampil di drawer mobile meski state desktop-nya collapsed. */}
                <span className={cn(collapsed && 'md:hidden')}>{label}</span>
              </Link>
            )
          })}
        </nav>
      </aside>
    </>
  )
}
