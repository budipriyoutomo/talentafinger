import React, { useCallback, useEffect, useRef, useState } from 'react'
import { Head } from '@inertiajs/react'
import QRCode from 'qrcode'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import ThemeToggle from '@/components/ThemeToggle'
import { STATUS_LABEL, STATUS_VARIANT } from '@/components/ManualReviewDialog'
import { Loader2, Maximize, QrCode, ShieldAlert, Smartphone, WifiOff } from 'lucide-react'

const TOKEN_KEY = 'kiosk_token'
// Jeda coba lagi saat server tak terjangkau / absen QR dimatikan admin.
const RETRY_OFFLINE_MS = 5000
const RETRY_DISABLED_MS = 30000
const REQUEST_TIMEOUT_MS = 10000

// localStorage bisa melempar (mode privat / data situs diblokir): tablet tetap
// jalan, hanya saja harus diaktivasi ulang setelah dimuat ulang.
function readToken() {
  try { return window.localStorage.getItem(TOKEN_KEY) } catch { return null }
}
function writeToken(token) {
  try {
    if (token) window.localStorage.setItem(TOKEN_KEY, token)
    else window.localStorage.removeItem(TOKEN_KEY)
  } catch { /* abaikan */ }
}

async function fetchJson(url, options = {}) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
  try {
    const res = await fetch(url, {
      ...options,
      signal: controller.signal,
      headers: { Accept: 'application/json', ...(options.headers || {}) },
    })
    const data = await res.json().catch(() => ({}))
    return { res, data }
  } finally {
    clearTimeout(timer)
  }
}

// "2026-10-21 08:01:12" (jam dinding server) -> Date lokal dengan angka yang
// sama, supaya jam di layar mengikuti zona server, bukan setelan tablet.
function parseWallClock(text) {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(text || '')
  if (!m) return null
  return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6])
}

const pad = (n) => String(n).padStart(2, '0')

/** Layar penuh tetap menyala selama halaman terlihat (bila browser mendukung). */
function useWakeLock(enabled) {
  useEffect(() => {
    if (!enabled || !('wakeLock' in navigator)) return
    let lock = null
    let cancelled = false
    const request = async () => {
      try {
        lock = await navigator.wakeLock.request('screen')
      } catch { /* ditolak (mis. baterai hemat) -> abaikan */ }
      if (cancelled) lock?.release().catch(() => {})
    }
    const onVisible = () => document.visibilityState === 'visible' && request()
    request()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      cancelled = true
      document.removeEventListener('visibilitychange', onVisible)
      lock?.release().catch(() => {})
    }
  }, [enabled])
}

function ActivationForm({ onActivated }) {
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const submit = async (e) => {
    e.preventDefault()
    if (!code.trim()) return
    setBusy(true)
    setError(null)
    try {
      const { res, data } = await fetchJson('/kiosk/activate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code }),
      })
      if (res.ok && data.token) {
        onActivated(data.token)
      } else if (res.status === 429) {
        setError('Terlalu banyak percobaan. Tunggu 1 menit lalu coba lagi.')
      } else {
        setError(data.message || Object.values(data.errors || {})[0]?.[0] || 'Aktivasi gagal.')
      }
    } catch {
      setError('Tidak terhubung ke server. Periksa koneksi internet tablet.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-4">
      <form onSubmit={submit} className="w-full max-w-md space-y-5 rounded-xl border border-border bg-card p-6 shadow-sm">
        <div className="space-y-1 text-center">
          <QrCode className="mx-auto h-10 w-10 text-indigo-500" />
          <h1 className="text-xl font-semibold text-foreground">Aktivasi Kiosk Absen QR</h1>
          <p className="text-sm text-muted-foreground">
            Masukkan kode aktivasi dari admin (menu Kiosk QR). Kode berlaku 30 menit dan hanya sekali pakai.
          </p>
        </div>
        <Input
          autoFocus
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
          placeholder="ABCD-EFGH"
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={32}
          className="h-14 text-center font-mono text-2xl tracking-[0.3em]"
        />
        {error && <p role="alert" className="text-center text-sm text-red-600 dark:text-red-400">{error}</p>}
        <Button type="submit" size="lg" className="w-full gap-2" disabled={busy || !code.trim()}>
          {busy && <Loader2 className="h-4 w-4 animate-spin" />}
          Aktifkan Tablet
        </Button>
      </form>
    </div>
  )
}

function QrCanvas({ url, size }) {
  const ref = useRef(null)
  useEffect(() => {
    if (!ref.current || !url) return
    // Selalu hitam di atas putih (apa pun temanya) supaya kamera HP mudah membaca.
    QRCode.toCanvas(ref.current, url, {
      errorCorrectionLevel: 'M',
      margin: 2,
      width: size,
      color: { dark: '#000000', light: '#ffffff' },
    }).catch(() => {})
  }, [url, size])
  return <canvas ref={ref} width={size} height={size} className="block rounded-lg" aria-label="QR absen" />
}

function useQrSize() {
  const compute = () => Math.round(Math.max(200, Math.min(560, Math.min(window.innerWidth, window.innerHeight) * 0.55)))
  const [size, setSize] = useState(compute)
  useEffect(() => {
    const onResize = () => setSize(compute())
    window.addEventListener('resize', onResize)
    return () => window.removeEventListener('resize', onResize)
  }, [])
  return size
}

function KioskScreen({ token, onUnauthorized }) {
  const [state, setState] = useState(null) // respons /kiosk/state terakhir
  const [offline, setOffline] = useState(false)
  const [now, setNow] = useState(Date.now())
  const timing = useRef({ receivedAt: 0, expiresAt: 0, serverClock: null })
  const timer = useRef(null)
  const qrSize = useQrSize()

  useWakeLock(true)

  const poll = useCallback(async () => {
    clearTimeout(timer.current)
    let next = RETRY_OFFLINE_MS
    try {
      const { res, data } = await fetchJson('/kiosk/state', { headers: { 'X-Kiosk-Token': token } })
      if (res.status === 401) {
        onUnauthorized()
        return
      }
      if (!res.ok) throw new Error(`HTTP ${res.status}`)

      const receivedAt = Date.now()
      timing.current = {
        receivedAt,
        expiresAt: data.qr ? receivedAt + data.qr.expires_in * 1000 : 0,
        serverClock: parseWallClock(data.server_time),
      }
      setState(data)
      setOffline(false)
      // Ambil QR berikutnya tepat setelah window berganti.
      next = data.qr ? data.qr.expires_in * 1000 + 300 : RETRY_DISABLED_MS
    } catch {
      // Jangan tampilkan QR basi saat tak terhubung.
      setOffline(true)
    }
    timer.current = setTimeout(poll, next)
  }, [token, onUnauthorized])

  useEffect(() => {
    poll()
    return () => clearTimeout(timer.current)
  }, [poll])

  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 250)
    return () => clearInterval(id)
  }, [])

  const { receivedAt, expiresAt, serverClock } = timing.current
  const clock = serverClock ? new Date(serverClock.getTime() + (now - receivedAt)) : new Date(now)
  const remainingMs = Math.max(0, expiresAt - now)
  const rotateMs = (state?.rotate_seconds || 30) * 1000
  const progress = Math.min(100, (remainingMs / rotateMs) * 100)

  const fullscreen = () => {
    const el = document.documentElement
    if (document.fullscreenElement) document.exitFullscreen?.()
    else el.requestFullscreen?.().catch(() => {})
  }

  if (!state && !offline) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  const showQr = !offline && state?.enabled && state?.qr

  return (
    <div className="flex min-h-screen flex-col bg-background text-foreground">
      <header className="flex items-center justify-between gap-3 border-b border-border px-6 py-4">
        <div className="min-w-0">
          <p className="truncate text-2xl font-semibold">{state?.kiosk?.outlet_name || 'Kiosk Absen'}</p>
          <p className="truncate text-sm text-muted-foreground">{state?.kiosk?.name}</p>
        </div>
        <div className="flex items-center gap-2">
          <p className="font-mono text-4xl font-semibold tabular-nums">
            {pad(clock.getHours())}:{pad(clock.getMinutes())}
            <span className="text-2xl text-muted-foreground">:{pad(clock.getSeconds())}</span>
          </p>
          <ThemeToggle className="text-muted-foreground" />
          <Button variant="ghost" size="sm" onClick={fullscreen} title="Layar penuh" aria-label="Layar penuh">
            <Maximize className="h-4 w-4" />
          </Button>
        </div>
      </header>

      <main className="flex flex-1 flex-col items-center justify-center gap-6 p-6 lg:flex-row lg:items-center lg:justify-center lg:gap-12">
        <section className="flex flex-col items-center gap-4">
          {offline ? (
            <div className="flex flex-col items-center gap-3 rounded-xl border border-red-200 bg-red-50 p-10 text-center text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" style={{ width: qrSize }}>
              <WifiOff className="h-12 w-12" />
              <p className="text-lg font-semibold">Tidak terhubung ke server</p>
              <p className="text-sm">Mencoba lagi otomatis…</p>
            </div>
          ) : !state.enabled ? (
            <div className="flex flex-col items-center gap-3 rounded-xl border border-border bg-card p-10 text-center" style={{ width: qrSize }}>
              <ShieldAlert className="h-12 w-12 text-amber-500" />
              <p className="text-lg font-semibold">Absen QR sedang dinonaktifkan</p>
              <p className="text-sm text-muted-foreground">Hubungi admin bila ini tidak seharusnya.</p>
            </div>
          ) : showQr ? (
            <>
              <div className="rounded-xl bg-white p-3 shadow-lg">
                <QrCanvas url={state.qr.url} size={qrSize} />
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-muted" style={{ width: qrSize }}>
                <div className="h-full rounded-full bg-indigo-500 transition-[width] duration-200 ease-linear" style={{ width: `${progress}%` }} />
              </div>
              <p className="flex items-center gap-2 text-lg font-medium">
                <Smartphone className="h-5 w-5 text-indigo-500" />
                Scan pakai kamera HP Anda
              </p>
            </>
          ) : null}
        </section>

        <aside className="w-full max-w-sm rounded-xl border border-border bg-card p-4">
          <h2 className="mb-3 text-sm font-semibold text-muted-foreground">Absen QR hari ini</h2>
          {(state?.recent || []).length === 0 ? (
            <p className="text-sm text-muted-foreground">Belum ada.</p>
          ) : (
            <ul className="divide-y divide-border">
              {state.recent.map((r, i) => (
                <li key={`${r.time}-${r.name}-${i}`} className="flex items-center justify-between gap-3 py-2">
                  <span className="truncate font-medium">✓ {r.name}</span>
                  <span className="flex items-center gap-2">
                    <span className="font-mono text-sm tabular-nums text-muted-foreground">{r.time}</span>
                    <Badge variant={STATUS_VARIANT[r.status] || 'secondary'}>
                      {r.status === 'pending_review' ? 'menunggu review' : STATUS_LABEL[r.status] || r.status}
                    </Badge>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </aside>
      </main>
    </div>
  )
}

/**
 * Layar tablet kiosk absen QR di outlet. Publik (tanpa login user):
 * diaktivasi sekali dengan kode dari admin, lalu memakai token device yang
 * disimpan di localStorage. Tanpa layout admin, dirancang layar penuh.
 */
export default function Kiosk() {
  const [token, setToken] = useState(readToken)

  const onActivated = (next) => {
    writeToken(next)
    setToken(next)
  }

  // Token dicabut (reset aktivasi / kiosk dinonaktifkan) -> kembali ke form aktivasi.
  const onUnauthorized = useCallback(() => {
    writeToken(null)
    setToken(null)
  }, [])

  return (
    <>
      <Head title="Kiosk Absen QR" />
      {token
        ? <KioskScreen key={token} token={token} onUnauthorized={onUnauthorized} />
        : <ActivationForm onActivated={onActivated} />}
    </>
  )
}
