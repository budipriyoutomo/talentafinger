import React from 'react'
import { cn } from '@/lib/utils'

// Wrapper <select> native bergaya shadcn (token-based). Bukan Radix Select,
// supaya tetap ringan & kompatibel dengan pemakaian <option> di seluruh app.
const Select = React.forwardRef(({ className, ...props }, ref) => (
  <select
    ref={ref}
    data-slot="select"
    className={cn(
      'border-input flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50',
      'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
      'aria-invalid:ring-destructive/20 aria-invalid:border-destructive',
      className
    )}
    {...props}
  />
))
Select.displayName = 'Select'

export { Select }
