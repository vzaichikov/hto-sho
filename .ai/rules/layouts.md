---
paths:
  - '{vite.config.js,resources/views/components/layouts/**}'
---

# Layouts

## Bundle and render Cyrillic brand fonts
Manrope must request both cyrillic and latin subsets in the Laravel Vite font configuration, and layouts must render @fonts('manrope'). Kurka Lapoyu is self-hosted from the author's unchanged OTF file and imported through resources/css/fonts.css in both CSS entries.
