import React, { useEffect, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Database, Loader2, Save } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

const toForm = (s) => ({ name: s.name, description: s.description ?? '', sync_to_talenta: !!s.sync_to_talenta })

/**
 * Master sumber absensi (cocok AttendanceSource di server): sumber mana yang
 * log-nya dikirim ke Talenta. Sumber tak bisa ditambah/dihapus — kodenya
 * dihasilkan aplikasi (mesin ADMS, absen foto, QR).
 */
export default function AttendanceSourcesPanel({ sources = [] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Database className="h-5 w-5" /> Master Sumber Absensi
        </CardTitle>
        <CardDescription>
          Atur sumber absensi mana yang dikirim ke Talenta. Log dari sumber yang tidak di-sync tetap tersimpan
          di aplikasi dengan status <b>Tidak di-sync</b>, tapi tidak ikut auto-send maupun "Kirim Semua Pending".
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {sources.length === 0 && (
          <p className="text-sm text-muted-foreground">
            Belum ada data. Jalankan <code>php artisan migrate</code>.
          </p>
        )}
        {sources.map((s) => <SourceRow key={s.id} source={s} />)}
      </CardContent>
    </Card>
  )
}

function SourceRow({ source }) {
  const [form, setForm] = useState(toForm(source))
  const [saving, setSaving] = useState(false)

  // Setelah reload props, samakan form dengan data server.
  useEffect(() => setForm(toForm(source)), [source])

  const saved = toForm(source)
  const dirty = Object.keys(form).some((k) => form[k] !== saved[k])

  const submit = async (requeueSkipped = false) => {
    setSaving(true)
    try {
      const res = await fetch(`/api/attendance-sources/${source.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ ...form, description: form.description || null, requeue_skipped: requeueSkipped }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        toast.error(data?.errors ? Object.values(data.errors).flat().join('\n') : data.message || 'Gagal menyimpan')
        return
      }
      toast.success(data.message || 'Disimpan.')
      router.reload({ only: ['attendanceSources'] })
    } finally {
      setSaving(false)
    }
  }

  const save = () => {
    // Sync baru dinyalakan & ada log lama yang dilewati: tawarkan ikut dikirim.
    const turningOn = form.sync_to_talenta && !source.sync_to_talenta
    if (turningOn && source.skipped_count > 0) {
      confirmToast({
        message: `Kirim juga ${source.skipped_count} log lama "${form.name}"?`,
        description: 'Log yang dulu dilewati akan masuk antrean kirim ke Talenta. Pilih Batal untuk hanya log baru.',
        confirmLabel: 'Ya, antrekan',
        cancelLabel: 'Batal',
        onConfirm: () => submit(true),
      })
      // Simpan tanpa antre ulang tetap jalan bila user tak memilih "Ya".
      submit(false)
      return
    }
    submit(false)
  }

  return (
    <div className="rounded-lg border p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="font-mono text-xs text-muted-foreground">{source.code}</span>
          <Badge variant={source.sync_to_talenta ? 'success' : 'secondary'}>
            {source.sync_to_talenta ? 'Sync ke Talenta' : 'Tidak di-sync'}
          </Badge>
          {source.skipped_count > 0 && (
            <span className="text-xs text-muted-foreground">{source.skipped_count} log dilewati</span>
          )}
        </div>
        <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
          <Checkbox
            checked={form.sync_to_talenta}
            onChange={(e) => setForm((f) => ({ ...f, sync_to_talenta: e.target.checked }))}
          />
          Kirim ke Talenta
        </label>
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-[1fr_2fr_auto] md:items-end">
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Nama</label>
          <Input value={form.name} maxLength={100} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
        </div>
        <div className="space-y-1">
          <label className="text-xs text-muted-foreground">Deskripsi</label>
          <Input value={form.description} maxLength={255} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
        </div>
        <Button onClick={save} disabled={!dirty || saving || !form.name.trim()}>
          {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          Simpan
        </Button>
      </div>
    </div>
  )
}
