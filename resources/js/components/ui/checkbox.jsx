import React from 'react'
import { cn } from '@/lib/utils'

// <input type="checkbox"> native bergaya shadcn (token-based). Bukan Radix,
// supaya konsisten dengan Select/Input di app ini yang juga native.
const Checkbox = React.forwardRef(({ className, indeterminate = false, ...props }, ref) => {
  const innerRef = React.useRef(null)

  // indeterminate hanya bisa di-set lewat property DOM, bukan atribut.
  React.useEffect(() => {
    if (innerRef.current) innerRef.current.indeterminate = indeterminate
  }, [indeterminate])

  return (
    <input
      type="checkbox"
      ref={(node) => {
        innerRef.current = node
        if (typeof ref === 'function') ref(node)
        else if (ref) ref.current = node
      }}
      data-slot="checkbox"
      className={cn(
        'border-input accent-primary size-4 shrink-0 cursor-pointer rounded-[4px] border bg-transparent transition-[color,box-shadow] outline-none disabled:cursor-not-allowed disabled:opacity-50',
        'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
        className
      )}
      {...props}
    />
  )
})
Checkbox.displayName = 'Checkbox'

export { Checkbox }
