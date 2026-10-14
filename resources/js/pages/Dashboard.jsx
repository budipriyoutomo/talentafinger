import React, { useState, useEffect } from 'react'
import { Head, Link } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { usePermissions } from '@/lib/permissions'
import { Clock, CheckCircle2, AlertCircle, Zap, Camera, ArrowRight, Cpu, MapPin } from 'lucide-react'

export default function Dashboard({ machines = [], stats = {} }) {
  const { can } = usePermissions()
  const [machineList, setMachineList] = useState(machines)
  const [stats_, setStats] = useState(stats)

  useEffect(() => {
    const interval = setInterval(() => {
      fetch('/api/machines')
        .then(res => res.json())
        .then(data => setMachineList(data))
        .catch(err => console.error(err))

      fetch('/api/dashboard-stats')
        .then(res => res.json())
        .then(data => setStats(data))
        .catch(err => console.error(err))
    }, 5000)

    return () => clearInterval(interval)
  }, [])

  const StatCard = ({ icon: Icon, label, value, tone, href, hint }) => {
    const card = (
      <Card className={`h-full gap-0 py-5 ${href ? 'transition-all hover:-translate-y-0.5 hover:shadow-md' : ''}`}>
        <CardContent className="flex items-start justify-between gap-4 px-5">
          <div className="min-w-0">
            <p className="text-sm font-medium text-muted-foreground">{label}</p>
            <p className="mt-2 text-3xl font-bold tracking-tight tabular-nums">{value}</p>
            {hint && <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">{hint}</p>}
          </div>
          <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${tone}`}>
            <Icon className="h-5 w-5" />
          </div>
        </CardContent>
      </Card>
    )
    return href ? (
      <Link href={href} className="block rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none">
        {card}
      </Link>
    ) : card
  }

  // null dari server = angka tak bisa dihitung untuk driver queue ini -> tampil "—".
  const QueueStat = ({ label, value, dot, color }) => (
    <div className="rounded-xl border bg-muted/30 p-4">
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <span className={`h-2 w-2 rounded-full ${dot}`} />
        {label}
      </p>
      <p className={`mt-2 text-2xl font-bold tabular-nums ${color}`}>{value ?? '—'}</p>
    </div>
  )

  const onlineCount = machineList.filter(m => m.status === 'online').length

  return (
    <Layout>
      <Head title="Dashboard" />

      <div className="space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Ringkasan Hari Ini</h2>
            <p className="text-sm text-muted-foreground">Pantau mesin absensi dan pengiriman data ke Talenta.</p>
          </div>
          <div className="flex items-center gap-2 rounded-full border bg-card px-3 py-1 text-xs text-muted-foreground shadow-xs">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
            </span>
            Live · diperbarui {new Date().toLocaleTimeString()}
          </div>
        </div>

        {can('attendance.review') && stats_?.review_pending > 0 && (
          <Link
            href="/attendance-logs?status=pending_review"
            className="group flex items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 transition-colors hover:bg-indigo-100 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-200 dark:hover:bg-indigo-500/20"
          >
            <span className="flex items-center gap-3">
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-500/15">
                <Camera className="h-4 w-4" />
              </span>
              <span>
                <span className="font-semibold">{stats_.review_pending} absen foto</span> menunggu persetujuan Anda
              </span>
            </span>
            <span className="flex shrink-0 items-center gap-1 font-medium">
              Review <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5" />
            </span>
          </Link>
        )}

        {/* Stats Cards */}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard
            icon={Zap}
            label="Online Machines"
            value={onlineCount}
            hint={`dari ${machineList.length} mesin terdaftar`}
            tone="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400"
          />
          <StatCard
            icon={Clock}
            label="Logs Today"
            value={stats_?.logs_today || 0}
            hint="scan masuk hari ini"
            tone="bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400"
          />
          <StatCard
            icon={CheckCircle2}
            label="Sent"
            value={stats_?.sent_count || 0}
            hint="terkirim ke Talenta"
            tone="bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-400"
          />
          <StatCard
            icon={AlertCircle}
            label="Failed"
            value={stats_?.failed_count || 0}
            hint={<>Lihat detail <ArrowRight className="h-3 w-3" /></>}
            tone="bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-400"
            href="/attendance-logs?status=failed"
          />
        </div>

        {/* Device Heartbeat */}
        <Card>
          <CardHeader className="flex flex-row items-start justify-between gap-4">
            <div className="space-y-1.5">
              <CardTitle>Device Heartbeat Monitor</CardTitle>
              <CardDescription>Real-time status of all registered machines</CardDescription>
            </div>
            <Badge variant="outline" className="shrink-0 tabular-nums">
              {onlineCount}/{machineList.length} online
            </Badge>
          </CardHeader>
          <CardContent>
            {machineList.length === 0 ? (
              <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed py-10 text-muted-foreground">
                <Cpu className="h-8 w-8 opacity-40" />
                <p className="text-sm">Belum ada mesin terdaftar.</p>
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                {machineList.map(machine => {
                  const online = machine.status === 'online'
                  return (
                    <div
                      key={machine.id}
                      className="flex items-start gap-3 rounded-xl border bg-muted/20 p-4 transition-colors hover:bg-muted/40"
                    >
                      <div
                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${
                          online
                            ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400'
                            : 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-400'
                        }`}
                      >
                        <Cpu className="h-5 w-5" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-2">
                          <h3 className="truncate font-semibold">{machine.name}</h3>
                          <Badge variant={online ? 'success' : 'destructive'}>
                            <span className={`h-1.5 w-1.5 rounded-full ${online ? 'bg-emerald-500' : 'bg-red-500'}`} />
                            {machine.status}
                          </Badge>
                        </div>
                        <p className="truncate font-mono text-xs text-muted-foreground">{machine.serial_number}</p>
                        {machine.location && (
                          <p className="mt-1 flex items-center gap-1 truncate text-xs text-muted-foreground">
                            <MapPin className="h-3 w-3 shrink-0" /> {machine.location}
                          </p>
                        )}
                        {machine.last_seen_at && (
                          <p className="mt-1 text-xs text-muted-foreground/80">
                            Last seen: {new Date(machine.last_seen_at).toLocaleString()}
                          </p>
                        )}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Queue Status */}
        <Card>
          <CardHeader>
            <CardTitle>Queue Status</CardTitle>
            <CardDescription>
              Absensi menunggu dikirim ke Talenta & job background (kirim ulang, sidik jari bulk)
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <QueueStat
                label="Absensi pending"
                value={stats_?.queue_pending}
                dot="bg-amber-500"
                color="text-amber-600 dark:text-amber-400"
              />
              <QueueStat
                label="Job menunggu"
                value={stats_?.jobs_waiting}
                dot="bg-slate-400"
                color="text-foreground"
              />
              <QueueStat
                label="Job diproses"
                value={stats_?.jobs_processing}
                dot="bg-sky-500"
                color="text-sky-600 dark:text-sky-400"
              />
              <QueueStat
                label="Job gagal"
                value={stats_?.jobs_failed}
                dot="bg-red-500"
                color="text-red-600 dark:text-red-400"
              />
            </div>
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
