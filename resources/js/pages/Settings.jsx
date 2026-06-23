import React, { useState } from 'react'
import { Head, usePage } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Button } from '@/components/ui/button'
import { Settings as SettingsIcon, SlidersHorizontal, Users } from 'lucide-react'
import AppSettingsPanel from '@/components/AppSettingsPanel'
import UsersPanel from '@/components/UsersPanel'

// Tab awal bisa di-preselect via ?tab=users (mis. dari link langsung).
function initialTab() {
  if (typeof window === 'undefined') return 'app'
  const t = new URLSearchParams(window.location.search).get('tab')
  return ['app', 'users'].includes(t) ? t : 'app'
}

export default function Settings({ groups = {}, users = [], roles = [] }) {
  const { props } = usePage()
  const isAdmin = props?.auth?.user?.role === 'admin'

  const [tab, setTab] = useState(initialTab)

  // Tab "User & Role" hanya untuk admin.
  const tabs = [
    { key: 'app', label: 'Setting Aplikasi', icon: SlidersHorizontal },
    ...(isAdmin ? [{ key: 'users', label: 'User & Role', icon: Users }] : []),
  ]

  // Jaga-jaga bila non-admin membuka ?tab=users.
  const activeTab = tab === 'users' && !isAdmin ? 'app' : tab

  return (
    <Layout>
      <Head title="Pengaturan" />

      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-50 flex items-center gap-2">
            <SettingsIcon className="h-6 w-6 text-indigo-500" />
            Pengaturan
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Konfigurasi aplikasi serta manajemen user & role.
          </p>
        </div>

        {/* Tab switcher */}
        <div className="flex gap-2 border-b border-slate-200 dark:border-slate-800">
          {tabs.map(({ key, label, icon: Icon }) => (
            <Button
              key={key}
              variant="ghost"
              onClick={() => setTab(key)}
              className={`gap-2 rounded-none border-b-2 ${
                activeTab === key
                  ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-slate-500'
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Button>
          ))}
        </div>

        {activeTab === 'app' && <AppSettingsPanel groups={groups} />}
        {activeTab === 'users' && isAdmin && <UsersPanel users={users} roles={roles} />}
      </div>
    </Layout>
  )
}
