import React from 'react'
import { Head } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { useNavigation } from '@/lib/navigation'
import AppSettingsPanel from '@/components/AppSettingsPanel'
import UsersPanel from '@/components/UsersPanel'
import RolesPanel from '@/components/RolesPanel'
import AttendanceSourcesPanel from '@/components/AttendanceSourcesPanel'

/**
 * /settings berisi tiga bagian yang masing-masing jadi menu sendiri di grup
 * "Administrasi" sidebar (/settings?tab=users|roles|app|sources). Tab aktif dibaca dari
 * lib/navigation — sumber yang sama dengan sidebar & judul topbar — jadi tab
 * tanpa izin / tak dikenal otomatis jatuh ke tab pertama yang diizinkan.
 * Server pun hanya mengirim data tab yang diizinkan.
 */
export default function Settings({ groups = {}, attendanceSources = [], users = [], roles = [], permissionCatalog = [], companies = [], employees = [] }) {
  const { active } = useNavigation()
  const activeTab = active?.path === '/settings' ? active.tab : null

  return (
    <Layout>
      <Head title={active?.title ?? active?.label ?? 'Pengaturan'} />

      {activeTab === 'app' && <AppSettingsPanel groups={groups} />}
      {activeTab === 'sources' && <AttendanceSourcesPanel sources={attendanceSources} />}
      {activeTab === 'users' && (
        <UsersPanel users={users} roles={roles} companies={companies} employees={employees} />
      )}
      {activeTab === 'roles' && <RolesPanel roles={roles} permissionCatalog={permissionCatalog} />}
    </Layout>
  )
}
