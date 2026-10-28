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
  Camera,
  ScanLine,
  UserPlus,
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
 *   linkedEmployee = hanya untuk user yang ditautkan ke data karyawan (absen
 *                sendiri). Admin memegang semua permission, jadi permission
 *                saja tak cukup untuk menentukan ia bisa absen.
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
    key: 'self',
    label: 'Absen Saya',
    items: [
      { path: '/absen/qr', label: 'Absen QR', icon: ScanLine, permission: 'attendance.qr.create', linkedEmployee: true },
      { path: '/absen', label: 'Absen Foto', icon: Camera, permission: 'attendance.manual.create', linkedEmployee: true },
    ],
  },
  {
    key: 'employee',
    label: 'Karyawan',
    items: [
      { path: '/employee-management', label: 'Employees', icon: UserCircle, permission: 'employee.view' },
      { path: '/fingerprints', label: 'Sidik Jari', icon: Fingerprint, permission: 'fingerprint.view' },
      { path: '/registrations', label: 'Pendaftaran Akun', title: 'Pendaftaran Akun Karyawan', icon: UserPlus, permission: 'user.registration.review' },
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
  const { url, props } = usePage()
  const { can, canAny } = usePermissions()
  const employeeLinked = !!props?.auth?.user?.employee_linked

  return useMemo(() => {
    const allowed = (item) =>
      (!item.linkedEmployee || employeeLinked) &&
      (!item.permission ||
        (Array.isArray(item.permission) ? canAny(...item.permission) : can(item.permission)))

    const groups = navGroups
      .map((g) => ({ ...g, items: g.items.filter(allowed) }))
      .filter((g) => g.items.length)
    const items = groups.flatMap((g) => g.items)

    const [pathname, query = ''] = url.split('?')
    const tab = new URLSearchParams(query).get('tab')
    const samePath = items.filter((i) => i.path === pathname)
    const active = samePath.find((i) => i.tab && i.tab === tab) ?? samePath[0] ?? null

    return { groups, items, active }
  }, [url, can, canAny, employeeLinked])
}
