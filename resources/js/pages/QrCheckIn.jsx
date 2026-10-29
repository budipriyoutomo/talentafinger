import React, { useEffect, useState } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import ThemeToggle from '@/components/ThemeToggle'
import { STATUS_LABEL, STATUS_VARIANT } from '@/components/ManualReviewDialog'
import LiveCamera, { cameraSupported } from '@/components/LiveCamera'
import QrScanner from '@/components/QrScanner'
import { useFreshLocation } from '@/lib/geolocation'
import {
  Camera, CheckCircle2, KeyRound, LayoutDashboard, Loader2, LogOut, MapPin, QrCode, RefreshCw, ScanLine, Send, ShieldAlert, Timer,
} from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Parameter token QR kiosk (lihat QrTokenService::urlFor).
const QR_PARAMS = ['k', 'w', 's']

/**
 * Teks QR -> URL relatif /absen/qr?k&w&s di origin halaman ini, atau null bila
 * bukan QR absen outlet. Origin QR sengaja diabaikan: session login ada di
 * origin halaman ini, dan tokennya tetap diverifikasi server.
 */
function scanUrlFrom(text) {
  let url
  try {
    url = new URL(text, window.location.origin)
  } catch {
    return null
  }
  if (!url.pathname.endsWith('/absen/qr') || !QR_PARAMS.every((p) => url.searchParams.get(p))) return null
  const query = new URLSearchParams(QR_PARAMS.map((p) => [p, url.searchParams.get(p)]))
  return `${window.location.pathname}?${query}`
}

const formatCountdown = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`

/** Sisa detik scan session, dihitung mundur dari nilai server saat halaman dimuat. */
function useCountdown(initial) {
  const [deadline, setDeadline] = useState(() => (initial ? Date.now() + initial * 1000 : 0))
  const [left, setLeft] = useState(initial || 0)

  useEffect(() => {
    setDeadline(initial ? Date.now() + initial * 1000 : 0)
    setLeft(initial || 0)
  }, [initial])

  useEffect(() => {
    if (!deadline) return
    const tick = () => setLeft(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)))
    tick()
    const id = setInterval(tick, 500)
    return () => clearInterval(id)
  }, [deadline])

  return left
}

/**
 * Halaman absen QR untuk akun KARYAWAN (role employee). Dibuka otomatis
 * setelah scan QR di tablet kiosk outlet, atau scan langsung dari halaman ini
 * (QrScanner) bila karyawan sudah login. Alur: lokasi -> selfie -> kirim ->
 * menunggu persetujuan supervisor.
 */
export default function QrCheckIn({
  employee, scan, qr_error, blocked_reason, radius_meters, max_accuracy_meters = 100, max_photo_kb, recent = [], can_manual_photo,
}) {
  const { position, locating, geoError, locate } = useFreshLocation()
  // Server menolak lokasi yang akurasinya lebih buruk dari batas ini.
  const inaccurate = !!position && Math.round(position.accuracy) > max_accuracy_meters

  const [photo, setPhoto] = useState(null) // Blob siap kirim
  const [preview, setPreview] = useState(null)
  const [cameraOpen, setCameraOpen] = useState(false)

  // Belum ada scan: scanner QR langsung dibuka begitu halaman tampil.
  const [scannerOpen, setScannerOpen] = useState(() => !scan && !blocked_reason && cameraSupported())
  const [scannerHint, setScannerHint] = useState(null)

  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState(null) // { ok, message }

  const secondsLeft = useCountdown(scan?.expires_in)
  const expired = !!scan && secondsLeft <= 0
  const active = !!scan && !blocked_reason

  // Langsung minta lokasi begitu ada scan yang bisa dipakai.
  useEffect(() => {
    if (active) locate()
  }, [active, locate])

  useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview])

  const onCaptured = (blob) => {
    setCameraOpen(false)
    setResult(null)
    if (max_photo_kb && blob.size > max_photo_kb * 1024) {
      setResult({ ok: false, message: `Foto terlalu besar (maks ${(max_photo_kb / 1024).toFixed(1)}MB). Coba ambil ulang.` })
      return
    }
    setPhoto(blob)
    setPreview(URL.createObjectURL(blob))
  }

  const submit = async () => {
    if (!position || !photo || expired) return
    setSubmitting(true)
    setResult(null)
    try {
      // Lokasi diambil ULANG tepat saat kirim (lihat ManualCheckIn).
      const fresh = await locate()
      if (!fresh) {
        setResult({ ok: false, message: 'Lokasi terbaru tidak terbaca. Coba lagi.' })
        return
      }
      const form = new FormData()
      form.append('photo', photo, 'absen.jpg')
      form.append('latitude', fresh.latitude)
      form.append('longitude', fresh.longitude)
      form.append('accuracy', Math.round(fresh.accuracy))
      const res = await fetch('/api/attendance/qr', {
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
        router.reload({ only: ['recent', 'scan'] })
      }
    } catch (err) {
      setResult({ ok: false, message: 'Gagal mengirim: ' + err.message })
    } finally {
      setSubmitting(false)
    }
  }

  const openScanner = () => {
    setScannerHint(null)
    setScannerOpen(true)
  }

  // false = bukan QR absen, scanner lanjut memindai.
  const onQrDetected = (text) => {
    const url = scanUrlFrom(text)
    if (!url) {
      setScannerHint('QR ini bukan QR absen outlet. Arahkan ke QR di tablet outlet.')
      return false
    }
    setScannerOpen(false)
    setResult(null)
    setPhoto(null)
    setPreview(null)
    router.visit(url)
  }

  const logout = () => router.post('/logout')
  // User role lain yang juga karyawan (mis. manager) bisa kembali ke admin panel.
  const canOpenPanel = usePage().props.auth?.user?.role !== 'employee'

  return (
    <div className="min-h-screen bg-background">
      <Head title="Absen QR" />

      <header className="sticky top-0 z-10 border-b border-border bg-card">
        <div className="mx-auto flex max-w-md items-center justify-between gap-2 px-4 py-3">
          <div className="min-w-0">
            <p className="text-xs text-muted-foreground">Absen QR</p>
            <p className="truncate font-semibold text-foreground">{employee?.name || 'Karyawan'}</p>
          </div>
          <div className="flex items-center gap-1">
            {can_manual_photo && (
              <Button asChild variant="ghost" size="sm" className="gap-1" title="Absen pakai foto (tanpa QR)">
                <Link href="/absen"><Camera className="h-4 w-4" /> Absen Foto</Link>
              </Button>
            )}
            {canOpenPanel && (
              <Button asChild variant="ghost" size="sm" title="Kembali ke dashboard">
                <Link href="/dashboard" aria-label="Dashboard">
                  <LayoutDashboard className="h-4 w-4" />
                </Link>
              </Button>
            )}
            <ThemeToggle className="text-muted-foreground" />
            <Button asChild variant="ghost" size="sm" title="Ganti password">
              <Link href="/password" aria-label="Ganti password">
                <KeyRound className="h-4 w-4" />
              </Link>
            </Button>
            <Button variant="ghost" size="sm" className="gap-1" onClick={logout}>
              <LogOut className="h-4 w-4" /> Keluar
            </Button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-md space-y-4 px-4 py-4">
        {qr_error && (
          <Card className="border-red-200 dark:border-red-900">
            <CardContent className="flex gap-3 pt-6">
              <ShieldAlert className="h-5 w-5 shrink-0 text-red-500" />
              <p className="text-sm text-red-800 dark:text-red-200">{qr_error}</p>
            </CardContent>
          </Card>
        )}

        {blocked_reason ? (
          <Card>
            <CardContent className="flex gap-3 pt-6">
              <ShieldAlert className="h-5 w-5 shrink-0 text-amber-500" />
              <p className="text-sm text-foreground">{blocked_reason}</p>
            </CardContent>
          </Card>
        ) : !scan ? (
          <Card>
            <CardContent className="flex flex-col items-center gap-3 pt-6 text-center">
              <ScanLine className="h-10 w-10 text-indigo-500" />
              <p className="font-medium text-foreground">Scan QR di layar tablet outlet.</p>
              {cameraSupported() && (
                <Button size="lg" className="gap-2" onClick={openScanner}>
                  <ScanLine className="h-4 w-4" /> Scan QR
                </Button>
              )}
              <p className="text-sm text-muted-foreground">
                QR berganti tiap beberapa detik, jadi scan langsung dari layar outlet, bukan dari foto.
                Bisa juga lewat aplikasi kamera HP.
              </p>
            </CardContent>
          </Card>
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <QrCode className="h-5 w-5 text-indigo-500" /> Absen di {scan.outlet_name}
              </CardTitle>
              <CardDescription>
                {scan.kiosk_name} · harus dalam radius {radius_meters} m dari outlet. Absen dicek supervisor
                sebelum masuk ke Talenta.
              </CardDescription>
              <p className={`flex items-center gap-1.5 text-sm font-medium ${
                expired ? 'text-red-600 dark:text-red-400' : secondsLeft <= 30 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'
              }`}>
                <Timer className="h-4 w-4" />
                {expired ? 'Waktu habis — scan ulang QR di layar outlet.' : `Sisa waktu ${formatCountdown(secondsLeft)}`}
              </p>
              {expired && cameraSupported() && (
                <Button size="sm" className="w-fit gap-1" onClick={openScanner}>
                  <ScanLine className="h-3.5 w-3.5" /> Scan ulang
                </Button>
              )}
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
                ) : position && inaccurate ? (
                  <p className="text-sm text-amber-600 dark:text-amber-400">
                    Sinyal lokasi kurang akurat (±{Math.round(position.accuracy)} m, maksimal ±{max_accuracy_meters} m).
                    Aktifkan GPS / mode akurasi tinggi, keluar ke area terbuka, lalu perbarui lokasi.
                  </p>
                ) : position ? (
                  <p className="text-sm text-foreground">
                    Lokasi didapat <span className="text-muted-foreground">(akurasi ±{Math.round(position.accuracy)} m)</span>
                  </p>
                ) : geoError ? (
                  <p className="text-sm text-red-600 dark:text-red-400">{geoError}</p>
                ) : null}
                <Button variant="outline" size="sm" className="gap-1" onClick={locate} disabled={locating || expired}>
                  <RefreshCw className="h-3.5 w-3.5" /> {position ? 'Perbarui lokasi' : 'Ambil lokasi'}
                </Button>
              </section>

              {/* 2. Selfie */}
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
                {cameraSupported() ? (
                  <Button variant="outline" size="sm" className="gap-1" onClick={() => setCameraOpen(true)} disabled={expired}>
                    <Camera className="h-3.5 w-3.5" />
                    {preview ? 'Ambil ulang foto' : 'Buka kamera'}
                  </Button>
                ) : (
                  <p className="text-sm text-red-600 dark:text-red-400">
                    Browser ini tidak bisa membuka kamera langsung. Gunakan Chrome / Safari versi terbaru
                    (alamat harus https://).
                  </p>
                )}
                {cameraOpen && <LiveCamera onCapture={onCaptured} onClose={() => setCameraOpen(false)} />}
              </section>

              {/* 3. Kirim */}
              <Button
                className="w-full gap-2"
                size="lg"
                onClick={submit}
                disabled={!position || inaccurate || !photo || submitting || expired}
              >
                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Kirim Absen
              </Button>
            </CardContent>
          </Card>
        )}

        {scannerOpen && (
          <QrScanner onDetected={onQrDetected} onClose={() => setScannerOpen(false)} hint={scannerHint} />
        )}

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

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Riwayat absen QR</CardTitle>
          </CardHeader>
          <CardContent>
            {recent.length === 0 ? (
              <p className="text-sm text-muted-foreground">Belum ada absen QR.</p>
            ) : (
              <ul className="divide-y divide-border">
                {recent.map((log) => (
                  <li key={log.id} className="flex items-start justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-foreground">{log.timestamp}</p>
                      <p className="truncate text-xs text-muted-foreground">
                        {log.outlet_name || '-'}
                        {log.kiosk_name && ` · ${log.kiosk_name}`}
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
