# Setting up AI suggestions

The module makes suggestions to editors using the **customer’s key**: improve text, alt texts from the image,
translation per field and SEO data (page title, description). It works through the
[Laravel AI SDK](https://laravel.com/docs/ai-sdk) (`laravel/ai`) and calls the provider directly from the installation.

It applies to your own installations and the SaaS alike.

![The settings of the AI suggestions: provider, model, key, limits and functions](../redaktion/bilder/ki-einstellungen.webp)

## Principles

- **Only on click, always a suggestion.** No calls in the background, none when a page loads, no job. The answer is a
  suggestion; “Apply” puts it into the field, and autosave writes to the **draft** — never to a published version. The
  only exception: alt texts take effect immediately when saved (media have no versions); applying is recorded in the
  log (`ai.alt_text.applied`).
- **The key belongs to the customer** and is stored per site. It is kept **encrypted** with the `APP_KEY` in
  `ai_settings.api_key`, never goes to the browser (the interface only shows “set ✓ · …1234”), never into the log and
  not into a site export. After a move or a new `APP_KEY`, enter it again.
- **Every use is logged** (`ai_usages`): site, user, function, content or media file, language, provider, model,
  tokens (if the provider reports them), success or kind of error, applied yes/no. **Without** text, prompt, answer
  and key. Provider error messages are not logged, only their kind (`rate_limited`, `auth` …).
- **Off by default.** Switch it on under **Administration › Sites › Edit site › “Modules” tab** › “AI suggestions”.

## Setting up

1. Switch the module on for the site (see above).
2. **Administration › AI suggestions** (`/admin/ki`) — only the site’s owner and administrators (or the account’s
   owner and administration):
   - Choose the **provider**: OpenAI, Anthropic (Claude), Google Gemini, Mistral AI, xAI (Grok), DeepSeek, Groq or
     OpenRouter. Alt texts need a provider that reads images; with DeepSeek and Groq the function is off.
   - **Model** — empty means the SDK default for this provider (shown as placeholder). Enter your own model (for
     example a cheaper one) with its name at the provider.
   - Paste the **key** (API key) — it saves itself with Enter or when you leave the field. Leaving it empty keeps the current one; “Remove key” switches off all AI
     buttons of the site.
   - **Daily limit** (suggestions per day for the whole site, default 100) and **cost cap** (tokens per month, empty =
     none). In addition, a fixed limit applies: at most ten suggestions per person and minute.
   - Switch **functions** on or off individually.
3. Try “✦ Improve text” once in the editor. If a message appears instead of a suggestion, it states the reason (key
   rejected, credit used up, provider overloaded, unknown model).

Every change of the setting is recorded in the core log (`ai.settings.changed`; for the key only “newly set”).

## Privacy

**Content goes to the provider.** Every suggestion sends the content concerned to the selected provider with the
site’s key: the text of a field, the source text of a translation, the text of a page (SEO) or the image (alt texts;
the largest variant up to 1600 px, otherwise the original up to 8 MB). The interface says so everywhere: on the
settings page as a notice with a link to the provider’s privacy policy, and in every suggestion dialog with the name
of the provider.

What you should clarify:

- **Data processing:** The contractual relationship with the provider is the customer’s (their key, their account).
  Check the provider’s terms on storage and training with inputs; many providers offer an API setting without training
  and with short retention.
- **Location:** Several providers process data in the USA. For personal data in texts, the rules on transfers abroad
  apply (Swiss FADP, GDPR).
- **The site’s privacy policy:** Name the provider if content with personal data is processed.
- **Train editors:** Don’t ask for suggestions on anything confidential; the editors’ help ([AI suggestions](../redaktion/ki.md))
  says so too.

## Gateway or proxy

If traffic to the provider goes through a gateway (company proxy, EU endpoint), set the base URL for the whole
installation in `.env` — the same keys as in the SDK, e.g. `OPENAI_URL`, `ANTHROPIC_URL`, `GEMINI_URL`. The module
uses them for every site; the key remains the site’s.

## Security

- Inputs are limited: at most 12,000 characters per suggestion; rich text with images, tables, tasks or embeds is not
  sent to the provider (those parts would be lost).
- Outputs are sanitised: rich text comes back as Markdown and goes through the core converter (raw HTML is dropped,
  links only with http(s), mailto, tel or relative); plain text without HTML and control characters, truncated to the
  field length.
- Permissions as for editing: improving text and SEO require “edit content”, translating the right to the content,
  alt texts the right to the media library. Other sites see and use nothing.

## Usage and costs

**Administration › AI suggestions › Usage** shows per month (the last twelve) the number of suggestions, how many were
applied and how many failed, the tokens and the split across functions, plus the last 50 calls with person, function,
content, model, tokens and result. Billing happens at the provider, on the customer’s account — Basalto bills nothing.

## Tables

| Table | Content | Site export |
| --- | --- | --- |
| `ai_settings` | per site provider, model, key (encrypted), daily limit, cost cap, switches | no — secret |
| `ai_usages` | usage log without content | no — log of the source installation |

## Troubleshooting

| Message | Cause | Remedy |
| --- | --- | --- |
| “… rejected the key” | key wrong, revoked or without access to the model | create a new key at the provider and enter it |
| “The credit … is used up” | prepaid credit empty | top up at the provider |
| “… is throttling right now” | the provider’s rate limit | try again later; possibly a higher tier at the provider |
| “… did not accept the request — is the model correct?” | unknown model name or no image input | empty the model (SDK default) or correct it |
| “The daily limit … has been reached” | the site’s daily limit | raise the limit or wait until tomorrow |
| “The cost cap … has been reached” | the month’s tokens over the cap | raise or empty the cap |
| Buttons grey “(AI off)” | no key or function off | check the setting |

## Next

- [AI suggestions](../redaktion/ki.md)
- [Administration](../verwaltung/README.md)
