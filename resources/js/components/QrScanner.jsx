import React, { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { CAMERA_ERRORS } from '@/components/LiveCamera'
import { Loader2, X } from 'lucide-react'

// Frame diperkecil sebelum di-decode: QR di layar tablet cukup besar, dan
// decode di resolusi penuh membuat HP murah tersendat.
const MAX_SCAN_SIDE = 640
const SCAN_EVERY_MS = 250

/**
 * Scanner QR langsung di halaman (kamera belakang + jsQR), supaya karyawan
 * yang sudah login tak perlu keluar ke aplikasi kamera HP. Memanggil
 * onDetected(teks QR) sekali, lalu berhenti memindai.
 *
 * jsQR dipakai (bukan BarcodeDetector bawaan) karena Safari iOS tak punya
 * BarcodeDetector. Di-load dinamis supaya halaman lain tak ikut menanggungnya.
 */
export default function QrScanner({ onDetected, onClose, hint }) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const onDetectedRef = useRef(onDetected)
  const [ready, setReady] = useState(false)
  const [error, setError] = useState(null)

  onDetectedRef.current = onDetected

  useEffect(() => {
    let cancelled = false
    let timer = null
    const canvas = document.createElement('canvas')
    const ctx = canvas.getContext('2d', { willReadFrequently: true })

    const start = async () => {
      try {
        const [{ default: jsQR }, stream] = await Promise.all([
          import('jsqr'),
          navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }),
        ])
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current = stream
        const video = videoRef.current
        video.srcObject = stream
        video.play().catch(() => {})

        const tick = () => {
          if (cancelled) return
          if (video.videoWidth) {
            const scale = Math.min(1, MAX_SCAN_SIDE / Math.max(video.videoWidth, video.videoHeight))
            canvas.width = Math.round(video.videoWidth * scale)
            canvas.height = Math.round(video.videoHeight * scale)
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
            const { data, width, height } = ctx.getImageData(0, 0, canvas.width, canvas.height)
            const code = jsQR(data, width, height, { inversionAttempts: 'attemptBoth' })
            if (code?.data) {
              // Hasil yang ditolak pemanggil (bukan QR absen) tetap lanjut memindai.
              if (onDetectedRef.current(code.data) !== false) return
            }
          }
          timer = setTimeout(tick, SCAN_EVERY_MS)
        }
        tick()
      } catch (err) {
        if (!cancelled) setError(CAMERA_ERRORS[err.name] || `Kamera tidak bisa dibuka: ${err.message}`)
      }
    }
    start()

    // Selalu matikan kamera saat ditutup (lampu indikator kamera ikut padam).
    return () => {
      cancelled = true
      clearTimeout(timer)
      streamRef.current?.getTracks().forEach((t) => t.stop())
      streamRef.current = null
    }
  }, [])

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-black">
      <div className="flex items-center justify-between px-4 py-3 text-white">
        <p className="text-sm font-medium">Scan QR di layar outlet</p>
        <Button variant="ghost" size="sm" className="text-white hover:bg-white/10 hover:text-white" onClick={onClose} aria-label="Tutup scanner">
          <X className="h-5 w-5" />
        </Button>
      </div>

      <div className="relative flex flex-1 items-center justify-center overflow-hidden">
        {error ? (
          <p className="max-w-xs px-4 text-center text-sm text-white">{error}</p>
        ) : (
          <>
            <video
              ref={videoRef}
              playsInline
              muted
              autoPlay
              onLoadedData={() => setReady(true)}
              className="h-full w-full object-cover"
            />
            {/* Bingkai bidik */}
            <div className="pointer-events-none absolute aspect-square w-2/3 max-w-xs rounded-xl border-4 border-white/80 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]" />
            {!ready && <Loader2 className="absolute h-8 w-8 animate-spin text-white" />}
          </>
        )}
      </div>

      <div className="px-4 pb-8 pt-4 text-center text-sm text-white/90">
        {error ? (
          <Button variant="secondary" onClick={onClose}>Tutup</Button>
        ) : (
          hint || 'Arahkan kamera ke QR di tablet outlet. QR akan terbaca otomatis.'
        )}
      </div>
    </div>
  )
}
