import React from 'react'
import { cn } from '@/lib/utils'

const badgeVariants = {
  base: 'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:focus:ring-slate-300',
  variants: {
    variant: {
      default: 'border-transparent bg-slate-900 text-slate-50 hover:bg-slate-800 dark:bg-slate-50 dark:text-slate-900 dark:hover:bg-slate-100',
      secondary: 'border-transparent bg-slate-100 text-slate-900 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-50 dark:hover:bg-slate-700',
      destructive: 'border-transparent bg-red-500 text-slate-50 hover:bg-red-600 dark:hover:bg-red-600',
      outline: 'text-slate-950 dark:text-slate-50',
      success: 'border-transparent bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900 dark:text-green-200 dark:hover:bg-green-800',
      warning: 'border-transparent bg-yellow-100 text-yellow-800 hover:bg-yellow-200 dark:bg-yellow-900 dark:text-yellow-200 dark:hover:bg-yellow-800',
      info: 'border-transparent bg-blue-100 text-blue-800 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800',
    },
  },
  defaultVariants: {
    variant: 'default',
  },
}

const Badge = React.forwardRef(
  ({ className, variant = 'default', ...props }, ref) => (
    <div
      ref={ref}
      className={cn(badgeVariants.base, badgeVariants.variants.variant[variant], className)}
      {...props}
    />
  )
)
Badge.displayName = 'Badge'

export { Badge }
