import React from 'react'
import { createRoot } from 'react-dom/client'
import { createInertiaApp } from '@inertiajs/react'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import '../css/app.css'

createInertiaApp({
  progress: {
    color: '#4F46E5',
  },
  resolve: (name) => resolvePageComponent(
    `./pages/${name}.jsx`,
    import.meta.glob('./pages/**/*.jsx'),
  ),
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />)
  },
})
