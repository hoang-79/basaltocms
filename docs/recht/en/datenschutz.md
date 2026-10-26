# Privacy Policy

> **Draft — legal review pending.** Version 0.3 · As of 25.10.2026 · not yet in force.
> The German version prevails; translations are for information only.

This policy describes what we do with personal data: on basaltocms.com and its subdomains (website, documentation,
demo, Community, Marketplace), in the customer account, when you purchase a licence, in the SaaS and during the
software's update check. It is based on the Swiss Federal Act on Data Protection (FADP) and, for persons in the EU,
on the General Data Protection Regulation (GDPR).

Whoever operates a website run with Basalto is responsible for it. If we operate a site for you in the SaaS, we
process its data on your behalf in accordance with the [Data Processing Agreement](avv.md).

## 1. Who is responsible

Basalto — a product of nguyen-development.ch, Hoang Nguyen, sole proprietorship, Hohfurristrasse 24, 8408 Winterthur,
Switzerland. Data protection contact: email@basaltocms.com.

Representative in the EU (Art. 27 GDPR): [[Platzhalter: appoint a representative in the EU or give reasons why none
is required]]

## 2. What the software sends us

A Basalto installation verifies its licence key offline and transmits nothing about what happens on a site — no
content, no visitors, no users. It connects to basaltocms.com only in these cases:

- **Update check** — once a day and when someone selects “Check now” in the administration. In doing so, we see the
  server's IP address and the Basalto version. If the setting **“Usage data for the vendor”** is on (on by default),
  the installation also sends a random ID, the PHP version and the type of database. The ID is created at the first
  check and is not linked to anything; we store only a checksum of it, at most once a day, together with version,
  PHP and database, and use it to count active installations. We do not store the IP address in the process. You
  can switch this off under Administration › Settings › Operations.
- **Updates and downloads** — when the web installer, a release archive or an update is downloaded. We count the
  download with its type, version and channel, without an IP address.
- **Marketplace** — if someone installs a theme or plugin, the installation sends its request with the personal
  access key of the customer account (section 9).
- **Connection to the customer account** — anyone who connects the installation to their customer account in order
  to see licences and invoices in their own `/admin` triggers a request with an access key (section 5).

If you download Basalto via GitHub or Packagist, their privacy policies apply.

## 3. Visiting the website

With every request, our hosting provider cyon processes your IP address, the address requested, the time, the
browser identifier and the response, in order to deliver the page, find errors and fend off attacks. We limit
sign-in attempts, forms and requests per IP address; these counters expire after one hour at most. We check forms
and sign-ins with a small computing task in your browser instead of a picture puzzle; nothing is sent to third
parties in the process.

## 4. Statistics and cookie settings

We count the use of basaltocms.com ourselves, without third-party analytics or advertising services, without
profiles and without passing anything on.

- **Without consent**, we count every page view anonymously: the page viewed (without parameters), the referrer
  (only the name of the referring website and campaign information from the address), browser, operating system and
  device type, language, response time and whether you are signed in. No IP address, no cookies, no identifier — a
  page view cannot be attributed to any person.
- **With your consent** to the “Statistics” category, your browser additionally sets two cookies with random
  identifiers (`basalto_besucher` for one year, `basalto_sitzung` for 30 minutes). This lets us recognise returning
  visits and related sessions — for example how many pages a visit comprises. The identifiers do not reveal who you
  are.
- If your browser sends the “Global Privacy Control” signal, we treat it as a no.
- We delete page views after 400 days.

We store your choice in the cookie `basalto_consent` for one year. You can change it at any time via the
“Cookie settings” link at the bottom of every page; a withdrawal applies to the future.

### Cookies and storage in the browser

| Name | Purpose | Duration | Type |
| --- | --- | --- | --- |
| `basalto-session` | session, sign-in | 2 hours after the last request | necessary |
| `XSRF-TOKEN` | protection of forms against misuse | same as the session | necessary |
| `basalto_consent` | your choice in the cookie settings | 1 year | necessary |
| `basalto_community_terms` | your consent to the community terms as a guest (site and version) | 1 year | necessary |
| `basalto_besucher` | random identifier for returning visits | 1 year | statistics, only with consent |
| `basalto_sitzung` | random identifier of a session | 30 minutes | statistics, only with consent |

In your browser's storage (not as a cookie, never transmitted to us), we remember at your request: light or dark
mode, dismissed notices, drafts of posts in the forum, posts saved for quoting and whether you want desktop
notifications. You delete this information with your browser's website data.

Embedded third-party content (such as videos) loads only after your consent to the “External content” category; the
privacy policies of its providers then apply.

## 5. Customer account and sign-in

**Customer account:** e-mail address, name, language and — voluntarily — company and address. We record who accepted
which version of the Licence Agreement, the GTC and the Terms of Use, and when.

**Sign-in with a code:** We send a code to your e-mail address. We store the code only as a checksum, and the IP
address of the request as a checksum with our key; we delete expired codes after one day.

**Passkey:** Your device generates a key pair. We store only the public key, an identifier, the name you give the
passkey and when it was last used. Your fingerprint or facial image never leaves your device.

**Sign-in via another account:** If you choose Google, Microsoft, GitHub or LinkedIn, your browser redirects you to
the provider. From there we receive an identifier, your name and — only if the provider has confirmed it — your
e-mail address, and for Microsoft also the identifier of the organisation. We store the provider, identifier, e-mail
address and the time of the last sign-in, but not your password with the provider or any other data from your
account there. The providers are themselves responsible for their part:
[Google](https://policies.google.com/privacy), [Microsoft](https://privacy.microsoft.com/),
[GitHub](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement),
[LinkedIn](https://www.linkedin.com/legal/privacy-policy). You can remove linked accounts in the customer account
under “Sign-in”.

**Session:** Your session contains your IP address and browser identifier and ends two hours after the last request.

**Connected installations:** If you connect an installation to your customer account, we store its address (host),
a name of your choice, a checksum of the access key and when it last sent a request. You can disconnect it in the
customer account or in the installation.

## 6. Licence purchase and payment

**Licences:** billing address, licensee, Production domains, Tier, issued keys, invoices and payments. The licensee
and the domains are contained in the key itself and can be read by anyone who sees the key. With an Agency licence,
you record the name and domain of your clients for their sub-licence.

**Payment by card and other means** is processed via Stripe. Card data goes directly to Stripe; we never see or
store it. We receive the payment status, the amount, the type and the last digits of the payment method. **For the
QR-bill**, we receive the name, account and amount of the payment from our bank.

## 7. SaaS

For an account in the SaaS, we process as controller: the names and e-mail addresses of the persons in the account
with their roles, company, billing address, plan, subscription, invoices and payments, domains, the time of consent
to the GTC and the Privacy Policy at registration, as well as cancellation, export and deletion.

We process the content of your sites — pages, media, users, form submissions, comments and your visitors' customer
accounts — only on your behalf in accordance with the [Data Processing Agreement](avv.md). To help you, our team can
open a site **read-only** for at most four hours; the reason is shown in a banner in your backend and in your site's
log.

## 8. Community

**Profile:** display name and, if you wish, profile picture, cover image, signature and other information you enter
yourself. The profile, your posts, reactions and your rank are publicly visible and can be found by search engines.
The profile also shows how many posts you have written and when you were last active.

**Posts:** text, attachments, edits with their history, poll votes, reactions and answers marked as helpful. For
each post, we store a checksum of your IP address in order to fend off spam and misuse.

**What only you see:** threads you have read, subscriptions, saved posts, whom you follow and whom you ignore or
block. **Private conversations** are seen only by the participants; moderators see a message only if someone
reports it.

**Live features:** While you are writing a post, others in the same thread see “… is typing” with your display name
for a few seconds. New posts and notifications appear without reloading.

**Terms:** Before you use the community, you agree to the terms of use and the community guidelines. With an
account, we store the version, date and time of your consent and of any withdrawal; you can see them in your customer
account and withdraw there. As a guest, your browser remembers your consent in the cookie `basalto_community_terms`.

**Moderation:** We record reports, warnings, bans and every moderation measure with reasons. We may check new
accounts against the public list of Stop Forum Spam; in doing so, the IP address and a checksum of the e-mail
address are sent to the operator of this list. On basaltocms.com, this check is switched off; if we switch it on,
we will supplement this policy beforehand.

**Closing your account:** We delete your profile, images and attachments; posts remain without your name and without
any link to you, and the checksum of the IP address is removed (see [Terms of Use](nutzungsbedingungen.md)).

## 9. Marketplace

**Authors:** the public author profile (name or company and, if you wish, location, website and logo), the submitted
packages with the results of the automated check and of the check in an isolated test installation, the
communication relating to the review, and sales and payouts. For paid listings, you open an account with Stripe
(Stripe Connect); Stripe verifies your identity and your bank account under its own responsibility.

**Buyers:** purchases, invoices, the personal access key to the Composer repository (encrypted, so that you can see
it again in the customer account) and, for each listing, whether and in which version you downloaded it — the basis
for the “installed” label on a review.

**Reviews:** stars, title, text and your display name are public, together with the “purchased” or “installed”
label. We store reports of reviews with the reason; for reports without an account, only a checksum that prevents
multiple reports.

## 10. E-mails, notifications and contact

We send e-mails that belong to the account: sign-in codes, confirmations, invoices, reminders and security notices.
You receive notifications from the Community — replies, mentions, summaries — in the bell and, depending on your
settings in the profile, by e-mail. There is no newsletter.

We process what you write to us by e-mail or via the contact form, with your name and e-mail address, in order to
reply. We delete contact form submissions after 90 days.

If you cancel via “Cancel contracts here”, we process your name, e-mail address, the contract details and the time
of receipt in order to carry out, confirm and document the cancellation (contract and legal obligation).

## 11. Log and security

We record changes in the customer account, in moderation and in the administration of basaltocms.com with the time,
the account and a checksum of the IP address, for traceability and security; we delete entries after 365 days.

We transmit everything encrypted (TLS), sign in without a password, store codes and IP addresses only as checksums
and access keys encrypted or as a checksum, back up daily and log access. No one can guarantee complete security.
If we detect a data security breach that affects you, we inform you and — where the law requires it — the FDPIC or
the competent supervisory authority.

## 12. Legal basis

- **Contract** (Art. 31 para. 2 lit. a FADP, Art. 6 para. 1 lit. b GDPR): customer account, sign-in, purchase, key,
  invoices, SaaS, Marketplace, Community, support.
- **Legal obligation** (Art. 6 para. 1 lit. c GDPR): retention of invoices and accounting records.
- **Legitimate interest** (Art. 31 para. 1 FADP, Art. 6 para. 1 lit. f GDPR): operation and security of the
  website, prevention of misuse and spam, anonymous statistics, counting active installations, review of submitted
  Extensions, moderation, evidence of accepted terms, replies to your enquiries.
- **Consent** (Art. 6 para. 1 lit. a GDPR): statistics cookies, embedded third-party content.

We do not sell any data, do not use it for advertising and do not make any automated individual decisions with legal
effect. Automatic protective measures in the Community (pause between posts, word filter, check in the browser) and
the automated check in the Marketplace do not decide definitively; a human makes the final decision.

## 13. Recipients and service providers

We use service providers who process data on our behalf:

- **cyon AG, Basel (Switzerland):** operation of the website, SaaS, database, files and backups as well as sending
  our e-mails, in data centres in Switzerland. Live features of the Community and of notifications run without any
  further service, via requests to our server.

The following process data under their own responsibility:

- **Stripe Payments Europe Ltd. (Ireland)** for payments and Stripe Connect; Stripe transfers data to Stripe, Inc.
  (USA) for fraud prevention and legal obligations.
- **[[Platzhalter: bank]]** for incoming payments on QR-bills.
- **Google, Microsoft, GitHub and LinkedIn**, only if you sign in via them (section 5).

Beyond this, we pass on data only if the law or an authority requires it or if it is necessary to enforce our
rights.

## 14. Disclosure abroad

Our data is stored in Switzerland. Data goes abroad only via Stripe and — if you use it — sign-in via Google, Microsoft,
GitHub or LinkedIn; these recipients have their registered office or their group in the USA. For this, we rely on the
recipients' certification under the EU-US Data Privacy Framework and its Swiss-US part; should it cease to apply, on the
standard contractual clauses of the European Commission in the version recognised by the FDPIC. [[Platzhalter: check
each recipient's certification in the DPF register]] For persons in the EEA: the EU recognises that Switzerland provides
an adequate level of data protection.

## 15. How long

| Data | Retention |
| --- | --- |
| Customer account, profile, linked accounts, passkeys | until you close the account |
| Consents to the community terms | until you close the account |
| Sign-in codes | one day after expiry |
| Invoices and accounting records | ten years (Art. 958f CO) |
| Issued keys and accepted terms | until ten years after the end of the contract (Art. 127 CO) |
| SaaS account after cancellation | locked for 30 days, then deleted; invoices as above |
| Statistics page views, update checks | 400 days |
| Downloads | without personal reference, no time limit |
| Posts in the forum | as long as they exist; after the account is closed, without personal reference |
| Contact form submissions | 90 days |
| Cancellations via “Cancel contracts here” | five years after receipt (Art. 128 CO) |
| Data protection requests | three years after the answer |
| E-mails to us | two years after the last message, unless you are a customer |
| Log | 365 days |
| Backups | our own daily, the last seven are kept; in addition the backups of cyon ([[Platzhalter: retention of backups at cyon]]) |

## 16. Your rights

You can request information about your data, have it corrected, erased or restricted, and receive it in a common
electronic format (Art. 25, 28 and 32 FADP, Art. 15 to 20 GDPR). Where we rely on a legitimate interest, you may object
(Art. 21 GDPR); you can withdraw consent at any time with effect for the future. A message to email@basaltocms.com is
sufficient; we reply within 30 days. What we are required to retain (section 15) remains until the end of the retention
period.

With a customer account, you do most of this yourself under **Customer account › Data protection**: download your
data as a file, correct your name and address, view and withdraw consents, request restriction or object (answered
within one month) and delete the account.

If you cannot resolve a matter with us, you can contact the Federal Data Protection and Information Commissioner
(edoeb.admin.ch) or, in the EEA, the supervisory authority of your country.

## 17. Children and young people

basaltocms.com is aimed at adults and businesses. The Community is open to persons aged 16 and over. If we learn
that an account belongs to a younger person, we delete it.

## 18. Changes

We adapt this policy when basaltocms.com, the software or the legal situation changes. The date at the top shows the
current version. We notify customers of material changes by e-mail.
