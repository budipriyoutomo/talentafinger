import { toast } from 'sonner'

/**
 * Konfirmasi berbasis toast (Sonner) menggantikan `window.confirm` bawaan
 * browser. Non-blocking: `onConfirm` dipanggil saat tombol aksi diklik.
 * Toast persist (tidak auto-hilang) supaya keputusan eksplisit.
 */
export function confirmToast({
  message,
  description,
  confirmLabel = 'Ya',
  cancelLabel = 'Batal',
  onConfirm,
  destructive = false,
}) {
  toast(message, {
    description,
    duration: Infinity,
    action: {
      label: confirmLabel,
      onClick: () => onConfirm?.(),
    },
    cancel: { label: cancelLabel },
    actionButtonStyle: destructive
      ? { background: 'var(--destructive)', color: '#fff' }
      : undefined,
  })
}
