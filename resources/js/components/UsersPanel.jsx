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

// Warna badge untuk role bawaan; role buatan sendiri memakai 'secondary'.
// Nama & deskripsi role datang dari server (tabel roles).
const ROLE_VARIANT = {
  admin: 'success',
  manager: 'default',
  operator: 'secondary',
  viewer: 'outline',
  employee: 'info',
}

const emptyForm = {
  name: '',
  email: '',
  password: '',
  role: 'operator',
  is_active: true,
  // Hanya untuk role employee: karyawan pemilik akun ini.
  employee_id: '',
  // Scope: outlet mana saja yang boleh disentuh. Mencentang company/brand
  // otomatis mencakup semua yang di bawahnya (diturunkan di server).
  company_ids: [],
  brand_ids: [],
  outlet_ids: [],
}

// `roles` = objek role dari server: { key, name, description, assignable,
// full_data_access, ... }. `assignable` false = viewer tak boleh memberikannya
// (pagar anti-eskalasi; server tetap memeriksa ulang).
// `users` sudah disaring server ke user yang boleh dikelola viewer.
export default function UsersPanel({ users = [], roles = [], companies = [], employees = [] }) {
  const { props } = usePage()
  const currentUserId = props?.auth?.user?.id

  const [showForm, setShowForm] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [formData, setFormData] = useState(emptyForm)

  const roleOf = (key) => roles.find((r) => r.key === key)
  const roleLabel = (key) => roleOf(key)?.name ?? key
  const selectedRole = roleOf(formData.role)

  // Role tanpa batas outlet (admin, data.all) tak butuh scope, dan akun
  // karyawan tak melihat data outlet mana pun — pemilih scope disembunyikan.
  const isEmployeeRole = formData.role === 'employee'
  const needsScope = !selectedRole?.full_data_access && !isEmployeeRole

  // Karyawan yang sudah punya akun lain tak bisa dipilih lagi (1 karyawan = 1 akun).
  const linkedEmployeeIds = new Set(
    users.filter((u) => u.employee_id && u.id !== editingId).map((u) => u.employee_id)
  )
  const employeeName = (id) => employees.find((e) => e.id === id)?.name

  const openCreate = () => {
    setEditingId(null)
    // Default 'operator' bila boleh diberikan, selain itu role pertama yang boleh.
    const fallback = roleOf(emptyForm.role)?.assignable
      ? emptyForm.role
      : roles.find((r) => r.assignable)?.key ?? ''
    setFormData({ ...emptyForm, role: fallback })
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
      employee_id: u.employee_id ?? '',
      is_active: u.is_active ?? true,
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
    const linked = u.employee_id ? `Karyawan: ${employeeName(u.employee_id) || '?'}` : null
    if (u.role === 'employee') return linked
    if (roleOf(u.role)?.full_data_access) return ['Semua outlet', linked].filter(Boolean).join(' · ')

    const count =
      (u.company_ids?.length ?? 0) + (u.brand_ids?.length ?? 0) + (u.outlet_ids?.length ?? 0)

    if (count === 0) return linked

    const parts = []
    if (u.company_ids?.length) parts.push(`${u.company_ids.length} company`)
    if (u.brand_ids?.length) parts.push(`${u.brand_ids.length} brand`)
    if (u.outlet_ids?.length) parts.push(`${u.outlet_ids.length} outlet`)

    if (linked) parts.push(linked)

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
      is_active: formData.is_active,
      // Role apa pun boleh ditautkan ke karyawan (wajib untuk role Karyawan).
      employee_id: formData.employee_id || null,
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
        router.reload({ only: ['users', 'roles'] })
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
      router.reload({ only: ['users', 'roles'] })
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
            {row.original.must_change_password && (
              <Badge variant="warning" className="text-[10px]" title="Belum mengganti password sementara dari admin">
                password sementara
              </Badge>
            )}
            {row.original.is_active === false && (
              <Badge variant="destructive" className="text-[10px]" title="Tidak bisa login">nonaktif</Badge>
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
          return <Badge variant={ROLE_VARIANT[r] ?? 'secondary'}>{roleLabel(r)}</Badge>
        },
      },
      {
        accessorKey: 'last_login_at',
        header: 'Login Terakhir',
        cell: ({ row }) =>
          row.original.last_login_at ? (
            <div className="text-xs" title={row.original.last_login_ip ? `IP ${row.original.last_login_ip}` : undefined}>
              <div className="font-mono">{row.original.last_login_at}</div>
              {row.original.last_login_ip && (
                <div className="font-mono text-muted-foreground">{row.original.last_login_ip}</div>
              )}
            </div>
          ) : (
            <span className="text-xs text-muted-foreground">belum pernah</span>
          ),
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
    [currentUserId, employees, roles]
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
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
                ? 'Kosongkan password bila tidak ingin menggantinya. Password baru bersifat sementara: user wajib menggantinya saat login berikutnya.'
                : 'Password minimal 8 karakter, berisi huruf & angka. Bersifat sementara: user wajib menggantinya saat login pertama.'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground">Nama</label>
                  <Input
                    type="text"
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Budi Santoso"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground">Email</label>
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
                  <label className="text-sm font-medium text-foreground">
                    Password {editingId && <span className="text-muted-foreground">(opsional)</span>}
                  </label>
                  <Input
                    type="password"
                    required={!editingId}
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder={editingId ? '•••••••• (kosongkan untuk tetap)' : 'Min. 8 karakter, huruf & angka'}
                    autoComplete="new-password"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground">Role</label>
                  <Select
                    value={formData.role}
                    onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                  >
                    {/* Role yang tak boleh diberikan viewer disembunyikan, kecuali
                        role user ini sekarang (ditampilkan terkunci). */}
                    {roles
                      .filter((r) => r.assignable || r.key === formData.role)
                      .map((r) => (
                        <option key={r.key} value={r.key} disabled={!r.assignable}>{r.name}</option>
                      ))}
                  </Select>
                  <p className="text-xs text-muted-foreground">{selectedRole?.description}</p>
                </div>
              </div>

              <label className="flex items-start gap-2 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5 h-4 w-4"
                  checked={formData.is_active}
                  disabled={editingId === currentUserId}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                />
                <span>
                  <span className="font-medium">Akun aktif</span>
                  <span className="block text-xs text-muted-foreground">
                    Nonaktif = tidak bisa login dan sesinya langsung terputus. Data & histori tetap tersimpan.
                  </span>
                </span>
              </label>

              <div className="space-y-2">
                <label className="text-sm font-medium text-foreground">
                  {isEmployeeRole ? 'Karyawan' : 'Juga karyawan? (opsional)'}
                </label>
                <Select
                  required={isEmployeeRole}
                  value={formData.employee_id}
                  onChange={(e) => setFormData({ ...formData, employee_id: e.target.value })}
                >
                  <option value="">{isEmployeeRole ? '— Pilih karyawan —' : '— Bukan karyawan —'}</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={emp.id} disabled={linkedEmployeeIds.has(emp.id)}>
                      {emp.name}
                      {emp.biometric_id ? ` (PIN ${emp.biometric_id})` : ''}
                      {linkedEmployeeIds.has(emp.id) ? ' — sudah punya akun' : ''}
                      {!emp.allow_manual_attendance ? ' — belum diizinkan absen foto' : ''}
                    </option>
                  ))}
                </Select>
                <p className="text-xs text-muted-foreground">
                  {!isEmployeeRole && 'Tautkan bila user ini juga karyawan: ia tetap memakai role di atas, plus bisa absen QR / foto seperti role Karyawan. '}
                  Absen foto juga butuh izin absen foto di halaman Employees dan titik koordinat outlet.
                </p>
              </div>

              {/* Wewenang data: Company > Brand > Outlet.
                  Mencentang company/brand otomatis mencakup semua di bawahnya —
                  jadi outlet baru di brand itu langsung ikut, tanpa perlu diedit. */}
              {needsScope && (
                <div className="space-y-2">
                  <label className="text-sm font-medium text-foreground">
                    Wewenang Data
                  </label>
                  <p className="text-xs text-muted-foreground">
                    Pilih Company / Brand / Outlet yang boleh diakses. Mencentang Company
                    mencakup seluruh Brand &amp; Outlet di bawahnya. Tanpa satu pun centang,
                    user ini tidak akan melihat data apa pun.
                  </p>

                  <div className="max-h-72 space-y-3 overflow-y-auto rounded-md border border-slate-200 p-3 dark:border-slate-700">
                    {companies.length === 0 && (
                      <p className="text-sm text-muted-foreground">
                        Belum ada struktur organisasi. Buat Company / Brand / Outlet dulu di halaman Employees.
                      </p>
                    )}

                    {companies.map((company) => {
                      const companyChecked = formData.company_ids.includes(company.id)

                      return (
                        <div key={company.id} className="space-y-1">
                          <label className="flex items-center gap-2 text-sm font-semibold text-foreground">
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
                                    <span className="text-[11px] text-muted-foreground">(ikut company)</span>
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
                                        <span className="text-[11px] text-muted-foreground">
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
