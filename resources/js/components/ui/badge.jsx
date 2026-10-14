import React from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const badgeVariants = cva(
  'inline-flex items-center justify-center rounded-full border px-2.5 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 gap-1 [&>svg]:size-3 [&>svg]:pointer-events-none transition-[color,box-shadow] overflow-hidden focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90',
        secondary:
          'border-transparent bg-muted text-muted-foreground [a&]:hover:bg-muted/80',
        destructive: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/15 dark:text-red-300 [a&]:hover:bg-red-100',
        outline: 'text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground',
        // Varian semantik tambahan yang dipakai di seluruh aplikasi.
        success:
          'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/15 dark:text-emerald-300',
        warning:
          'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-300',
        info: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/15 dark:text-sky-300',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
)

const Badge = React.forwardRef(({ className, variant, asChild = false, ...props }, ref) => {
  const Comp = asChild ? Slot : 'span'
  return (
    <Comp
      ref={ref}
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
})
Badge.displayName = 'Badge'

export { Badge, badgeVariants }
