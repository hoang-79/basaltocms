# “CRM” plugin

With the “CRM” you keep your customers in one place: companies and persons with customer number, address, contact
persons, activities and their lifecycle stage — from the first contact to the former customer. If you also have the
“Accounting”, “Project management” or “Kanban board” plugins, they pick customers straight from the CRM. The plugin is
paid — CHF 99 with one year of updates — and comes from Basalto.

![A customer in the CRM with lifecycle, contact persons and activities — available to accounting, project management and the Kanban board](bilder/plugin-crm.svg)

## Install and switch on

1. Buy it on basaltocms.com — once per installation ([Buying](installieren.md#buying)).
2. In the backend under **Administration › Modules › Marketplace**, click **“Install”** for “CRM”
   ([Installing a plugin](installieren.md#installing-a-plugin)).
3. Switch it on under **Administration › Sites › Edit site › “Modules” tab**. It is off by default.

Afterwards **“CRM”** appears under **Content**. The number next to it counts the open tasks due today or earlier.

## Create a customer

1. Under **CRM**, click **“New customer”**.
2. Choose the **Type**: **Company** or **Person**. The **Country** is set to Switzerland — if the customer is in another
   country, choose it first (list with flags; typing searches).
3. For a company, type the **Company name**: from three letters, suggestions from the country’s company register appear
   below. Choose one and the CRM fills in street, postcode, town and UID. For a person, enter **First name** and **Last
   name** — they then form the name. A company does not have these fields; enter its contacts under **Contact persons**.
4. As soon as you leave the field, the customer is created — there is no “Create” button. The customer’s page opens, the
   cursor stays in the field you were in, and anything you typed in the meantime is kept.
5. Add the rest: **Lifecycle** (see below), **“Also a supplier”** if you also buy from this customer, email, phone,
   address and, if you like, **Language**, **Website**, **Responsible** and **Notes**.

Every field saves itself: “saved” briefly appears at the field. If something is not right, the message appears in red
at the field, and that field stays unsaved until it is correct. The CRM assigns the **customer number** itself:
“K-0001”, “K-0002” and so on, without gaps.

### Help while filling in

- **Street and number:** type street and house number and the CRM suggests matching addresses in the country.
  Choosing one fills in postcode and town.
- **Phone:** the flag with the dialling code stands in front of the field. The field accepts digits only, as many as
  numbers in that country have, and groups them as is usual there (“79 123 45 67”). The flag follows the customer’s
  country; if you choose another flag in the phone field, the country changes too — as long as no postcode is entered.
- **Notes:** a click into the field opens the editor with all tools (headings, lists, links, tables and more) in a
  window. What you write is taken over at once; “Done” or Escape closes the window.
- **Where the suggestions come from:** companies from the UID register of the Federal Statistical Office (Switzerland),
  from “Recherche d’entreprises” (France) and otherwise from GLEIF (only companies with an LEI); addresses from the
  official register of building addresses via swisstopo (Switzerland), from the Base Adresse Nationale (France) and
  otherwise from OpenStreetMap. Your website asks these services through its server — your browser does not talk to
  them directly. Both can be switched off under **Administration › Settings › Lookup**. If nothing matches, what you
  typed stays.

| Field | What is checked |
| --- | --- |
| **Country** | choice with flag; Switzerland by default |
| **Phone** | digits only, as many as numbers in the country have; stored in international format (“+41 79 123 45 67”) |
| **Postcode** | Switzerland and Liechtenstein: four digits from 1000 to 9658 · Austria: four digits · Germany, France, Italy: five digits · other countries: up to 12 characters |
| **UID** | if it starts with “CHE”, it has the form CHE-123.456.789, optionally followed by “MWST” |
| **Email** | a valid address; it is stored in lower case |

## Lifecycle

| Stage | Meaning |
| --- | --- |
| **Lead** | first contact, no conversation yet |
| **Prospect** | you are in talks or have sent a quote |
| **Customer** | there is an order — this is the default |
| **Former customer** | no order at the moment |

Change the stage on the customer or for several at once: select them in the list and choose **“Lifecycle: …”** at the
bottom.

**Your own values:** if a stage is missing, type it into the **Lifecycle** choice and select **“+ Add “…””** — likewise
for the **type** of an activity. The **role** of a contact person suggests common roles by department (management,
finance, purchasing …); simply type any other. The lists are maintained under **Administration › Choice lists**.

## Contact persons

For a company, record under **Contact persons** the people you deal with: first name, last name, **Function**, email
and phone (in the customer’s country). One of them is the **Main contact** — the first one automatically; tick **“Main contact”** on another one to
switch. A click on a contact person opens it for editing; it can also be deleted there.

**Good to know:** With the “Project management” plugin, the email address of a contact person opens this customer’s
projects in the website’s customer account.

## Activities and tasks

Under **“Record activity”** you note what has happened with the customer or is still to come:

| Type | For |
| --- | --- |
| **Note** | what you want to remember |
| **Call**, **Meeting**, **Email** | conversations with date and optionally a contact person |
| **Task** | something to do — with **“Due on”** |

You write the **note** of an activity in the editor with all tools, like the customer’s notes — a click into the field
opens it. With **“Record”** the activity appears in the list; there is a button here because an activity is only
complete with type, date and note.

Open tasks are listed first on the customer; once the date has passed they turn red. **“Mark as done”** completes a
task. The key figure **“Tasks due”** above the list and the number next to the menu entry count all open tasks due today
or earlier.

## Ask for a review

Reviews on Google or other platforms help new customers choose. The CRM sends the request for you — by hand, when it
fits, for example after a completed project.

1. Once, under **CRM › “Reviews”**, enter the **review link**: ideally a page on your website that leads to the review
   form — or the link from your Google Business Profile (“Ask for reviews”). If you like, write your **own text** there;
   left empty, the text comes in the customer’s language.
2. On the customer, click **“Ask for a review”**. The primary contact comes first as recipient; you can choose any
   contact person with an email address and the customer’s own address. **Subject** and **text** are filled in —
   German, English, French or Italian according to the customer’s **Language** field — and can be changed.
3. **“Send”** sends the email: greeting, your text, the **“Write a review”** button with your link and your name in
   the closing. The customer then shows an activity “Review request” with recipient and text; opening the form again
   shows when you last asked.

In the “Project management” plugin, **“Ask the customer for a review”** leads there directly for a completed project
with a customer from the CRM.

**Ask fairly:** ask all customers alike, not only satisfied ones, and offer nothing in return — Google and the other
platforms require this.

## Find customers

The list shows all customers that are not archived, by name. The **search** finds names, customer numbers, towns, email
addresses, UID and contact persons — umlauts and upper or lower case do not matter: “zurich” finds Zürich. Several words
must all occur (“anna keller”). You can also filter by **Lifecycle** and under **List** by **Active**, **Suppliers** or
**Archive**.

## Archive instead of delete

Customers are not deleted but archived — on the customer with **“Archive”** or for several in the list. An archived
customer can still be viewed and changed but no longer appears in any selection of the other plugins; invoices, projects
and cards keep it. Under **List › Archive** you bring it back with **“Restore”**.

## Import and export

Under **CRM › Import and export**:

- **“Export all customers”** downloads all customers that are not archived as a CSV file.
- **“Download template”** gives a file with the columns and a sample row.
- **“Import a list”** accepts a CSV file.

The file uses semicolons as separators and is saved in UTF-8 — in Excel as “CSV UTF-8”. The columns are called
**Customer number**, **Type**, **Company name**, **First name**, **Last name**, **Lifecycle**, **Supplier**, **Email**,
**Phone**, **Website**, **Street and number**, **Postcode**, **Town**, **Country**, **UID**, **Language** and **Notes**;
the header row may also be in German, French or Italian, and not every column has to be present. When importing:

- A row with a **known customer number** or **email address** changes that customer instead of creating a duplicate.
  Empty cells and missing columns leave its details as they are.
- All other rows become new customers with the next customer number.
- A row with errors — such as a wrong postcode — is skipped; the message names it with its row number. The other rows
  are imported.

Notes appear in the export as text without formatting, phone numbers in the format of their country.

**Good to know:** values that Excel would read as a formula (such as a phone number starting with “+”) get an
apostrophe in front in the export. It is removed again on import.

## Working with other plugins

As soon as a customer is created, the other plugins can pick it — without any further step:

| Plugin | What it does with the customer |
| --- | --- |
| **Accounting** | customers as debtors for invoices, suppliers as creditors |
| **Project management** | projects per customer; the email addresses of customer and contact persons open the projects in the customer account |
| **Kanban board** | attach cards to customers |

If you change the name or address in the CRM, the other plugins take over the change. What they know about a customer —
invoices, projects, cards — is listed on the customer under **“Linked”**.

![Four plugins, one customer: CRM, accounting, project management and Kanban board](bilder/geschaeft-zusammenspiel.svg)

## Data protection

- **Access and erasure** per email address are handled by the administration under “Administration › Users” (or
  `php artisan basalto:privacy`): the information lists customers with this address including activities and contact
  persons. Erasure removes contact persons with this address; a customer who is a person loses name, address, phone,
  notes and activities — the customer number remains so that invoices and projects keep their reference. For a company
  only the email address is removed.
- The **export of a site** does not take the customers along — use the CSV export for that.

## If something goes wrong

| Sign | Cause | Solution |
| --- | --- | --- |
| “Postcodes in Switzerland and Liechtenstein have four digits (1000–9658).” | postcode and country do not match | enter the right ISO code under **Country**, such as DE for Germany |
| “A company needs a company name.” | type “Company” without company name | enter the company name or choose the type “Person” |
| A customer is missing from the selection in accounting or a project | it is archived, or the CRM is off for this site | restore it under **List › Archive** or switch on the CRM under the site’s “Modules” |
| “The file has no recognisable header row …” | the first row names no known columns | download the template and use its header row |
| Umlauts are wrong after the import | the file is not saved in UTF-8 | choose “CSV UTF-8” in Excel |
| A customer exists twice after the import | neither customer number nor email address matched | archive the duplicate; next time include the **Customer number** column from the export |

## Next

- [For installations](installieren.md)
- [Marketplace](README.md)
- [“Club fees with QR-bill” plugin](plugin-vereinsbeitraege.md)
