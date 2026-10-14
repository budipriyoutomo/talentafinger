import React, { useEffect, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Settings as SettingsIcon, Plug, Cpu, SlidersHorizontal, Send, Clock, Save, Loader2, CheckCircle2, AlertCircle } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Metadata tampilan per grup (judul + ikon + deskripsi).
const GROUP_META = {
  talenta: {
    title: 'Integrasi Talenta (Mekari)',
    icon: Plug,
    description: 'Kredensial & endpoint untuk mengirim absensi ke Mekari Talenta.',
  },
  attendance: {
    title: 'Absensi (Auto-kirim & Absen Foto)',
    icon: Send,
    description: 'Jadwal pengiriman otomatis absensi ke Talenta (pengiriman manual tetap tersedia di halaman Logs), serta saklar & radius absen foto.',
  },
  machine: {
    title: 'Sinkronisasi Jam Mesin (Auto)',
    icon: Clock,
    description: 'Penyetelan jam mesin secara otomatis & terjadwal. Sinkron manual tetap tersedia di halaman Machines.',
  },
  adms: {
    title: 'Perangkat / ADMS',
    icon: Cpu,
    description: 'Pengaturan mesin ZKTeco & skrip sinkronisasi sidik jari.',
  },
  general: {
    title: 'Umum',
    icon: SlidersHorizontal,
    description: 'Preferensi umum aplikasi.',
  },
}

const GROUP_ORDER = ['talenta', 'attendance', 'machine', 'adms', 'general']

export default function AppSettingsPanel({ groups = {} }) {
  // Nilai form: { key: value }. Kosongkan dulu lalu isi dari props.
  const initial = {}
  Object.values(groups).forEach((items) =>
    items.forEach((s) => {
      initial[s.key] = s.type === 'boolean' ? s.value === '1' || s.value === true : s.value ?? ''
    })
  )

  const [form, setForm] = useState(initial)
  // Nilai terakhir yang tersimpan di server, pembanding untuk "belum disimpan".
  const [saved, setSaved] = useState(initial)
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState(null)

  const dirtyKeys = Object.keys(form).filter((k) => form[k] !== saved[k])
  const dirty = dirtyKeys.length > 0

  // Cegah perubahan hilang diam-diam: peringatkan saat menutup/refresh tab
  // atau pindah halaman lewat menu, selama masih ada yang belum disimpan.
  useEffect(() => {
    if (!dirty) return
    const onBeforeUnload = (e) => {
      e.preventDefault()
      e.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    const offBefore = router.on('before', (event) => {
      // Reload parsial di halaman yang sama (mis. tab User & Role) tak dihitung.
      if (event.detail.visit.url.pathname === window.location.pathname) return
      if (!window.confirm('Ada pengaturan yang belum disimpan. Tinggalkan halaman ini?')) {
        event.preventDefault()
      }
    })
    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload)
      offBefore()
    }
  }, [dirty])

  const setField = (key, value) => {
    setForm((f) => ({ ...f, [key]: value }))
    setSavedAt(null)
  }

  const save = async () => {
    setSaving(true)
    try {
      const payload = {}
      Object.entries(form).forEach(([k, v]) => {
        payload[k] = typeof v === 'boolean' ? (v ? '1' : '0') : v
      })

      const res = await fetch('/api/settings', {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ settings: payload }),
      })

      if (!res.ok) {
        const b = await res.json().catch(() => ({}))
        const msg = b?.errors ? Object.values(b.errors).flat().join('\n') : b.message || 'Gagal menyimpan pengaturan'
        toast.error(msg)
        return
      }

      toast.success('Pengaturan disimpan.')
      setSavedAt(Date.now())
      // Password tak pernah dikembalikan ke browser: kosongkan lagi field-nya
      // supaya kondisi form = kondisi tersimpan.
      const next = { ...form }
      Object.values(groups).forEach((items) =>
        items.forEach((s) => {
          if (s.type === 'password') next[s.key] = ''
        })
      )
      setForm(next)
      setSaved(next)
    } finally {
      setSaving(false)
    }
  }

  const renderField = (s) => {
    if (s.type === 'boolean') {
      return (
        <label className="flex items-center gap-2 cursor-pointer select-none">
          <input
            type="checkbox"
            checked={!!form[s.key]}
            onChange={(e) => setField(s.key, e.target.checked)}
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
          />
          <span className="text-sm text-slate-600 dark:text-slate-300">Aktif</span>
        </label>
      )
    }

    const inputType =
      s.type === 'password' ? 'password' : s.type === 'number' ? 'number' : s.type === 'time' ? 'time' : 'text'

    const passwordPlaceholder =
      s.type === 'password' && s.is_set
        ? `${s.preview ?? '••••••••'} · tersimpan, kosongkan untuk tetap`
        : ''

    return (
      <Input
        type={inputType}
        value={form[s.key] ?? ''}
        placeholder={passwordPlaceholder}
        onChange={(e) => setField(s.key, e.target.value)}
        className={s.type === 'time' ? 'sm:w-40' : undefined}
      />
    )
  }

  const orderedGroups = GROUP_ORDER.filter((g) => groups[g]?.length)

  return (
    <div className="space-y-6">
      {/* Menempel di atas saat di-scroll: tombol Simpan selalu terlihat dari kartu mana pun. */}
      <div className="sticky top-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-3 rounded-md bg-background/95 px-1 py-2 backdrop-blur">
        <p className="text-sm text-muted-foreground">
          Nilai di sini menimpa konfigurasi <code>.env</code>. Kosongkan sebuah field untuk kembali memakai nilai default <code>.env</code>.
        </p>
        <div className="flex items-center gap-3">
          {dirty && (
            <span className="flex items-center gap-1 text-sm text-amber-600 dark:text-amber-400">
              <AlertCircle className="h-4 w-4" /> {dirtyKeys.length} perubahan belum disimpan
            </span>
          )}
          {!dirty && savedAt && (
            <span className="flex items-center gap-1 text-sm text-emerald-600">
              <CheckCircle2 className="h-4 w-4" /> Tersimpan
            </span>
          )}
          <Button onClick={save} disabled={saving} className="gap-2">
            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            Simpan
          </Button>
        </div>
      </div>

      {orderedGroups.map((g) => {
        const meta = GROUP_META[g] || { title: g, icon: SettingsIcon, description: '' }
        const Icon = meta.icon
        return (
          <Card key={g}>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon className="h-5 w-5 text-indigo-500" />
                {meta.title}
              </CardTitle>
              {meta.description && <CardDescription>{meta.description}</CardDescription>}
            </CardHeader>
            <CardContent className="space-y-5">
              {groups[g].map((s) => (
                <div
                  key={s.key}
                  className={`grid gap-1.5 sm:grid-cols-3 sm:items-start sm:gap-4 ${
                    dirtyKeys.includes(s.key) ? '-mx-2 rounded-md bg-amber-50 px-2 py-1 dark:bg-amber-950/40' : ''
                  }`}
                >
                  <div className="sm:pt-2">
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200 flex items-center gap-2">
                      {s.label}
                      {s.type === 'password' && s.is_set && (
                        <Badge variant="secondary" className="text-[10px]">terisi</Badge>
                      )}
                    </label>
                    <code className="text-[11px] text-muted-foreground">{s.key}</code>
                  </div>
                  <div className="sm:col-span-2 space-y-1">
                    {renderField(s)}
                    {s.description && <p className="text-xs text-muted-foreground">{s.description}</p>}
                  </div>
                </div>
              ))}
            </CardContent>
          </Card>
        )
      })}
    </div>
  )
}
