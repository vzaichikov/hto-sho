---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Treat alcohol checkbox as affirmative confirmation
When an event has alcohol_planned=true, the adult organizer has explicitly confirmed alcohol for the event. Do not ask again whether alcohol is needed, though questions about type or quantity remain valid. When false, it is not a no-alcohol answer; never generate alcohol without explicit current context or an answer.

## Separate expected image waits from failures
SummarizeEventContextJob releases while image sources are unfinished, and every release consumes a queue attempt. Keep it on a bounded retryUntil with tries=0 and a matching unique-lock horizon so normal OCR waits cannot trigger MaxAttemptsExceededException. Keep the image HTTP timeout below ProcessImageExtractionJob timeout, and that job timeout below queue retry_after.

## Prefer structured image timelines in event synthesis
When an image extraction has a non-empty message_timeline, omit raw ocr_text from SummarizeEventContextJob evidence to avoid sending the same chat twice. Keep ocr_text only as a fallback for null or empty timelines; source_summary remains available.
