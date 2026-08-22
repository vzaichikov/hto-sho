---
paths:
  - '{vite.config.js,resources/views/components/layouts/**}'
---

# Layouts

## Bundle and render Cyrillic brand fonts
Manrope and Neucha must request both cyrillic and latin subsets in the Laravel Vite font configuration. Layouts must render @fonts(['manrope', 'neucha']); declaring the CSS family alone silently falls back for Ukrainian text.
