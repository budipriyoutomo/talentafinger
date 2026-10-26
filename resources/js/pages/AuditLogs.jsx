import React, { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, History, X } from 'lucide-react'

// Label manusia untuk tiap aksi. Aksi yang belum terdaftar tampil apa adanya.
const ACTION_LABEL = {
  'auth.login': 'Login',
  'auth.login_failed': 'Login gagal',
  'auth.login_locked': 'Login terkunci (terlalu banyak percobaan)',
  'auth.login_blocked': 'Login ditolak (akun nonaktif)',
  'auth.logout': 'Logout',
  'auth.password_changed': 'Ganti password',
  'user.created': 'User dibuat',
  'user.updated': 'User diubah',
  'user.deleted': 'User dihapus',
  'role.created': 'Role dibuat',
  'role.updated': 'Role diubah',
  'role.deleted': 'Role dihapus',
  'setting.updated': 'Pengaturan diubah',
  'machine.created': 'Mesin ditambah',
  'machine.updated': 'Mesin diubah',
  'machine.deleted': 'Mesin dihapus',
  'machine.device_user_deleted': 'User di mesin dihapus',
  'machine.attendance_cleared': 'Log absensi di mesin dikosongkan',
  'kiosk.created': 'Kiosk QR ditambah',
  'kiosk.updated': 'Kiosk QR diubah',
  'kiosk.reset': 'Aktivasi kiosk QR direset',
  'kiosk.deleted': 'Kiosk QR dihapus',
  'kiosk.activated': 'Tablet kiosk QR diaktivasi',
  'employee.deleted': 'Karyawan dihapus',
  'attendance.sent': 'Kirim 1 log ke Talenta',
  'attendance.send_pending': 'Kirim semua pending ke Talenta',
  'attendance.resend': 'Kirim ulang ke Talenta',
  'attendance.manual_approved': 'Absen foto/QR disetujui',
  'attendance.manual_rejected': 'Absen foto/QR ditolak',
  'org.company_deleted': 'Company dihapus',
  'org.brand_deleted': 'Brand dihapus',
  'org.outlet_deleted': 'Outlet dihapus',
  'employee.talenta_created': 'Karyawan dibuat dari Talenta',
  'employee.talenta_updated': 'Karyawan diperbarui dari Talenta',
  'employee.talenta_deactivated': 'Karyawan dinonaktifkan (resign Talenta)',
  'employee.talenta_linked': 'Karyawan ditautkan ke Talenta',
  'employee.removed_from_machines': 'Karyawan dihapus dari mesin',
  'talenta_employee.ignored': 'Data Talenta diabaikan',
}

const GROUP_LABEL = {
  auth: 'Login & password',
  user: 'User',
  role: 'Role',
  setting: 'Pengaturan',
  machine: 'Mesin',
  employee: 'Karyawan',
  talenta_employee: 'Sinkron Talenta',
  attendance: 'Absensi / Talenta',
  org: 'Struktur organisasi',
}

const actionLabel = (a) => ACTION_LABEL[a] ?? a

function actionVariant(action) {
  if (/failed|locked|blocked|deleted|cleared|rejected/.test(action)) return 'destructive'
  if (action.startsWith('auth.')) return 'outline'
  if (/created|approved/.test(action)) return 'success'
  return 'secondary'
}

const show = (v) => {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'ya' : 'tidak'
  if (Array.isArray(v)) return v.length ? v.join(', ') : '—'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

// Perubahan berbentuk {field: {from, to}} ditampilkan sebagai tabel kecil;
// bentuk lain (ringkasan aksi) sebagai daftar key: value.
function Changes({ changes }) {
  if (!changes) return <span className="text-muted-foreground">—</span>
  const entries = Object.entries(changes)
  const isDiff = entries.every(([, v]) => v && typeof v === 'object' && !Array.isArray(v) && 'to' in v)

  return (
    <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1 text-xs">
      {entries.map(([field, v]) => (
        <React.Fragment key={field}>
          <dt className="font-mono text-muted-foreground">{field}</dt>
          <dd className="break-words">
            {isDiff ? (
              <>
                <span className="text-muted-foreground line-through">{show(v.from)}</span>
                {' → '}
                <span className="font-medium">{show(v.to)}</span>
              </>
            ) : (
              show(v)
            )}
          </dd>
        </React.Fragment>
      ))}
    </dl>
  )
}

export default function AuditLogs({ logs = [], pagination = {}, filters = {}, actions = [], users = [] }) {
  const [form, setForm] = useState({
    action: filters.action ?? '',
    user_id: filters.user_id ?? '',
    q: filters.q ?? '',
    date_from: filters.date_from ?? '',
    date_to: filters.date_to ?? '',
  })
  const [expanded, setExpanded] = useState(null)

  const groups = [...new Set(actions.map((a) => a.split('.')[0]))]
  const hasFilters = Object.values(filters).some(Boolean)

  const visit = (params) => {
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null))
    router.get('/audit-logs', clean, { preserveState: true, preserveScroll: true, only: ['logs', 'pagination', 'filters'] })
  }

  const apply = (e) => {
    e?.preventDefault()
    visit(form)
  }

  const reset = () => {
    const empty = { action: '', user_id: '', q: '', date_from: '', date_to: '' }
    setForm(empty)
    visit(empty)
  }

  const goToPage = (page) => visit({ ...filters, page })

  return (
    <Layout>
      <Head title="Audit Log" />

      <div className="space-y-6">
        <p className="text-sm text-muted-foreground">
          Jejak aksi sensitif: login, perubahan user & role, pengaturan, mesin, dan pengiriman ke Talenta.
          Disimpan 365 hari.
        </p>

        <Card>
          <CardContent className="pt-6">
            <form onSubmit={apply} className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-6">
              <Select value={form.action} onChange={(e) => setForm({ ...form, action: e.target.value })} className="lg:col-span-2">
                <option value="">Semua aksi</option>
                {groups.map((g) => (
                  <optgroup key={g} label={GROUP_LABEL[g] ?? g}>
                    <option value={g}>Semua: {GROUP_LABEL[g] ?? g}</option>
                    {actions.filter((a) => a.startsWith(`${g}.`)).map((a) => (
                      <option key={a} value={a}>{actionLabel(a)}</option>
                    ))}
                  </optgroup>
                ))}
              </Select>
              <Select value={form.user_id} onChange={(e) => setForm({ ...form, user_id: e.target.value })}>
                <option value="">Semua pengguna</option>
                {users.map((u) => (
                  <option key={u.id} value={u.id}>{u.name}</option>
                ))}
              </Select>
              <Input
                type="date"
                value={form.date_from}
                onChange={(e) => setForm({ ...form, date_from: e.target.value })}
                aria-label="Dari tanggal"
              />
              <Input
                type="date"
                value={form.date_to}
                onChange={(e) => setForm({ ...form, date_to: e.target.value })}
                aria-label="Sampai tanggal"
              />
              <Input
                value={form.q}
                onChange={(e) => setForm({ ...form, q: e.target.value })}
                placeholder="Cari nama / target / IP"
              />
              <div className="flex gap-2 lg:col-span-6">
                <Button type="submit" size="sm">Terapkan</Button>
                {hasFilters && (
                  <Button type="button" size="sm" variant="ghost" onClick={reset} className="gap-1">
                    <X className="h-4 w-4" /> Reset
                  </Button>
                )}
              </div>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <History className="h-5 w-5 text-indigo-500" />
              Aktivitas
            </CardTitle>
            <CardDescription>
              {pagination.total > 0
                ? `Menampilkan ${pagination.from}–${pagination.to} dari ${pagination.total} entri`
                : 'Tidak ada entri'}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Waktu</th>
                    <th className="py-2 pr-3 font-medium">Pengguna</th>
                    <th className="py-2 pr-3 font-medium">Aksi</th>
                    <th className="py-2 pr-3 font-medium">Target</th>
                    <th className="py-2 pr-3 font-medium">IP</th>
                    <th className="py-2 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  {logs.map((log) => {
                    const open = expanded === log.id
                    return (
                      <React.Fragment key={log.id}>
                        <tr className="border-b align-top last:border-0">
                          <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs">{log.created_at}</td>
                          <td className="py-2 pr-3">{log.user_name ?? <span className="text-muted-foreground">sistem</span>}</td>
                          <td className="py-2 pr-3">
                            <Badge variant={actionVariant(log.action)} title={log.action}>{actionLabel(log.action)}</Badge>
                          </td>
                          <td className="py-2 pr-3">
                            {log.target_label ?? log.target_id ?? <span className="text-muted-foreground">—</span>}
                            {log.target_type && (
                              <span className="ml-1 text-xs text-muted-foreground">({log.target_type})</span>
                            )}
                          </td>
                          <td className="whitespace-nowrap py-2 pr-3 font-mono text-xs">{log.ip_address ?? '—'}</td>
                          <td className="py-2 text-right">
                            {log.changes && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setExpanded(open ? null : log.id)}
                                aria-expanded={open}
                                className="gap-1 text-xs"
                              >
                                Detail {open ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                              </Button>
                            )}
                          </td>
                        </tr>
                        {open && (
                          <tr className="border-b bg-muted/40">
                            <td colSpan={6} className="px-3 py-3">
                              <Changes changes={log.changes} />
                              {log.user_agent && (
                                <p className="mt-2 truncate text-[11px] text-muted-foreground" title={log.user_agent}>
                                  {log.user_agent}
                                </p>
                              )}
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {(pagination.last_page ?? 1) > 1 && (
              <div className="mt-4 flex items-center justify-end gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={pagination.current_page <= 1}
                  onClick={() => goToPage(pagination.current_page - 1)}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="text-sm text-muted-foreground">
                  Hal {pagination.current_page} / {pagination.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={pagination.current_page >= pagination.last_page}
                  onClick={() => goToPage(pagination.current_page + 1)}
                >
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
