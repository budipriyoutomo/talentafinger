import React, { useMemo, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Info, KeyRound, Lock, Pencil, Plus, Trash2 } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Kelompok checkbox permission berdasarkan prefiks key-nya.
const GROUPS = [
  { prefix: 'attendance.', label: 'Absensi' },
  { prefix: 'employee.', label: 'Karyawan' },
  { prefix: 'fingerprint.', label: 'Sidik Jari' },
  { prefix: 'machine.', label: 'Mesin' },
  { prefix: 'data.', label: 'Cakupan Data' },
  { prefix: '', label: 'Administrasi' },
]
const groupOf = (key) => GROUPS.find((g) => g.prefix && key.startsWith(g.prefix)) ?? GROUPS[GROUPS.length - 1]

const emptyForm = { key: '', name: '', description: '', permissions: [] }

/**
 * Kelola role & permission-nya. `permissionCatalog` = [{ key, label, grantable }]:
 * permission yang tak dimiliki viewer tak bisa dicentang (server memeriksa ulang).
 * Role sistem (admin, karyawan) terkunci.
 */
export default function RolesPanel({ roles = [], permissionCatalog = [] }) {
  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)

  const labelOf = (key) => permissionCatalog.find((p) => p.key === key)?.label ?? key

  const grouped = useMemo(
    () => GROUPS.map((g) => ({ ...g, items: permissionCatalog.filter((p) => groupOf(p.key) === g) }))
      .filter((g) => g.items.length),
    [permissionCatalog]
  )

  const openCreate = () => {
    setEditingId(null)
    setFormData(emptyForm)
    setShowForm(true)
  }

  const openEdit = (r) => {
    setEditingId(r.id)
    setFormData({ key: r.key, name: r.name, description: r.description ?? '', permissions: r.permissions })
    setShowForm(true)
  }

  const closeForm = () => {
    setEditingId(null)
    setFormData(emptyForm)
    setShowForm(false)
  }

  const requiresOf = (key) => permissionCatalog.find((p) => p.key === key)?.requires ?? []

  // Mencentang ikut mencentang prasyaratnya; melepas prasyarat ikut melepas
  // permission yang bergantung padanya (mis. lepas "Lihat log" -> review ikut lepas).
  // Server tetap menambahkan prasyarat saat simpan (Role::withRequirements).
  const withRequired = (list) => {
    const result = [...list]
    for (let i = 0; i < result.length; i++) {
      for (const need of requiresOf(result[i])) {
        if (!result.includes(need)) result.push(need)
      }
    }
    return result
  }

  const withoutDependents = (list, removed) => {
    let result = list.filter((p) => !removed.includes(p))
    const dropped = []
    let orphan
    while ((orphan = result.find((p) => requiresOf(p).some((need) => !result.includes(need))))) {
      dropped.push(orphan)
      result = result.filter((p) => p !== orphan)
    }
    return { result, dropped }
  }

  const togglePermission = (key) => {
    const current = formData.permissions
    if (current.includes(key)) {
      const { result, dropped } = withoutDependents(current, [key])
      if (dropped.length) toast.info(`Ikut dilepas karena butuh "${labelOf(key)}": ${dropped.map(labelOf).join(', ')}`)
      setFormData((prev) => ({ ...prev, permissions: result }))
    } else {
      const result = withRequired([...current, key])
      const added = result.filter((p) => p !== key && !current.includes(p))
      if (added.length) toast.info(`Ikut dicentang karena wajib: ${added.map(labelOf).join(', ')}`)
      setFormData((prev) => ({ ...prev, permissions: result }))
    }
  }

  const toggleGroup = (items, checkAll) => {
    const keys = items.filter((p) => p.grantable).map((p) => p.key)
    setFormData((prev) => ({
      ...prev,
      permissions: checkAll
        ? withRequired([...new Set([...prev.permissions, ...keys])])
        : withoutDependents(prev.permissions, keys).result,
    }))
  }

  // Template: salin permission role lain (yang boleh diberikan viewer) sebagai titik awal.
  const applyTemplate = (role) => {
    const grantable = permissionCatalog.filter((p) => p.grantable).map((p) => p.key)
    const source = role.permissions.includes('*') ? grantable : role.permissions
    setFormData((prev) => ({ ...prev, permissions: withRequired(source.filter((p) => grantable.includes(p))) }))
    toast.success(`Permission disalin dari role "${role.name}". Silakan sesuaikan.`)
  }

  const templateRoles = roles.filter((r) => r.key !== 'employee' && r.id !== editingId)

  const send = async (url, method, body) => {
    const res = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: body ? JSON.stringify(body) : undefined,
    })
    if (res.ok) return true
    const data = await res.json().catch(() => ({}))
    toast.error(data?.errors ? Object.values(data.errors).flat().join('\n') : data.message || 'Gagal menyimpan role')
    return false
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const payload = {
      name: formData.name,
      description: formData.description || null,
      permissions: formData.permissions,
      ...(editingId ? {} : { key: formData.key }),
    }
    const ok = await send(editingId ? `/api/roles/${editingId}` : '/api/roles', editingId ? 'PUT' : 'POST', payload)
    if (ok) {
      toast.success(editingId ? 'Role diperbarui.' : 'Role ditambahkan.')
      closeForm()
      router.reload({ only: ['roles'] })
    }
  }

  const deleteRole = (r) => {
    confirmToast({
      message: `Hapus role "${r.name}"?`,
      description: r.key,
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: async () => {
        if (await send(`/api/roles/${r.id}`, 'DELETE')) {
          toast.success('Role dihapus.')
          router.reload({ only: ['roles'] })
        }
      },
    })
  }

  const columns = useMemo(
    () => [
      {
        accessorKey: 'name',
        header: 'Role',
        cell: ({ row }) => (
          <div className="space-y-0.5">
            <div className="flex flex-wrap items-center gap-1.5 font-medium">
              {row.original.name}
              {row.original.is_system && (
                <Badge variant="outline" className="gap-1 text-[10px]"><Lock className="h-3 w-3" />sistem</Badge>
              )}
              {row.original.full_data_access && (
                <Badge variant="info" className="text-[10px]">semua outlet</Badge>
              )}
            </div>
            <div className="font-mono text-xs text-muted-foreground">{row.original.key}</div>
          </div>
        ),
      },
      {
        id: 'permissions',
        header: 'Permission',
        cell: ({ row }) => {
          const list = row.original.permissions
          if (list.includes('*')) return <span className="text-sm">Semua permission</span>
          if (!list.length) return <span className="text-sm text-muted-foreground">Belum ada</span>
          return (
            <div className="flex max-w-md flex-wrap gap-1" title={list.map(labelOf).join('\n')}>
              {list.slice(0, 3).map((p) => (
                <Badge key={p} variant="secondary" className="text-[11px] font-normal">{labelOf(p)}</Badge>
              ))}
              {list.length > 3 && (
                <Badge variant="outline" className="text-[11px] font-normal">+{list.length - 3} lainnya</Badge>
              )}
            </div>
          )
        },
      },
      {
        accessorKey: 'users_count',
        header: 'User',
        cell: ({ row }) => <span className="text-sm">{row.original.users_count}</span>,
      },
      {
        id: 'actions',
        header: 'Aksi',
        cell: ({ row }) => {
          const r = row.original
          const lockedReason = r.is_system ? 'Role sistem tidak bisa diubah' : 'Di luar wewenang Anda'
          return (
            <div className="flex gap-2">
              <Button
                onClick={() => openEdit(r)}
                variant="outline"
                size="sm"
                className="gap-1"
                disabled={!r.editable}
                title={r.editable ? 'Edit' : lockedReason}
              >
                <Pencil className="h-3 w-3" /> Edit
              </Button>
              <Button
                onClick={() => deleteRole(r)}
                variant="destructive"
                size="sm"
                className="gap-1"
                disabled={!r.editable || r.users_count > 0}
                title={!r.editable ? lockedReason : r.users_count > 0 ? 'Masih dipakai user' : 'Hapus'}
              >
                <Trash2 className="h-3 w-3" /> Hapus
              </Button>
            </div>
          )
        },
      },
    ],
    [permissionCatalog]
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Atur role dan permission-nya. Batas outlet tiap user tetap diatur di tab User.
        </p>
        <Button onClick={showForm ? closeForm : openCreate} className="gap-2">
          <Plus className="h-4 w-4" />
          {showForm ? 'Cancel' : 'Tambah Role'}
        </Button>
      </div>

      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle>{editingId ? 'Edit Role' : 'Tambah Role'}</CardTitle>
            <CardDescription>
              Role = paket permission yang bisa dipasang ke banyak user sekaligus.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="mb-4 space-y-1.5 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-200">
              <div className="flex items-center gap-1.5 font-semibold">
                <Info className="h-4 w-4" /> Akses user ditentukan 2 hal:
              </div>
              <ol className="ml-5 list-decimal space-y-0.5">
                <li><b>Permission</b> (di sini): boleh melakukan <i>apa</i> — menu &amp; tombol yang muncul.</li>
                <li>
                  <b>Outlet</b> (tab User, per user): data outlet <i>mana</i> yang boleh dilihat. Lewati ini dengan
                  mencentang <b>Akses data semua outlet</b> (mis. HR Pusat).
                </li>
              </ol>
              <p className="text-xs opacity-80">
                Contoh supervisor review absen foto: centang <b>Setujui / tolak absen manual</b> (Lihat log ikut
                otomatis), lalu di tab User tugaskan outlet yang ia pegang. Permission yang Anda sendiri tidak punya
                tampil pudar.
              </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <label className="text-sm font-medium">Nama</label>
                  <Input
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., HR Pusat"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium">
                    Key {editingId && <span className="text-muted-foreground">(tidak bisa diubah)</span>}
                  </label>
                  <Input
                    required
                    disabled={!!editingId}
                    value={formData.key}
                    onChange={(e) => setFormData({ ...formData, key: e.target.value.toLowerCase() })}
                    placeholder="e.g., hr_pusat"
                    className="font-mono"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Deskripsi</label>
                <Input
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Ditampilkan saat memilih role untuk user"
                />
              </div>

              <div className="space-y-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <label className="text-sm font-medium">
                    Permission{' '}
                    <span className="font-normal text-muted-foreground">
                      ({formData.permissions.length} dipilih)
                    </span>
                  </label>
                  {templateRoles.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5 text-xs">
                      <span className="text-muted-foreground">Salin dari role:</span>
                      {templateRoles.map((r) => (
                        <Button key={r.id} type="button" variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => applyTemplate(r)}>
                          {r.name}
                        </Button>
                      ))}
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        onClick={() => setFormData((prev) => ({ ...prev, permissions: [] }))}
                      >
                        Kosongkan
                      </Button>
                    </div>
                  )}
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {grouped.map((g) => {
                    const grantableItems = g.items.filter((p) => p.grantable)
                    const checkedCount = g.items.filter((p) => formData.permissions.includes(p.key)).length
                    const allChecked = grantableItems.length > 0 && grantableItems.every((p) => formData.permissions.includes(p.key))
                    return (
                      <div key={g.label} className="space-y-2 rounded-lg border border-border p-3">
                        <div className="flex items-center justify-between gap-2">
                          <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {g.label} <span className="font-normal normal-case">({checkedCount}/{g.items.length})</span>
                          </div>
                          {grantableItems.length > 1 && (
                            <button
                              type="button"
                              className="text-xs font-medium text-primary hover:underline"
                              onClick={() => toggleGroup(g.items, !allChecked)}
                            >
                              {allChecked ? 'Lepas semua' : 'Pilih semua'}
                            </button>
                          )}
                        </div>
                        {g.items.map((p) => {
                          const checked = formData.permissions.includes(p.key)
                          return (
                            <label
                              key={p.key}
                              className={`flex cursor-pointer items-start gap-2.5 rounded-md p-2 text-sm transition-colors ${
                                checked ? 'bg-primary/5' : 'hover:bg-muted/60'
                              } ${p.grantable ? '' : 'cursor-not-allowed opacity-50'}`}
                              title={p.grantable ? p.key : 'Anda sendiri tidak memiliki permission ini, jadi tidak bisa memberikannya'}
                            >
                              <Checkbox
                                className="mt-0.5"
                                checked={checked}
                                disabled={!p.grantable}
                                onChange={() => togglePermission(p.key)}
                              />
                              <span className="space-y-0.5">
                                <span className="block font-medium">{p.label}</span>
                                {p.description && (
                                  <span className="block text-xs text-muted-foreground">{p.description}</span>
                                )}
                                {p.requires?.length > 0 && (
                                  <span className="block text-[11px] text-amber-700 dark:text-amber-400">
                                    Otomatis ikut: {p.requires.map(labelOf).join(', ')}
                                  </span>
                                )}
                              </span>
                            </label>
                          )
                        })}
                      </div>
                    )
                  })}
                </div>
              </div>

              <div className="flex gap-2">
                <Button type="submit">{editingId ? 'Update' : 'Save'}</Button>
                <Button type="button" variant="outline" onClick={closeForm}>Cancel</Button>
              </div>
            </form>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <KeyRound className="h-5 w-5 text-indigo-500" />
            Daftar Role
          </CardTitle>
          <CardDescription>{roles.length} role</CardDescription>
        </CardHeader>
        <CardContent>
          <DataTable columns={columns} data={roles} filterPlaceholder="Cari role..." />
        </CardContent>
      </Card>
    </div>
  )
}
