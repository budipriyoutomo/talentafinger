import React, { useState } from 'react'
import { Head } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Button } from '@/components/ui/button'
import { UserCircle, Building2 } from 'lucide-react'
import EmployeesPanel from '@/components/EmployeesPanel'
import OrgStructurePanel from '@/components/OrgStructurePanel'

// Tab awal bisa di-preselect via ?tab=structure (mis. dari link lama).
function initialTab() {
  if (typeof window === 'undefined') return 'employees'
  const t = new URLSearchParams(window.location.search).get('tab')
  return t === 'structure' ? t : 'employees'
}

export default function EmployeeManagement({ employees = [], machines = [], companies = [] }) {
  const [tab, setTab] = useState(initialTab)

  const tabs = [
    { key: 'employees', label: 'Karyawan', icon: UserCircle },
    { key: 'structure', label: 'Struktur Organisasi', icon: Building2 },
  ]

  return (
    <Layout>
      <Head title="Employees" />

      <div className="space-y-6">
        <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Employees</h1>

        {/* Tab switcher */}
        <div className="flex gap-2 border-b border-slate-200 dark:border-slate-800">
          {tabs.map(({ key, label, icon: Icon }) => (
            <Button
              key={key}
              variant="ghost"
              onClick={() => setTab(key)}
              className={`gap-2 rounded-none border-b-2 ${
                tab === key
                  ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                  : 'border-transparent text-slate-500'
              }`}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Button>
          ))}
        </div>

        {tab === 'employees' && <EmployeesPanel employees={employees} companies={companies} machines={machines} />}
        {tab === 'structure' && <OrgStructurePanel companies={companies} />}
      </div>
    </Layout>
  )
}
