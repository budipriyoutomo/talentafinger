import React, { useState } from 'react'
import { toast } from 'sonner'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Settings as SettingsIcon, Plug, Cpu, SlidersHorizontal, Send, Save, Loader2, CheckCircle2 } from 'lucide-react'

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
    title: 'Pengiriman Absensi (Auto-kirim)',
    icon: Send,
    description: 'Jadwal pengiriman otomatis absensi ke Talenta. Pengiriman manual tetap tersedia di halaman Logs.',
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

const GROUP_ORDER = ['talenta', 'attendance', 'adms', 'general']

export default function AppSettingsPanel({ groups = {} }) {
  // Nilai form: { key: value }. Kosongkan dulu lalu isi dari props.
  const initial = {}
  Object.values(groups).forEach((items) =>
    items.forEach((s) => {
      initial[s.key] = s.type === 'boolean' ? s.value === '1' || s.value === true : s.value ?? ''
    })
  )

  const [form, setForm] = useState(initial)
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState(null)

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

    return (
      <Input
        type={inputType}
        value={form[s.key] ?? ''}
        placeholder={s.type === 'password' && s.is_set ? '•••••••• (tersimpan, kosongkan untuk tetap)' : ''}
        onChange={(e) => setField(s.key, e.target.value)}
        className={s.type === 'time' ? 'sm:w-40' : undefined}
      />
    )
  }

  const orderedGroups = GROUP_ORDER.filter((g) => groups[g]?.length)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          Nilai di sini menimpa konfigurasi <code>.env</code>. Kosongkan sebuah field untuk kembali memakai nilai default <code>.env</code>.
        </p>
        <div className="flex items-center gap-3">
          {savedAt && (
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
                <div key={s.key} className="grid gap-1.5 sm:grid-cols-3 sm:items-start sm:gap-4">
                  <div className="sm:pt-2">
                    <label className="text-sm font-medium text-slate-700 dark:text-slate-200 flex items-center gap-2">
                      {s.label}
                      {s.type === 'password' && s.is_set && (
                        <Badge variant="secondary" className="text-[10px]">terisi</Badge>
                      )}
                    </label>
                    <code className="text-[11px] text-slate-400">{s.key}</code>
                  </div>
                  <div className="sm:col-span-2 space-y-1">
                    {renderField(s)}
                    {s.description && <p className="text-xs text-slate-500">{s.description}</p>}
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
