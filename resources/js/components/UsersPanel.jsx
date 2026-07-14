import React, { useState, useMemo } from 'react'
import { router, usePage } from '@inertiajs/react'
import { toast } from 'sonner'
import { confirmToast } from '@/lib/confirm'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Trash2, Plus, Pencil, ShieldCheck } from 'lucide-react'

function csrf() {
  return document.querySelector('meta[name="csrf-token"]').content
}

// Label & warna badge per role. Harus selaras dengan config/permissions.php.
const ROLE_META = {
  admin: { label: 'Admin', variant: 'success', desc: 'Akses penuh termasuk kelola user, pengaturan, & struktur organisasi. Tidak dibatasi outlet.' },
  manager: { label: 'Manager', variant: 'default', desc: 'Kelola karyawan & sidik jari, kirim ulang absensi. Tidak bisa mengubah mesin.' },
  operator: { label: 'Operator', variant: 'secondary', desc: 'Operasi harian & kirim absensi, tanpa hak menghapus.' },
  viewer: { label: 'Viewer', variant: 'outline', desc: 'Hanya melihat data, tidak bisa mengubah apa pun.' },
}
const roleLabel = (r) => ROLE_META[r]?.label ?? r

const emptyForm = {
  name: '',
  email: '',
  password: '',
  role: 'operator',
  // Scope: outlet mana saja yang boleh disentuh. Mencentang company/brand
  // otomatis mencakup semua yang di bawahnya (diturunkan di server).
  company_ids: [],
  brand_ids: [],
  outlet_ids: [],
}

export default function UsersPanel({ users = [], roles = ['admin', 'manager', 'operator', 'viewer'], companies = [] }) {
  const { props } = usePage()
  const currentUserId = props?.auth?.user?.id

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)

  // Admin tak pernah dibatasi outlet, jadi pemilih scope disembunyikan untuknya.
  const needsScope = formData.role !== 'admin'

  const openCreate = () => {
    setEditingId(null)
    setFormData(emptyForm)
    setShowForm(true)
  }

  const openEdit = (u) => {
    setEditingId(u.id)
    // Password dikosongkan saat edit; isi hanya bila ingin mengganti.
    setFormData({
      name: u.name ?? '',
      email: u.email ?? '',
      password: '',
      role: u.role ?? 'operator',
      company_ids: u.company_ids ?? [],
      brand_ids: u.brand_ids ?? [],
      outlet_ids: u.outlet_ids ?? [],
    })
    setShowForm(true)
  }

  // Centang/lepas satu simpul pohon organisasi.
  const toggleScope = (key, id) => {
    setFormData((prev) => {
      const list = prev[key] ?? []
      return {
        ...prev,
        [key]: list.includes(id) ? list.filter((x) => x !== id) : [...list, id],
      }
    })
  }

  // Ringkasan scope untuk kolom tabel.
  const scopeSummary = (u) => {
    if (u.role === 'admin') return 'Semua outlet'

    const count =
      (u.company_ids?.length ?? 0) + (u.brand_ids?.length ?? 0) + (u.outlet_ids?.length ?? 0)

    if (count === 0) return null

    const parts = []
    if (u.company_ids?.length) parts.push(`${u.company_ids.length} company`)
    if (u.brand_ids?.length) parts.push(`${u.brand_ids.length} brand`)
    if (u.outlet_ids?.length) parts.push(`${u.outlet_ids.length} outlet`)

    return parts.join(' · ')
  }

  const closeForm = () => {
    setFormData(emptyForm)
    setEditingId(null)
    setShowForm(false)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const url = editingId ? `/api/users/${editingId}` : '/api/users'
    const method = editingId ? 'PUT' : 'POST'

    const payload = {
      name: formData.name,
      email: formData.email,
      role: formData.role,
      // Admin tak punya scope; kirim kosong supaya sisa penugasan lama dibersihkan.
      company_ids: needsScope ? formData.company_ids : [],
      brand_ids: needsScope ? formData.brand_ids : [],
      outlet_ids: needsScope ? formData.outlet_ids : [],
    }
    // Saat create password wajib; saat edit hanya kirim bila diisi.
    if (!editingId || formData.password) payload.password = formData.password

    try {
      const res = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(payload),
      })
      if (res.ok) {
        toast.success(editingId ? 'User diperbarui.' : 'User ditambahkan.')
        closeForm()
        router.reload({ only: ['users'] })
      } else {
        const body = await res.json().catch(() => ({}))
        const msg = body?.errors
          ? Object.values(body.errors).flat().join('\n')
          : body.message || 'Gagal menyimpan user'
        toast.error(msg)
      }
    } catch (err) {
      console.error('Failed to save user:', err)
    }
  }

  const deleteUser = (u) => {
    confirmToast({
      message: `Hapus user "${u.name}"?`,
      description: u.email,
      confirmLabel: 'Hapus',
      destructive: true,
      onConfirm: () => runDeleteUser(u),
    })
  }

  const runDeleteUser = async (u) => {
    try {
      const res = await fetch(`/api/users/${u.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      })
      if (!res.ok) {
        const body = await res.json().catch(() => ({}))
        toast.error(body.message || 'Gagal menghapus user')
        return
      }
      toast.success('User dihapus.')
      router.reload({ only: ['users'] })
    } catch (err) {
      console.error('Failed to delete user:', err)
    }
  }

  const columns = useMemo(
    () => [
      {
        accessorKey: 'name',
        header: 'Nama',
        cell: ({ row }) => (
          <div className="flex items-center gap-2 font-medium">
            {row.getValue('name')}
            {row.original.id === currentUserId && (
              <Badge variant="outline" className="text-[10px]">Anda</Badge>
            )}
          </div>
        ),
      },
      {
        accessorKey: 'email',
        header: 'Email',
        cell: ({ row }) => <div className="text-sm text-slate-600 dark:text-slate-300">{row.getValue('email')}</div>,
      },
      {
        accessorKey: 'role',
        header: 'Role',
        cell: ({ row }) => {
          const r = row.getValue('role')
          return <Badge variant={ROLE_META[r]?.variant ?? 'secondary'}>{roleLabel(r)}</Badge>
        },
      },
      {
        id: 'scope',
        header: 'Wewenang Data',
        cell: ({ row }) => {
          const summary = scopeSummary(row.original)

          // Non-admin tanpa scope tak melihat data apa pun — beri peringatan,
          // karena dari luar akunnya tampak "kosong" seperti aplikasi rusak.
          if (!summary) {
            return (
              <span className="text-xs text-amber-600" title="User ini belum ditugaskan ke Company/Brand/Outlet mana pun, jadi tidak melihat data apa pun.">
                belum ditugaskan
              </span>
            )
          }

          return <span className="text-sm text-slate-600 dark:text-slate-300">{summary}</span>
        },
      },
      {
        id: 'actions',
        header: 'Aksi',
        cell: ({ row }) => (
          <div className="flex gap-2">
            <Button onClick={() => openEdit(row.original)} variant="outline" size="sm" className="gap-1">
              <Pencil className="h-3 w-3" />
              Edit
            </Button>
            <Button
              onClick={() => deleteUser(row.original)}
              variant="destructive"
              size="sm"
              className="gap-1"
              disabled={row.original.id === currentUserId}
              title={row.original.id === currentUserId ? 'Tidak bisa menghapus akun sendiri' : 'Hapus'}
            >
              <Trash2 className="h-3 w-3" />
              Hapus
            </Button>
          </div>
        ),
      },
    ],
    [currentUserId]
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-slate-500">
          Kelola akun pengguna aplikasi beserta perannya (role).
        </p>
        <Button onClick={showForm ? closeForm : openCreate} className="gap-2">
          <Plus className="h-4 w-4" />
          {showForm ? 'Cancel' : 'Tambah User'}
        </Button>
      </div>

      {showForm && (
        <Card>
          <CardHeader>
            <CardTitle>{editingId ? 'Edit User' : 'Tambah User'}</CardTitle>
            <CardDescription>
              {editingId
                ? 'Kosongkan password bila tidak ingin menggantinya.'
                : 'Password minimal 8 karakter.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Nama</label>
                  <Input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Budi Santoso"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Email</label>
                  <Input
                    type="email"
                    required
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="e.g., user@adms.local"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Password {editingId && <span className="text-slate-400">(opsional)</span>}
                  </label>
                  <Input
                    type="password"
                    required={!editingId}
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder={editingId ? '•••••••• (kosongkan untuk tetap)' : 'Min. 8 karakter'}
                    autoComplete="new-password"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">Role</label>
                  <Select
                    value={formData.role}
                    onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                  >
                    {roles.map((r) => (
                      <option key={r} value={r}>{roleLabel(r)}</option>
                    ))}
                  </Select>
                  <p className="text-xs text-slate-500">{ROLE_META[formData.role]?.desc}</p>
                </div>
              </div>

              {/* Wewenang data: Company > Brand > Outlet.
                  Mencentang company/brand otomatis mencakup semua di bawahnya —
                  jadi outlet baru di brand itu langsung ikut, tanpa perlu diedit. */}
              {needsScope && (
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    Wewenang Data
                  </label>
                  <p className="text-xs text-slate-500">
                    Pilih Company / Brand / Outlet yang boleh diakses. Mencentang Company
                    mencakup seluruh Brand &amp; Outlet di bawahnya. Tanpa satu pun centang,
                    user ini tidak akan melihat data apa pun.
                  </p>

                  <div className="max-h-72 space-y-3 overflow-y-auto rounded-md border border-slate-200 p-3 dark:border-slate-700">
                    {companies.length === 0 && (
                      <p className="text-sm text-slate-400">
                        Belum ada struktur organisasi. Buat Company / Brand / Outlet dulu di halaman Employees.
                      </p>
                    )}

                    {companies.map((company) => {
                      const companyChecked = formData.company_ids.includes(company.id)

                      return (
                        <div key={company.id} className="space-y-1">
                          <label className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-50">
                            <input
                              type="checkbox"
                              className="h-4 w-4"
                              checked={companyChecked}
                              onChange={() => toggleScope('company_ids', company.id)}
                            />
                            {company.name}
                          </label>

                          {(company.brands ?? []).map((brand) => {
                            // Sudah tercakup company -> centangnya dikunci, biar
                            // tak ada dua sumber kebenaran yang membingungkan.
                            const brandChecked = companyChecked || formData.brand_ids.includes(brand.id)

                            return (
                              <div key={brand.id} className="ml-5 space-y-1">
                                <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200">
                                  <input
                                    type="checkbox"
                                    className="h-4 w-4"
                                    checked={brandChecked}
                                    disabled={companyChecked}
                                    onChange={() => toggleScope('brand_ids', brand.id)}
                                  />
                                  {brand.name}
                                  {companyChecked && (
                                    <span className="text-[11px] text-slate-400">(ikut company)</span>
                                  )}
                                </label>

                                {(brand.outlets ?? []).map((outlet) => {
                                  const outletChecked = brandChecked || formData.outlet_ids.includes(outlet.id)

                                  return (
                                    <label
                                      key={outlet.id}
                                      className="ml-5 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"
                                    >
                                      <input
                                        type="checkbox"
                                        className="h-4 w-4"
                                        checked={outletChecked}
                                        disabled={brandChecked}
                                        onChange={() => toggleScope('outlet_ids', outlet.id)}
                                      />
                                      {outlet.name}
                                      {brandChecked && (
                                        <span className="text-[11px] text-slate-400">
                                          (ikut {companyChecked ? 'company' : 'brand'})
                                        </span>
                                      )}
                                    </label>
                                  )
                                })}
                              </div>
                            )
                          })}
                        </div>
                      )
                    })}
                  </div>
                </div>
              )}

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
            <ShieldCheck className="h-5 w-5 text-indigo-500" />
            Daftar User
          </CardTitle>
          <CardDescription>{users.length} user terdaftar</CardDescription>
        </CardHeader>
        <CardContent>
          <DataTable columns={columns} data={users} filterPlaceholder="Cari nama / email..." />
        </CardContent>
      </Card>
    </div>
  )
}
