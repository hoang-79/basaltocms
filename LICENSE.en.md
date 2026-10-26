# Basalto License (English translation)

> **Draft v0.5 of 25 October 2026 — legal review pending (R-45).**
> This text is not yet in force and not binding. The agreement (from «In short») is the same as
> [docs/recht/en/lizenzvertrag.md](docs/recht/en/lizenzvertrag.md); it translates the German draft in
> [LICENSE.md](LICENSE.md), which prevails in case of doubt (§ 13). Passages marked `[[Offen: …]]` («open») are
> deliberately undecided and follow the German draft.
> [[Offen: Have the English version proofread once the German text has been approved (review brief TB 11).]]

## Scope within this repository

The repository contains parts under different licences (ADR-002):

| Part                                                                                                   | Licence                                                                                               |
| ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Core `packages/core` (`basalto/core`), Lava `packages/lava` (`basalto/lava`), modules under `modules/` | **this Basalto License** (SPDX `LicenseRef-Basalto`)                                                  |
| Application skeleton in the root folder, later `basalto/basalto`                                       | MIT, so that `composer create-project` starts without a licence question                              |
| Themes and tools with their own licence statement                                                      | the licence in their `theme.json` or `package.json` (e.g. `themes/starter`, `tools/vscode-lava`: MIT) |
| Third-party packages (`vendor/`, `node_modules/`)                                                      | their own licences                                                                                    |

[[Offen: see the open points on scope in the German version.]]

## In short (not part of the agreement)

You may read, run and adapt the source code of Basalto for your own use. Basalto is free for one site with one
backend user — with all modules —, for development, testing and private use. If you use Basalto commercially with
several users or several sites, you need a Site or Agency licence. Basalto verifies the key offline and sends
nothing to us. Without a valid key Basalto keeps running but shows notices: in the backend immediately, on the
public site after 30 days. Basalto never disables a feature.

## § 1 Definitions

- **Licensor:** Basalto — a product of nguyen-development.ch, Hoang Nguyen, sole proprietorship, Hohfurristrasse 24,
  8408 Winterthur, Switzerland; contact for licence questions: email@basaltocms.com.
- **Software:** Basalto in source or built form, to the extent this agreement applies according to the scope in
  `LICENSE.md`: core, modules, Lava compiler and bundled themes without a licence statement of their own.
- **Installation:** one copy of the Software with its own database.
- **Site:** one web presence within an Installation (one entry in the `sites` table).
- **Production domain:** the domain name under which a Site is publicly reachable, with and without `www`.
  Subdomains of the same domain count towards it.
- **Backend user:** an account with access to the backend of a Site.
- **Commercial use:** operating a Site by or for a company, a public authority or an organisation, or with the
  intention of making a profit.
- **Development and test installation:** an Installation that is not publicly reachable or serves only for
  evaluation.
- **Private installation:** personal or family use without the intention of making a profit.
- **Licence key:** the key signed by the Licensor stating Tier, licensee, domains and limits.
- **Tier:** Free, Site or Agency.
- **Update entitlement:** the period during which you may obtain new versions.
- **Major version:** all versions sharing the same first version number.
- **Extension:** a theme or plugin that works through the documented interfaces of the Software.

## § 2 Grant and conclusion

1. The Licensor grants you a non-exclusive, non-transferable right to install and run the Software, to view its
   source code and to modify it for your own use — within the limits of your Tier (§ 3 and § 4). All other rights
   remain with the Licensor.
2. This agreement is concluded when you install or use the Software. Before the installation is completed, the
   installer — in the browser and on the console — refers to this agreement and links to it. Anyone who installs
   the Software for another person or a company warrants that they are authorised to do so.

## § 3 Free

Without a Licence key you may operate the Software with **one Site** and **one Backend user**, with all modules and
without time limit: for development and testing, privately, and for a one-person Site also commercially.
Associations, educational institutions and non-profit organisations receive a free Site licence on request.

## § 4 Licence obligation

1. If you use the Software commercially with more than one Backend user or more than one Site, you need a Site or
   Agency licence.
2. **Site licence:** covers one Production domain and up to three Sites in one Installation, with unlimited Backend
   users and languages. Development and test installations for the same domain are included.
3. **Agency licence:** covers all Sites and Installations you operate for yourself or your clients. You may issue
   sub-licence keys per client; they remain linked to your Agency licence. You may offer the Software under your own
   brand (white label).
4. You enter the Licence key within 30 days after the Site first becomes publicly reachable. Without a valid key, or
   beyond the limits of your Tier, the Software shows notices in the backend and, after 30 days, a footer notice on
   the public site. It does not restrict any feature. You must not remove these notices or circumvent the licence
   module.
5. If the previous Site ceases to exist, you may assign the key to another domain through your customer account.
6. You purchase Site and Agency licences on basaltocms.com; such purchases are governed by the [GTC](docs/recht/en/agb.md).

## § 5 Updates and support

The Site licence includes new versions for one year from purchase; the Update entitlement can be renewed. The
Agency licence includes new versions for its term. The Licensor provides security fixes for the latest Major version
for two years after its release. After the Update entitlement ends you may continue to use the versions obtained.
Support follows the offering on basaltocms.com; there are no guaranteed response times.

## § 6 Restrictions

1. You must not pass on, sell, rent or lend the Software or offer it to third parties as a hosted service. Exempt
   are agencies operating and invoicing Installations for their clients, and hosting providers operating
   Installations of their customers where the customer is the licensee.
2. Modifications for your own use are permitted. Modifying the licence module, removing copyright and licence
   notices, and distributing modified versions are prohibited.
3. A backend account must not be shared by several people in order to circumvent the limit of a Tier.
4. The Software must not be used for unlawful content or activities.

## § 7 Extensions and contributions

1. Extensions you create through the documented interfaces belong to you. You may distribute them under a licence of
   your choice, provided they contain no code of the Software beyond those interfaces and do not circumvent the
   licence module.
2. You contribute changes to the source code of the Software, for example via GitHub, under this agreement. You grant
   the Licensor the free and unrestricted right to use, modify and distribute your contribution under this or
   another agreement. Your copyright remains unaffected.
3. Third-party Extensions you obtain through the Marketplace on basaltocms.com are subject to the licence their author
   has chosen for the listing. That licence applies between you and the author; the Licensor is not a party to it
   and gives no assurance for third-party Extensions. Authors are subject to the
   [Author Terms](docs/recht/en/autoren-bedingungen.md).

## § 8 Trust clause

If the Licensor publishes no stable version of the Software for twelve months, you may use the most recently
published version under the terms of the MIT License.

## § 9 Term, termination, return

This agreement is perpetual. You may terminate it at any time by ceasing to use the Software. The Licensor may
terminate it in case of a breach after notifying you of the breach and setting a period of at least 14 days to
remedy it. Within 14 days of purchase you may return a licence for a refund (GTC section 10). Before purchase, the
Free tier and the demo serve for evaluation.

## § 10 Warranty, liability, legal templates

The Software is provided as is. Any warranty is excluded to the extent permitted by law. The Licensor is liable only
for intent and gross negligence. Liability for auxiliary persons is excluded (Art. 101 para. 2 CO). There is no
liability for indirect damage or lost profit. Liability is limited to the licence fees you paid in the twelve months
before the event. Bundled templates for legal pages (imprint, privacy policy) are aids, not legal advice; you are
responsible for their content on your Site. Mandatory statutory liability, in particular for personal injury,
remains reserved.

## § 11 Data protection

The Licence key is verified locally. The Software transmits no content and no information about visitors or users of
your Site to the Licensor; you alone are responsible for the data on your Site. Once a day the Software asks
basaltocms.com for updates; in doing so the Licensor sees the server's IP address and the version and, as long as the
setting “Usage data for the vendor” is on, receives a random ID of the installation, the PHP version and the type of
database. You can switch off the usage data at any time, and the daily check with `BASALTO_UPDATES_CHECK=false`. If you
obtain Extensions from the Marketplace or connect the installation to your customer account, the request with your
access key reaches the basaltocms.com server. The update check, the purchase, the customer account and the Marketplace
are governed by the [Privacy Policy](docs/recht/en/datenschutz.md).

## § 12 Changes to this agreement

This agreement applies in the version included with the version of the Software you obtained. The Licensor may
change it for future versions; your rights to versions already obtained remain unaffected.

## § 13 Final provisions

Swiss law applies, excluding its conflict-of-laws rules and the UN Convention on Contracts for the International
Sale of Goods. Place of jurisdiction is Winterthur. Mandatory consumer protection law at the licensee's place of
residence remains reserved. The German version prevails; translations are for information only. If a provision is
invalid, the remaining provisions stay in force; the invalid provision is replaced by a valid one that comes closest
to its purpose.

---

Copyright © 2026 Hoang Nguyen (nguyen-development.ch), Winterthur. All rights reserved except as granted by this agreement.
