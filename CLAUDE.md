# CLAUDE.md — parttime.q8ee.com (المنتدبون / Part-timers & Interns Management)

> **Status: NOT STARTED — scope not yet decided.** This folder is scaffolding
> only. Before writing any code, brainstorm the scope with Dr. Mishal
> (superpowers:brainstorming) and record the agreed design here + in a
> `docs/` spec. Do not assume features.

## What this project is (intended)

A management system for the department's **interns and part-time instructors
(المنتدبون / part-timers)** at the Electrical Engineering Technology
Department, College of Technological Studies, PAAET, Kuwait.

Likely responsibilities (to confirm in brainstorming): register part-timers/
interns, track which courses/sections they teach, hours/load, contact and
official info, contracts/periods, and reporting for the department. It may
connect conceptually to the scheduling tool (jadawil — instructor names) and
to help.q8ee.com, but it is a **separate project/repo**.

## Open scope questions (answer before building)

- Exact records to manage (interns vs part-timers — same or separate?).
- Fields per person; any sensitive data (civil ID → encrypt + mask, follow
  the help.q8ee.com pattern).
- Who uses it: admin-only, or do part-timers log in? Roles?
- Link to jadawil instructor data / SWRSCHA, or standalone?
- Reports/exports needed (Excel/PDF, RTL).

## Stack (proposed — confirm)

Two established patterns to choose from:
- **Laravel 12 + MySQL + Bootstrap 5 RTL, Blade, no build step** — same as
  help.q8ee.com (best for a real multi-user admin app with auth/roles).
- **Self-contained single-file tool** — same as jadawil (best for a
  focused, mostly-client tool).

Pick per scope. Arabic-first, RTL, complete translation coverage from day one.

## Conventions (carry over from the other projects)

- **Formal, undiacritized Arabic** in all UI/shared copy (no tashkeel).
- Owner: Dr. Mishal E. AlSharidah — د. مشعل إبراهيم الشريده.
- Deploy target: the alsharidah.shop server (Apache vhost at
  parttime.q8ee.com); a `deploy/deploy.sh` like the sibling projects.
- Private GitHub repo when it starts.
- Sensitive data encrypted at rest + masked in UI, reveals audit-logged.

## Next step

Run superpowers:brainstorming to define scope → write `docs/` spec → then
scaffold. Nothing is built yet.
