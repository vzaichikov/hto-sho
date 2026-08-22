---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Treat alcohol checkbox as affirmative confirmation
When an event has alcohol_planned=true, the adult organizer has explicitly confirmed alcohol for the event. Do not ask again whether alcohol is needed, though questions about type or quantity remain valid. When false, it is not a no-alcohol answer; never generate alcohol without explicit current context or an answer.
