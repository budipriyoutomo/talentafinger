import React, { useState } from 'react'
import { Head } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Button } from '@/components/ui/button'
import { UserCircle, Building2, RefreshCw } from 'lucide-react'
import { usePermissions } from '@/lib/permissions'
import EmployeesPanel from '@/components/EmployeesPanel'
import OrgStructurePanel from '@/components/OrgStructurePanel'
import TalentaSyncPanel from '@/components/TalentaSyncPanel'

// Tab awal bisa di-preselect via ?tab=structure / ?tab=talenta.
function initialTab() {
  if (typeof window === 'undefined') return 'employees'
  const t = new URLSearchParams(window.location.search).get('tab')
  return ['structure', 'talenta'].includes(t) ? t : 'employees'
}

export default function EmployeeManagement({ employees = [], machines = [], companies = [], talentaPulled = false }) {
  const [tab, setTab] = useState(initialTab)
  // Struktur organisasi hanya bisa diubah admin (org.manage), dan tab ini murni
  // untuk mengubahnya — jadi tak perlu ditampilkan ke yang lain.
  const { can } = usePermissions()
  const canManageOrg = can('org.manage')
  // Sync Talenta mengubah karyawan di semua outlet -> butuh data.all juga (dicek ulang di server).
  const canSyncTalenta = can('employee.talenta_sync') && can('data.all')

  const tabs = [
    { key: 'employees', label: 'Karyawan', icon: UserCircle },
    ...(canSyncTalenta ? [{ key: 'talenta', label: 'Sinkron Talenta', icon: RefreshCw }] : []),
    ...(canManageOrg ? [{ key: 'structure', label: 'Struktur Organisasi', icon: Building2 }] : []),
  ]

  // Jaga-jaga bila user tanpa izin membuka ?tab=structure / ?tab=talenta.
  const activeTab = tabs.some((t) => t.key === tab) ? tab : 'employees'

  return (
    <Layout>
      <Head title="Employees" />

      <div className="space-y-6">
        {/* Tab switcher */}
        <div className="flex gap-2 border-b border-border">
          {tabs.map(({ key, label, icon: Icon }) => (
            <Button
              key={key}
              variant="ghost"
              onClick={() => setTab(key)}
              className={`gap-2 rounded-none border-b-2 ${
                activeTab === key
                  ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-muted-foreground'
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Button>
          ))}
        </div>

        {activeTab === 'employees' && (
          <EmployeesPanel employees={employees} companies={companies} machines={machines} talentaPulled={talentaPulled} />
        )}
        {activeTab === 'talenta' && canSyncTalenta && <TalentaSyncPanel employees={employees} />}
        {activeTab === 'structure' && canManageOrg && <OrgStructurePanel companies={companies} />}
      </div>
    </Layout>
  )
}
