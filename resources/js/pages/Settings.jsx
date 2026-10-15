import React, { useState } from 'react'
import { Head } from '@inertiajs/react'
import { usePermissions } from '@/lib/permissions'
import Layout from '../layouts/Layout'
import { Button } from '@/components/ui/button'
import { KeyRound, SlidersHorizontal, Users } from 'lucide-react'
import AppSettingsPanel from '@/components/AppSettingsPanel'
import UsersPanel from '@/components/UsersPanel'
import RolesPanel from '@/components/RolesPanel'

// Tab awal bisa di-preselect via ?tab=users (mis. dari link langsung).
function initialTab() {
  if (typeof window === 'undefined') return null
  return new URLSearchParams(window.location.search).get('tab')
}

export default function Settings({ groups = {}, users = [], roles = [], permissionCatalog = [], companies = [], employees = [] }) {
  const { can } = usePermissions()

  const [tab, setTab] = useState(initialTab)

  // Tiap tab muncul hanya bila permission-nya ada (server pun hanya mengirim
  // data tab yang diizinkan).
  const tabs = [
    ...(can('setting.manage') ? [{ key: 'app', label: 'Setting Aplikasi', icon: SlidersHorizontal }] : []),
    ...(can('user.manage') ? [{ key: 'users', label: 'User', icon: Users }] : []),
    ...(can('role.manage') ? [{ key: 'roles', label: 'Role & Permission', icon: KeyRound }] : []),
  ]

  // Tab dari URL yang tak diizinkan / tak dikenal jatuh ke tab pertama.
  const activeTab = tabs.some((t) => t.key === tab) ? tab : tabs[0]?.key

  return (
    <Layout>
      <Head title="Pengaturan" />

      <div className="space-y-6">
        <p className="text-sm text-muted-foreground">
          Konfigurasi aplikasi serta manajemen user &amp; role.
        </p>

        {/* Tab switcher */}
        <div className="inline-flex flex-wrap gap-1 rounded-xl border bg-muted/50 p-1">
          {tabs.map(({ key, label, icon: Icon }) => (
            <Button
              key={key}
              variant="ghost"
              size="sm"
              onClick={() => setTab(key)}
              aria-pressed={activeTab === key}
              className={`gap-2 rounded-lg ${
                activeTab === key
                  ? 'bg-card text-primary shadow-sm hover:bg-card hover:text-primary'
                  : 'text-muted-foreground hover:bg-transparent hover:text-foreground'
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Button>
          ))}
        </div>

        {activeTab === 'app' && <AppSettingsPanel groups={groups} />}
        {activeTab === 'users' && (
          <UsersPanel users={users} roles={roles} companies={companies} employees={employees} />
        )}
        {activeTab === 'roles' && <RolesPanel roles={roles} permissionCatalog={permissionCatalog} />}
      </div>
    </Layout>
  )
}
