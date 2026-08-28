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

## Validate legacy grouped requirements atomically
For saved grouped shopping requirements, require every explicitly named parenthesized product as its own plan item. Treat a qualifier for a known participant as purpose rather than product identity. Extra descriptive words may remain, but every atom must be present; preserve explicit units and compare explicit quantity against the sum of split items.
