import React, { useMemo, useState } from 'react'
import { router } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { KeyRound, Lock, Pencil, Plus, Trash2 } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Kelompok checkbox permission berdasarkan prefiks key-nya.
const GROUPS = [
  { prefix: 'machine.', label: 'Mesin' },
  { prefix: 'attendance.', label: 'Absensi' },
  { prefix: 'employee.', label: 'Karyawan' },
  { prefix: 'fingerprint.', label: 'Sidik Jari' },
  { prefix: '', label: 'Administrasi & Akses Data' },
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

  const togglePermission = (key) => {
    setFormData((prev) => ({
      ...prev,
      permissions: prev.permissions.includes(key)
        ? prev.permissions.filter((p) => p !== key)
        : [...prev.permissions, key],
    }))
  }

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
          return (
            <span className="text-sm text-muted-foreground" title={list.map(labelOf).join('\n')}>
              {list.length} permission
            </span>
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
              Permission yang tidak Anda miliki tidak bisa diberikan. Centang "Akses data semua outlet"
              untuk role global (mis. HR Pusat).
            </CardDescription>
          </CardHeader>
          <CardContent>
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
                <label className="text-sm font-medium">Permission</label>
                <div className="grid grid-cols-1 gap-4 rounded-md border border-border p-3 md:grid-cols-2">
                  {grouped.map((g) => (
                    <div key={g.label} className="space-y-1.5">
                      <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{g.label}</div>
                      {g.items.map((p) => (
                        <label
                          key={p.key}
                          className={`flex items-start gap-2 text-sm ${p.grantable ? '' : 'opacity-50'}`}
                          title={p.grantable ? p.key : 'Anda tidak memiliki permission ini'}
                        >
                          <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4"
                            checked={formData.permissions.includes(p.key)}
                            disabled={!p.grantable}
                            onChange={() => togglePermission(p.key)}
                          />
                          <span>
                            {p.label}
                            <span className="ml-1 font-mono text-[11px] text-muted-foreground">{p.key}</span>
                          </span>
                        </label>
                      ))}
                    </div>
                  ))}
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
