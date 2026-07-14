import { usePage } from '@inertiajs/react'

/**
 * Izin user yang sedang login, dikirim lewat HandleInertiaRequests.
 *
 * Ini hanya untuk MERAPIKAN tampilan — menyembunyikan tombol yang pasti ditolak
 * server. Bukan pengaman: backend tetap memeriksa policy & scope pada tiap
 * request, jadi jangan pernah menjadikan pemeriksaan di sini sebagai satu-satunya
 * penghalang.
 *
 *   const { can } = usePermissions()
 *   {can('machine.manage') && <Button>Hapus</Button>}
 */
export function usePermissions() {
  const { props } = usePage()
  const permissions = props?.auth?.permissions ?? []
  const role = props?.auth?.user?.role ?? null

  return {
    permissions,
    role,
    can: (permission) => permissions.includes(permission),
    // Beberapa aksi butuh salah satu dari sejumlah izin.
    canAny: (...list) => list.some((p) => permissions.includes(p)),
  }
}
