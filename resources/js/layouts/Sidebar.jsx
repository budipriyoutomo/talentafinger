import React, { useEffect } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { cn } from '@/lib/utils'
import { hrefOf, useNavigation } from '@/lib/navigation'
import { PanelLeftClose, PanelLeftOpen, ScanFace } from 'lucide-react'

// Daftar menu, pengelompokan, dan permission tiap menu ada di lib/navigation.js.
export default function Sidebar({ collapsed, onToggleCollapse, mobileOpen, onCloseMobile }) {
  const { url } = usePage()
  const { groups, active } = useNavigation()

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

        <nav aria-label="Menu utama" className="flex-1 space-y-4 overflow-y-auto px-3 py-4">
          {groups.map((group, index) => (
            <div key={group.key} role="group" aria-labelledby={group.label ? `nav-${group.key}` : undefined}>
              {group.label && (
                <p
                  id={`nav-${group.key}`}
                  className={cn('px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase', collapsed && 'md:hidden')}
                >
                  {group.label}
                </p>
              )}
              {/* Saat diciutkan label grup hilang; garis tipis jadi pemisahnya. */}
              {collapsed && index > 0 && (
                <div className="mx-3 mb-2 hidden border-t border-sidebar-border md:block" aria-hidden="true" />
              )}

              <div className="space-y-1">
                {group.items.map((item) => {
                  const href = hrefOf(item)
                  const isActive = active === item
                  const Icon = item.icon

                  return (
                    <Link
                      key={href}
                      href={href}
                      aria-current={isActive ? 'page' : undefined}
                      title={collapsed ? item.label : undefined}
                      className={cn(
                        'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                        collapsed && 'md:justify-center md:px-0',
                        isActive
                          ? 'bg-sidebar-accent font-semibold text-sidebar-accent-foreground'
                          : 'font-medium text-muted-foreground hover:bg-muted hover:text-foreground'
                      )}
                    >
                      {isActive && (
                        <span className="absolute top-1.5 bottom-1.5 left-0 w-1 rounded-r-full bg-primary" aria-hidden="true" />
                      )}
                      <Icon className={cn('h-4 w-4 shrink-0', isActive ? 'text-primary' : 'opacity-80 group-hover:opacity-100')} />
                      {/* Label tetap tampil di drawer mobile meski state desktop-nya collapsed. */}
                      <span className={cn(collapsed && 'md:hidden')}>{item.label}</span>
                    </Link>
                  )
                })}
              </div>
            </div>
          ))}
        </nav>
      </aside>
    </>
  )
}
