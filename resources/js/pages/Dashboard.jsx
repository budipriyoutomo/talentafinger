import React, { useState, useEffect } from 'react'
import { Head, Link } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { usePermissions } from '@/lib/permissions'
import { Clock, CheckCircle2, AlertCircle, Zap, Camera } from 'lucide-react'

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

  const StatCard = ({ icon: Icon, label, value, color, href }) => {
    const card = (
      <Card className={href ? 'transition-colors hover:bg-slate-50 dark:hover:bg-slate-900' : ''}>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <CardTitle className="text-sm font-medium">{label}</CardTitle>
          <Icon className={`h-4 w-4 ${color}`} />
        </CardHeader>
        <CardContent>
          <div className="text-2xl font-bold">{value}</div>
        </CardContent>
      </Card>
    )
    return href ? <Link href={href} className="block">{card}</Link> : card
  }

  // null dari server = angka tak bisa dihitung untuk driver queue ini -> tampil "—".
  const QueueStat = ({ label, value, accent, color }) => (
    <div className={`border-l-4 ${accent} pl-4`}>
      <p className="text-sm text-slate-500 dark:text-slate-400">{label}</p>
      <p className={`text-2xl font-bold ${color}`}>{value ?? '—'}</p>
    </div>
  )

  return (
    <Layout>
      <Head title="Dashboard" />

      <div className="space-y-6">
        <div className="flex justify-end">
          <div className="text-sm text-slate-500 dark:text-slate-400">
            Last updated: {new Date().toLocaleTimeString()}
          </div>
        </div>

        {can('attendance.review') && stats_?.review_pending > 0 && (
          <Link
            href="/attendance-logs?status=pending_review"
            className="flex items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 transition-colors hover:bg-blue-100 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200 dark:hover:bg-blue-900"
          >
            <span className="flex items-center gap-2">
              <Camera className="h-4 w-4" />
              {stats_.review_pending} absen foto menunggu persetujuan Anda
            </span>
            <span className="font-medium">Review →</span>
          </Link>
        )}

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard
            icon={Zap}
            label="Online Machines"
            value={machineList.filter(m => m.status === 'online').length}
            color="text-green-600"
          />
          <StatCard
            icon={Clock}
            label="Logs Today"
            value={stats_?.logs_today || 0}
            color="text-blue-600"
          />
          <StatCard
            icon={CheckCircle2}
            label="Sent"
            value={stats_?.sent_count || 0}
            color="text-green-600"
          />
          <StatCard
            icon={AlertCircle}
            label="Failed"
            value={stats_?.failed_count || 0}
            color="text-red-600"
            href="/attendance-logs?status=failed"
          />
        </div>

        {/* Device Heartbeat */}
        <Card>
          <CardHeader>
            <CardTitle>Device Heartbeat Monitor</CardTitle>
            <CardDescription>Real-time status of all registered machines</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {machineList.map(machine => (
                <Card key={machine.id} className="border-slate-200 dark:border-slate-800">
                  <CardContent className="pt-6">
                    <div className="flex justify-between items-start mb-3">
                      <div>
                        <h3 className="font-semibold text-slate-900 dark:text-slate-50">{machine.name}</h3>
                        <p className="text-sm text-slate-500 dark:text-slate-400">{machine.serial_number}</p>
                        <p className="text-xs text-slate-400 dark:text-slate-500 mt-1">{machine.location}</p>
                      </div>
                      <Badge variant={machine.status === 'online' ? 'success' : 'destructive'}>
                        {machine.status}
                      </Badge>
                    </div>
                    {machine.last_seen_at && (
                      <p className="text-xs text-slate-400 dark:text-slate-500">
                        Last seen: {new Date(machine.last_seen_at).toLocaleString()}
                      </p>
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>
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
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <QueueStat
                label="Absensi pending"
                value={stats_?.queue_pending}
                accent="border-yellow-500"
                color="text-yellow-600"
              />
              <QueueStat
                label="Job menunggu"
                value={stats_?.jobs_waiting}
                accent="border-slate-400"
                color="text-slate-700 dark:text-slate-200"
              />
              <QueueStat
                label="Job diproses"
                value={stats_?.jobs_processing}
                accent="border-blue-500"
                color="text-blue-600"
              />
              <QueueStat
                label="Job gagal"
                value={stats_?.jobs_failed}
                accent="border-red-500"
                color="text-red-600"
              />
            </div>
          </CardContent>
        </Card>
      </div>
    </Layout>
  )
}
