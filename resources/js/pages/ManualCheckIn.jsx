import React, { useCallback, useEffect, useRef, useState } from 'react'
import { Head, router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import ThemeToggle from '@/components/ThemeToggle'
import { STATUS_LABEL, STATUS_VARIANT } from '@/components/ManualReviewDialog'
import { Camera, CheckCircle2, Loader2, LogOut, MapPin, RefreshCw, Send, ShieldAlert } from 'lucide-react'

// Foto diperkecil di browser sebelum diunggah: hemat kuota karyawan & disk
// server, dan tetap jauh di bawah batas upload server (max_photo_kb).
const MAX_PHOTO_SIDE = 1280
const PHOTO_QUALITY = 0.8

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

/** Kecilkan foto ke sisi terpanjang MAX_PHOTO_SIDE px, hasil JPEG. */
async function compressPhoto(file) {
  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, MAX_PHOTO_SIDE / Math.max(bitmap.width, bitmap.height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close?.()
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', PHOTO_QUALITY))
  return blob || file
}

const GEO_ERRORS = {
  1: 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan browser.',
  2: 'Lokasi tidak tersedia. Pastikan GPS/lokasi HP aktif.',
  3: 'Waktu habis saat mencari lokasi. Coba lagi di tempat terbuka.',
}

/**
 * Halaman absen manual pakai foto untuk akun KARYAWAN (role employee).
 * Mobile-first, tanpa layout admin. Alur: lokasi -> foto -> kirim ->
 * menunggu persetujuan supervisor.
 */
export default function ManualCheckIn({ employee, blocked_reason, radius_meters, max_photo_kb, recent = [] }) {
  const [position, setPosition] = useState(null) // { latitude, longitude, accuracy }
  const [locating, setLocating] = useState(false)
  const [geoError, setGeoError] = useState(null)

  const [photo, setPhoto] = useState(null) // Blob siap kirim
  const [preview, setPreview] = useState(null)
  const [preparing, setPreparing] = useState(false)

  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState(null) // { ok, message }
  const fileRef = useRef(null)

  // Semua alasan blokir (fitur mati, belum diizinkan, outlet/koordinat) dari server.
  const blocked = blocked_reason

  const locate = useCallback(() => {
    if (!('geolocation' in navigator)) {
      setGeoError('Browser ini tidak mendukung lokasi.')
      return
    }
    setLocating(true)
    setGeoError(null)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setPosition({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
        })
        setLocating(false)
      },
      (err) => {
        setGeoError(GEO_ERRORS[err.code] || err.message)
        setLocating(false)
      },
      { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
    )
  }, [])

  // Langsung minta lokasi saat halaman dibuka (selama tidak diblokir).
  useEffect(() => {
    if (!blocked) locate()
  }, [blocked, locate])

  useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview])

  const onPhotoPicked = async (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setPreparing(true)
    setResult(null)
    let picked = file
    try {
      picked = await compressPhoto(file)
    } catch {
      // Browser lama tanpa createImageBitmap: pakai file aslinya.
    }
    try {
      // File asli (gagal dikecilkan) bisa melebihi batas upload server: tolak
      // di sini dengan pesan jelas daripada ditolak diam-diam oleh PHP.
      if (max_photo_kb && picked.size > max_photo_kb * 1024) {
        setPhoto(null)
        setPreview(null)
        setResult({
          ok: false,
          message: `Foto terlalu besar (${(picked.size / 1048576).toFixed(1)}MB, maks ${(max_photo_kb / 1024).toFixed(1)}MB). Coba ambil ulang dengan resolusi kamera lebih kecil.`,
        })
        return
      }
      setPhoto(picked)
      setPreview(URL.createObjectURL(picked))
    } finally {
      setPreparing(false)
    }
  }

  const submit = async () => {
    if (!position || !photo) return
    setSubmitting(true)
    setResult(null)
    try {
      const form = new FormData()
      form.append('photo', photo, 'absen.jpg')
      form.append('latitude', position.latitude)
      form.append('longitude', position.longitude)
      form.append('accuracy', Math.round(position.accuracy))
      const res = await fetch('/api/attendance/manual', {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: form,
      })
      const data = await res.json().catch(() => ({}))
      const message = (res.status === 413 && 'Foto terlalu besar untuk diunggah. Ambil ulang foto.')
        || data.message
        || Object.values(data.errors || {})[0]?.[0]
        || 'Gagal mengirim absen.'
      setResult({ ok: res.ok, message })
      if (res.ok) {
        toast.success('Absen terkirim')
        setPhoto(null)
        setPreview(null)
        router.reload({ only: ['recent'] })
      }
    } catch (err) {
      setResult({ ok: false, message: 'Gagal mengirim: ' + err.message })
    } finally {
      setSubmitting(false)
    }
  }

  const logout = () => router.post('/logout')

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      <Head title="Absen Foto" />

      <header className="sticky top-0 z-10 border-b border-border bg-card">
        <div className="mx-auto flex max-w-md items-center justify-between gap-2 px-4 py-3">
          <div className="min-w-0">
            <p className="text-xs text-muted-foreground">Absen Foto</p>
            <p className="truncate font-semibold text-foreground">{employee?.name || 'Karyawan'}</p>
          </div>
          <div className="flex items-center gap-1">
            <ThemeToggle className="text-muted-foreground" />
            <Button variant="ghost" size="sm" className="gap-1" onClick={logout}>
              <LogOut className="h-4 w-4" /> Keluar
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-md space-y-4 px-4 py-4">
        {blocked ? (
          <Card>
            <CardContent className="flex gap-3 pt-6">
              <ShieldAlert className="h-5 w-5 shrink-0 text-amber-500" />
              <p className="text-sm text-foreground">{blocked}</p>
            </CardContent>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle>Absen sekarang</CardTitle>
              <CardDescription>
                Harus dalam radius {radius_meters} m dari outlet Anda. Absen akan dicek supervisor
                sebelum masuk ke Talenta.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
              {/* 1. Lokasi */}
              <section className="space-y-2">
                <h3 className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <MapPin className="h-4 w-4 text-indigo-500" /> 1. Lokasi
                </h3>
                {locating ? (
                  <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" /> Mencari lokasi…
                  </p>
                ) : position ? (
                  <p className="text-sm text-foreground">
                    Lokasi didapat <span className="text-muted-foreground">(akurasi ±{Math.round(position.accuracy)} m)</span>
                  </p>
                ) : geoError ? (
                  <p className="text-sm text-red-600 dark:text-red-400">{geoError}</p>
                ) : null}
                <Button variant="outline" size="sm" className="gap-1" onClick={locate} disabled={locating}>
                  <RefreshCw className="h-3.5 w-3.5" /> {position ? 'Perbarui lokasi' : 'Ambil lokasi'}
                </Button>
              </section>

              {/* 2. Foto */}
              <section className="space-y-2">
                <h3 className="flex items-center gap-2 text-sm font-medium text-foreground">
                  <Camera className="h-4 w-4 text-indigo-500" /> 2. Foto wajah
                </h3>
                {preview && (
                  <img
                    src={preview}
                    alt="Pratinjau foto absen"
                    className="max-h-72 w-full rounded-md border border-border object-contain"
                  />
                )}
                <input
                  ref={fileRef}
                  type="file"
                  accept="image/*"
                  capture="user"
                  className="hidden"
                  onChange={onPhotoPicked}
                />
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1"
                  onClick={() => fileRef.current?.click()}
                  disabled={preparing}
                >
                  {preparing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Camera className="h-3.5 w-3.5" />}
                  {preview ? 'Ambil ulang foto' : 'Buka kamera'}
                </Button>
              </section>

              {/* 3. Kirim */}
              <Button
                className="w-full gap-2"
                size="lg"
                onClick={submit}
                disabled={!position || !photo || submitting}
              >
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Kirim Absen
              </Button>

              {result && (
                <div
                  role="status"
                  className={`flex gap-2 rounded-md border p-3 text-sm ${
                    result.ok
                      ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200'
                      : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200'
                  }`}
                >
                  {result.ok && <CheckCircle2 className="h-4 w-4 shrink-0" />}
                  <span>{result.message}</span>
                </div>
              )}
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Riwayat absen foto</CardTitle>
          </CardHeader>
          <CardContent>
            {recent.length === 0 ? (
              <p className="text-sm text-muted-foreground">Belum ada absen foto.</p>
            ) : (
              <ul className="divide-y divide-border">
                {recent.map((log) => (
                  <li key={log.id} className="flex items-start justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-foreground">{log.timestamp}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {log.outlet_name || '-'}
                        {log.distance_meters != null && ` · ±${log.distance_meters} m`}
                      </p>
                      {log.review_note && (
                        <p className="text-xs text-muted-foreground">Catatan: {log.review_note}</p>
                      )}
                    </div>
                    <Badge variant={STATUS_VARIANT[log.status_sync] || 'secondary'}>
                      {STATUS_LABEL[log.status_sync] || log.status_sync}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </main>
    </div>
  )
}
