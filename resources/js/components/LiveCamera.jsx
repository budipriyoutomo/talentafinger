import React, { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Camera, Loader2, X } from 'lucide-react'

// Foto diperkecil sebelum diunggah: hemat kuota karyawan & disk server, dan
// tetap jauh di bawah batas upload server.
const MAX_PHOTO_SIDE = 1280
const PHOTO_QUALITY = 0.8

const CAMERA_ERRORS = {
  NotAllowedError: 'Izin kamera ditolak. Aktifkan izin kamera untuk situs ini di pengaturan browser.',
  NotFoundError: 'Kamera tidak ditemukan di perangkat ini.',
  NotReadableError: 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi tersebut lalu coba lagi.',
  OverconstrainedError: 'Kamera depan tidak tersedia.',
  SecurityError: 'Kamera hanya bisa dipakai lewat HTTPS.',
}

/** Browser ini bisa membuka kamera langsung di halaman? */
export function cameraSupported() {
  return typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia
}

/**
 * Kamera langsung di halaman (getUserMedia), SENGAJA tanpa pilihan galeri:
 * foto absen harus diambil saat itu juga, bukan foto lama dari galeri.
 * Memanggil onCapture(blob JPEG) lalu menutup kamera.
 */
export default function LiveCamera({ onCapture, onClose }) {
  const videoRef = useRef(null)
  const streamRef = useRef(null)
  const [ready, setReady] = useState(false)
  const [error, setError] = useState(null)
  const [capturing, setCapturing] = useState(false)

  useEffect(() => {
    let cancelled = false

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } }, audio: false })
      .then((stream) => {
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        streamRef.current = stream
        const video = videoRef.current
        video.srcObject = stream
        video.play().catch(() => {})
      })
      .catch((err) => setError(CAMERA_ERRORS[err.name] || `Kamera tidak bisa dibuka: ${err.message}`))

    // Selalu matikan kamera saat ditutup (lampu indikator kamera ikut padam).
    return () => {
      cancelled = true
      streamRef.current?.getTracks().forEach((t) => t.stop())
      streamRef.current = null
    }
  }, [])

  const capture = async () => {
    const video = videoRef.current
    if (!video?.videoWidth) return
    setCapturing(true)
    try {
      const scale = Math.min(1, MAX_PHOTO_SIDE / Math.max(video.videoWidth, video.videoHeight))
      const canvas = document.createElement('canvas')
      canvas.width = Math.round(video.videoWidth * scale)
      canvas.height = Math.round(video.videoHeight * scale)
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height)
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', PHOTO_QUALITY))
      if (!blob) throw new Error('gagal membuat foto')
      onCapture(blob)
    } catch (err) {
      setError('Gagal mengambil foto: ' + err.message)
    } finally {
      setCapturing(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-black">
      <div className="flex items-center justify-between px-4 py-3 text-white">
        <p className="text-sm font-medium">Foto wajah</p>
        <Button variant="ghost" size="sm" className="text-white hover:bg-white/10 hover:text-white" onClick={onClose} aria-label="Tutup kamera">
          <X className="h-5 w-5" />
        </Button>
      </div>

      <div className="relative flex flex-1 items-center justify-center overflow-hidden">
        {error ? (
          <p className="max-w-xs px-4 text-center text-sm text-white">{error}</p>
        ) : (
          <>
            {/* Dicerminkan supaya terasa seperti cermin; foto hasilnya tidak. */}
            <video
              ref={videoRef}
              playsInline
              muted
              autoPlay
              onLoadedData={() => setReady(true)}
              className="h-full w-full -scale-x-100 object-contain"
            />
            {!ready && <Loader2 className="absolute h-8 w-8 animate-spin text-white" />}
          </>
        )}
      </div>

      <div className="flex justify-center px-4 pb-8 pt-4">
        {error ? (
          <Button variant="secondary" onClick={onClose}>Tutup</Button>
        ) : (
          <Button size="lg" className="gap-2 rounded-full px-8" onClick={capture} disabled={!ready || capturing}>
            {capturing ? <Loader2 className="h-5 w-5 animate-spin" /> : <Camera className="h-5 w-5" />}
            Ambil Foto
          </Button>
        )}
      </div>
    </div>
  )
}
