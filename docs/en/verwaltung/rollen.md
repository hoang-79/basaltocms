# Roles

A **role** determines what someone may do in the backend — a **level** per **area**, plus the **languages** in which someone may edit content. A person has exactly one role per site; the roles themselves apply to all sites of the installation.

## Where

“Administration” mode › **Roles** (or the “Roles” button under “Users”).

![The roles with the matrix of the role “Administrator”](bilder/rollen.webp)

On the left are the roles — for each, how many people have it, which modes it sees (“sees: Content · Setup · Administration”) and which languages. Labels show **“unchangeable”** (Owner), **“adjusted”** (a template with own changes) or **“own”** (created by you). On the right the selected role:

1. **Name** and **description** (optional) — the description appears below the role when inviting.
2. **Languages**: **all**, **all except the source language** or a **selection**. Below it says in which languages the role may edit content. Creating, deleting and moving pages and changing their settings requires the source language.
3. **The matrix** — a level per area, grouped into Content, Setup and Administration. **“all to”** sets a whole group to “none”, “view” or “edit”. Areas from modules carry “Module”.
4. **Preview** at the bottom — what the role sees and how many people have it. **“Save”** applies the change; it takes effect from the next request. Rights never change on the side.

| Level | Means |
| --- | --- |
| **none** | The area is missing from the menu. |
| **view** | read without changing anything |
| **edit** | create and change |
| **delete** | delete as well |
| **everything** | also the sensitive settings of the area — for example a form’s webhook or the AI suggestions’ key |

If a role sees no area in a group, the mode for it (for example “Setup”) is missing too.

## The templates

| Template | May |
| --- | --- |
| **Owner** | everything; unchangeable so that no site locks itself out. Every site keeps at least one owner. |
| **Administrator** | everything except assigning the role Owner |
| **Editor** | pages, media and forms up to “delete”, translation, comments, members and AI suggestions “edit”; no setup, no administration, no approving |
| **Approver** | approval and publishing “everything”; pages, media, translation, forms and comments “view” |
| **Translator** | pages and translation “edit”, media “view”, AI suggestions “edit” — in all languages except the source language |

**“Reset”** brings an adjusted template back to the factory state (except Owner).

## Own roles

- **“New role”** creates an empty role (it may do nothing) — or a copy of an existing one. **“Copy”** at the top right copies the selected role.
- **“Delete”** removes an own role as long as nobody has it and no open invitation refers to it.

## Rules nobody gets around

- Changing roles requires “Users and roles · everything”; viewing requires “view”.
- Whoever assigns a role needs at least the same level in every area and each of its languages.
- API tokens act with the rights of their person.
- Everything is in the [activity log](protokoll.md): created, changed (before and after), reset, deleted and every assignment.

More background for the technical side: [Roles and rights](../betrieb/rollen.md).

## Next

- [Users](benutzer.md)
- [Roles and rights](../betrieb/rollen.md)
