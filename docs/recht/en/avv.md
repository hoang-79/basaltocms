# Data Processing Agreement

> **Draft — legal review pending.** Version 0.3 · As of 25.10.2026 · not yet in force.
> The German version prevails; translations are for information only.

## When this agreement applies

This agreement applies to the extent that we process personal data on your behalf:

1. **Basalto as SaaS** — we operate your sites on our infrastructure. The agreement applies to all content, users,
   media, form submissions, comments, your visitors' customer accounts and logs of your sites. It forms part of the
   subscription and applies upon registration.
2. **Support with access to your data** — you give us access to your own installation, or send us a backup, an
   export or excerpts from logs so that we can find an error.
3. **Agency sub-licences** — if an agency records in the customer account the names of its clients who are natural
   persons, we process them only to issue their keys (GTC section 8). [[Platzhalter: classification as processing
   on behalf or own responsibility — question in the review brief]]

Your own Basalto installation does not send us any content; you alone are responsible for its data. We process the
customer account, subscription, invoices, licence purchases and the Marketplace as a controller in our own right in
accordance with the [Privacy Policy](datenschutz.md).

## 1. Subject matter and scope

This agreement applies between you as the controller (“customer”) and Basalto — a product of nguyen-development.ch,
Hoang Nguyen, sole proprietorship, Hohfurristrasse 24, 8408 Winterthur, Switzerland (“we”). It forms part of the
[GTC](agb.md) and satisfies Art. 9 of the Swiss Federal Act on Data Protection (FADP) and Art. 28 of the EU General Data
Protection Regulation (GDPR). Where it contradicts the GTC, it takes precedence.

## 2. What is processed

**Purpose:** operating your sites in the SaaS — storing, delivering, backing up, updating, exporting and deleting —;
troubleshooting and fixing errors in support; issuing sub-licence keys.

**Nature of the processing:** collecting via your sites, storing, delivering, backing up and restoring, reading in
support, exporting, deleting.

**Data subjects:** users of your backend, visitors to your sites, persons who submit forms, comments or posts or
open a customer account on your site, persons in your content and media, clients of an agency.

**Categories of data:** names, e-mail addresses, roles, sign-in data (passkeys as a public key), log entries with
checksums of IP addresses, form submissions, comments, posts, content and media, anonymous or — with your visitors'
consent — pseudonymous statistics. We process sensitive personal data only if you record it yourself; you decide
whether your sites may contain such data.

**Duration:** in the SaaS, the term of the subscription and the period under section 10; in support, until the case
is closed, at most 30 days; for sub-licences, as long as the Agency licence.

## 3. Instructions

We process the data only on your instructions. Instructions are this agreement, the GTC, your settings and actions
in the backend and your request in a support case; you give further instructions in writing or by e-mail. If we
consider an instruction to be unlawful, we tell you so. Excepted is what we are required to do by law; in that case
we inform you beforehand, insofar as the law permits.

## 4. Confidentiality and access

Anyone at our end who accesses the data is bound to confidentiality. Today this is the owner alone; if further
persons join, we bind them in writing. We access your sites only where this is necessary for operation or support.
Support access to a site is **read-only**, requires a reason, ends after at most four hours and is shown, visible to
all users, in a banner in the backend and in the site's log.

## 5. Security

We take the technical and organisational measures under Art. 8 FADP and Art. 32 GDPR, in particular:

- encrypted transmission (TLS) on all channels; data and backups in data centres in Switzerland;
- separation of customers: every query is restricted to your account and your sites;
- sign-in without a password, roles and permissions per area, logging of changes and access;
- daily backups, updates with backup and rollback;
- checksums instead of IP addresses in logs, secrets stored encrypted;
- in support: data only on encrypted storage media, not in e-mail mailboxes, test environments only locally and
  without access from the internet, deletion after the case is closed.

We adapt the measures to the state of the art without lowering the level of protection.

## 6. Sub-processors

You approve these sub-processors:

| Sub-processor | Service | Location |
| --- | --- | --- |
| cyon AG, Basel (Switzerland) | operation of the application, database, files and backups; sending the e-mails of your sites | data centres in Switzerland |

In support we do not engage any further sub-processors; data you send us passes only through our e-mail mailbox at cyon
and our devices. Live features run without any further service, via requests to the server of your site.

We announce a new or replaced sub-processor by e-mail at least 30 days in advance. You may object within this period
for an important reason relating to data protection. If no solution is found, you may terminate with effect from
the date of the change; we refund amounts paid in advance on a pro rata basis. We bind every sub-processor to an
equivalent level of protection and are answerable for it as for our own conduct, within the limits of GTC
section 12.

## 7. Disclosure abroad

We disclose data only to states with an adequate level of data protection or with safeguards under Art. 16 para. 2 FADP
and Art. 46 GDPR — in particular certification under the EU-US Data Privacy Framework and its Swiss-US part, or the
standard contractual clauses of the European Commission. We do not disclose any data abroad for the operation of your
sites: it is stored with cyon in Switzerland.

## 8. Assistance

We assist you so that you can respond to requests from data subjects, report data security breaches and carry out
data protection impact assessments, insofar as our processing is concerned. For this purpose, Basalto provides you
with access, export and deletion per person in the backend. If we receive a request from a data subject concerning
your data, we forward it to you and do not answer it ourselves.

## 9. Data security breach

If we detect a data security breach affecting your data, we report it to you as quickly as possible, at the latest
within 48 hours, with what is known: nature of the breach, data and persons concerned, likely consequences, measures
taken. We provide whatever becomes known later subsequently. You decide whether the supervisory authority and the
data subjects are informed; we help you with this.

## 10. Deletion and return

**SaaS:** You can create a full export of your sites and your account at any time. After the end of the
subscription, the account is locked for 30 days; you can still export during this time. After that, we delete
sites, content, media and users. The data disappears from the backups when they are overwritten
([[Platzhalter: retention of backups at cyon]]). We retain invoices as required by law.

**Support:** After a case is closed, we delete the data you gave us for it at the latest after 30 days and confirm
this to you on request. **Sub-licences:** We delete the information at the end of the Agency licence, insofar as we
do not have to retain it as evidence for the key.

## 11. Evidence and audits

On request, we provide you with the information you need to demonstrate compliance with this agreement — primarily
in writing, and for the data centres via the reports and certificates of the sub-processors. An on-site audit is
possible only for good cause, with 30 days' advance notice, during business hours, without access to other
customers' data and by a person bound to confidentiality. You bear the costs, unless the audit reveals a material
breach.

## 12. Liability and final provisions

Liability is governed by GTC section 12. In all other respects the GTC apply, including as regards the applicable
law and place of jurisdiction.
