import { useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import { usePermissions } from '@/lib/permissions'
import {
  LayoutDashboard,
  Cpu,
  FileText,
  MapPin,
  UserCircle,
  Fingerprint,
  History,
  SlidersHorizontal,
  Users,
  KeyRound,
  QrCode,
  Database,
} from 'lucide-react'

/**
 * SATU-SATUNYA peta menu -> permission. Dipakai Sidebar (menu), Layout (judul
 * topbar), dan Settings (tab yang boleh tampil).
 *
 * Tiap item:
 *   path       = URL halaman.
 *   tab        = opsional, untuk halaman bertab (/settings?tab=...).
 *   label      = teks di sidebar (pendek).
 *   title      = judul topbar bila beda dari label.
 *   permission = izin untuk melihat menunya (array = cukup salah satu; tanpa
 *                permission = semua user panel). Halaman itu sendiri TETAP
 *                dijaga server — menyembunyikan menu bukan pengaman.
 *
 * Grup yang semua item-nya tersembunyi ikut disembunyikan.
 */
export const navGroups = [
  {
    key: 'main',
    label: null,
    items: [
      { path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    ],
  },
  {
    key: 'attendance',
    label: 'Absensi',
    items: [
      { path: '/attendance-logs', label: 'Logs', title: 'Attendance Logs', icon: FileText, permission: 'attendance.view' },
      { path: '/employee-presence', label: 'Absen per Mesin', title: 'Karyawan Absen di Mesin Mana', icon: MapPin, permission: 'attendance.view' },
    ],
  },
  {
    key: 'employee',
    label: 'Karyawan',
    items: [
      { path: '/employee-management', label: 'Employees', icon: UserCircle, permission: 'employee.view' },
      { path: '/fingerprints', label: 'Sidik Jari', icon: Fingerprint, permission: 'fingerprint.view' },
    ],
  },
  {
    key: 'device',
    label: 'Perangkat',
    items: [
      { path: '/machines', label: 'Machines', icon: Cpu, permission: 'machine.view' },
      { path: '/kiosks', label: 'Kiosk QR', title: 'Kiosk Absen QR', icon: QrCode, permission: 'kiosk.manage' },
    ],
  },
  {
    key: 'admin',
    label: 'Administrasi',
    items: [
      { path: '/settings', tab: 'users', label: 'User', title: 'Manajemen User', icon: Users, permission: 'user.manage' },
      { path: '/settings', tab: 'roles', label: 'Role & Permission', icon: KeyRound, permission: 'role.manage' },
      { path: '/settings', tab: 'app', label: 'Pengaturan Aplikasi', icon: SlidersHorizontal, permission: 'setting.manage' },
      { path: '/settings', tab: 'sources', label: 'Sumber Absensi', title: 'Master Sumber Absensi', icon: Database, permission: 'setting.manage' },
      { path: '/audit-logs', label: 'Audit Log', icon: History, permission: 'audit.view' },
    ],
  },
]

export const hrefOf = (item) => (item.tab ? `${item.path}?tab=${item.tab}` : item.path)

/**
 * Menu yang boleh dilihat user saat ini + item yang aktif untuk URL sekarang.
 * Untuk halaman bertab tanpa ?tab=, yang aktif adalah tab PERTAMA yang boleh
 * dilihat — sama dengan tab bawaan yang dibuka halaman Settings.
 */
export function useNavigation() {
  const { url } = usePage()
  const { can, canAny } = usePermissions()

  return useMemo(() => {
    const allowed = (item) =>
      !item.permission ||
      (Array.isArray(item.permission) ? canAny(...item.permission) : can(item.permission))

    const groups = navGroups
      .map((g) => ({ ...g, items: g.items.filter(allowed) }))
      .filter((g) => g.items.length)
    const items = groups.flatMap((g) => g.items)

    const [pathname, query = ''] = url.split('?')
    const tab = new URLSearchParams(query).get('tab')
    const samePath = items.filter((i) => i.path === pathname)
    const active = samePath.find((i) => i.tab && i.tab === tab) ?? samePath[0] ?? null

    return { groups, items, active }
  }, [url, can, canAny])
}
