import React, { useMemo } from 'react'
import { Head } from '@inertiajs/react'
import Layout from '../layouts/Layout'
import { DataTable } from '@/components/DataTable'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Download } from 'lucide-react'

export default function AttendanceLogs({ logs = [], machines = [] }) {
  const columns = useMemo(
    () => [
      {
        accessorKey: 'timestamp',
        header: 'Timestamp',
        cell: ({ row }) => (
          <div className="text-sm">
            {new Date(row.getValue('timestamp')).toLocaleString()}
          </div>
        ),
      },
      {
        accessorKey: 'machine_id',
        header: 'Machine',
        cell: ({ row }) => {
          const machineId = row.getValue('machine_id')
          const machine = machines.find(m => m.id === machineId)
          return <div className="text-sm">{machine?.name || 'Unknown'}</div>
        },
      },
      {
        accessorKey: 'biometric_id_lokal',
        header: 'Biometric ID',
        cell: ({ row }) => (
          <div className="font-mono text-sm">{row.getValue('biometric_id_lokal')}</div>
        ),
      },
      {
        accessorKey: 'employee_name',
        header: 'Employee',
        cell: ({ row }) => <div className="text-sm">{row.getValue('employee_name') || 'N/A'}</div>,
      },
      {
        accessorKey: 'status_sync',
        header: 'Status',
        cell: ({ row }) => {
          const status = row.getValue('status_sync')
          const variants = {
            pending: 'warning',
            sent: 'success',
            failed: 'destructive',
            duplicate: 'info',
          }
          return <Badge variant={variants[status] || 'info'}>{status}</Badge>
        },
      },
      {
        accessorKey: 'error_message',
        header: 'Error',
        cell: ({ row }) => {
          const error = row.getValue('error_message')
          return error ? (
            <details className="text-xs">
              <summary className="cursor-pointer font-medium text-red-600">View</summary>
              <p className="mt-1 text-slate-600 dark:text-slate-400 whitespace-normal">{error}</p>
            </details>
          ) : (
            '-'
          )
        },
      },
    ],
    [machines]
  )

  const handleExport = () => {
    const csv = [
      ['Timestamp', 'Machine', 'Biometric ID', 'Employee', 'Status', 'Error'],
      ...logs.map(log => [
        new Date(log.timestamp).toLocaleString(),
        machines.find(m => m.id === log.machine_id)?.name || 'Unknown',
        log.biometric_id_lokal,
        log.employee_name || 'N/A',
        log.status_sync,
        log.error_message || '',
      ]),
    ]
      .map(row => row.map(cell => `"${cell}"`).join(','))
      .join('\n')

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `attendance-logs-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
  }

  const stats = {
    total: logs.length,
    sent: logs.filter(l => l.status_sync === 'sent').length,
    failed: logs.filter(l => l.status_sync === 'failed').length,
    successRate: logs.length > 0 ? Math.round((logs.filter(l => l.status_sync === 'sent').length / logs.length) * 100) : 0,
  }

  return (
    <Layout>
      <Head title="Attendance Logs" />

      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold text-slate-900 dark:text-slate-50">Attendance Logs</h1>
          <Button onClick={handleExport} variant="outline" className="gap-2">
            <Download className="h-4 w-4" />
            Export CSV
          </Button>
        </div>

        {/* Data Table */}
        <Card>
          <CardHeader>
            <CardTitle>Log Viewer</CardTitle>
            <CardDescription>Click column headers to sort • Use search to filter</CardDescription>
          </CardHeader>
          <CardContent>
            <DataTable columns={columns} data={logs} filterPlaceholder="Filter by employee or biometric ID..." />
          </CardContent>
        </Card>

        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Total Logs</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Sent</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats.sent}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Failed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats.failed}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Success Rate</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats.successRate}%</div>
            </CardContent>
          </Card>
        </div>
      </div>
    </Layout>
  )
}
