---
paths:
  - app/Http/Controllers/EventJournalController.php
---

# Http Controllers

## Follow reused image extraction runs
An image extraction is deduplicated per owner and may be first recorded under another event. The event journal must include only image-extraction runs whose image correlation IDs match extraction IDs linked by that event's sources, while keeping all other run types event-scoped.
