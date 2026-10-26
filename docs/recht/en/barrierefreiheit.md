# Accessibility Statement

> **Draft — legal review pending.** Version 0.2 · As of 25.10.2026 · not yet in force.
> The German version prevails; translations are for information only.

Basalto is meant to be usable by everyone — on basaltocms.com and in the software that others use to build their
websites. This statement explains how far we have come, what is still missing and how to reach us if something does
not work.

## 1. Our goal

We follow the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA and the European standard EN 301 549. We
build new features from the outset so that they work with a keyboard, a screen reader, magnification and custom
colour settings.

## 2. What this statement covers

- **basaltocms.com** with documentation, demo, Community, Marketplace, customer account and licence purchase;
- **the Basalto software**: the backend, the editor, the cookie settings, the customer accounts in the frontend and
  the bundled theme “starter”.

Whoever operates a website run with Basalto is responsible for it. Basalto helps — for example with mandatory alt
text for images, which can be switched on — but cannot guarantee other people's content and themes.

## 3. Status

basaltocms.com and Basalto are **partially conformant** with WCAG 2.2 level AA. The deviations are listed in
section 5. This is based on a self-assessment dated 25.10.2026 (section 8).

## 4. What we do to achieve this

- Pages with a clear structure: headings in the correct order, landmarks, language per page and section, a
  “Skip to content” link.
- Everything operable by keyboard, with visible focus; dialogs such as the cookie settings keep the focus and can be
  closed with Escape.
- Contrast meeting AA in light and dark mode; no information conveyed by colour alone.
- Motion only where it explains something; calm when “reduce motion” is set in the operating system.
- Forms with visible labels and error messages next to the field.
- Sign-in without a password and without picture puzzles: code by e-mail or passkey; the spam protection computes in
  the background instead of using a CAPTCHA.
- Readable when magnified up to 200 %, without horizontal scrolling on a phone.

## 5. Known limitations

- **Charts in the statistics** summarise their values in a caption; a table with all daily values is still missing.
- **PDF documents** — invoices and receipts — are not yet tagged as accessible PDFs. On request, we will send you the
  information as text.
- **Drag and drop** in the page tree and in the block editor: where there is no equivalent way of operating it via
  menus or keyboard, we are working on it.
- **Content by users** — posts, images, listings in the Marketplace — may contain images without a description or
  unclear structures. We point this out in the editors.
- **Embedded third-party content** (videos) depends on its providers; it loads only after your consent.

## 6. Feedback and contact

Have you encountered a barrier, or do you need content in another format? Write to email@basaltocms.com with the address
of the page and a short description. We reply within ten working days and tell you by when we will fix the problem or
how you can obtain the information in another way.

## 7. Enforcement

In Switzerland, the Disability Discrimination Act (DDA) prohibits private providers from discriminating against
people with disabilities. Services in the EU are governed by the European Accessibility Act (Directive (EU)
2019/882); as a microenterprise, we are exempt from its requirements for services and follow them voluntarily. If
you are not satisfied with our reply, you can contact the market surveillance authority of your country or an
organisation for people with disabilities.

## 8. How we test

- Automated tests with axe-core on every change to the software and with Lighthouse on the most important pages of
  basaltocms.com.
- Manual tests with keyboard, magnification, dark mode and a narrow screen for every new feature.
- Spot checks with a screen reader: [[Platzhalter: carry out testing with VoiceOver and NVDA and enter the date]]

Technology: HTML, CSS and JavaScript, with WAI-ARIA where HTML alone is not sufficient. Content pages also work
without JavaScript; the cookie settings, live features and the backend require it. The current versions of Chrome,
Edge, Firefox and Safari are supported.

We review this statement at least once a year and whenever there are major changes.
