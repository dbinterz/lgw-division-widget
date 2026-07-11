# SPEC — Club-Admin Registration & Approval (OAuth-backed)

**Status:** Draft for review
**Author:** Damien Bintley
**Target version:** next release (feature bump from `2026.27.24`)
**Supersedes:** per-club passphrase as the primary submission gate (passphrase retained as fallback)

---

## 1. Goal

Replace the shared per-club passphrase as the primary scorecard-submission gate with
**verified identity + human approval**:

- **Authentication** — a real Google account (via a social-login plugin), proving *who* the
  person is. LGW writes no OAuth code and stores no tokens/secrets.
- **Authorization** — LGW maps that WordPress user to one or more clubs, gated by a WP
  administrator approving the request.

The passphrase mechanism stays functional in parallel and is retired later, per club, once
adoption is proven.

### Non-goals (v1)

- Apple / Microsoft login (Google only for now; adding them later is plugin config, no LGW change).
- Scoping WP administrators to specific clubs (admins remain able to submit for any club).
- Automatic approval by email domain (clubs have no consistent domains — human step is required).

---

## 2. Decisions (locked)

| Question | Decision |
|---|---|
| OAuth provider (v1) | **Google only**, via existing social-login plugin (Nextend free tier or equivalent). |
| Who approves | **WP `administrator` role** (`current_user_can('manage_options')`). No custom approver capability. |
| Club selection | **Dropdown** sourced from existing clubs (`get_option('lgw_clubs')`). |
| Trust unit | **Multiple approved admins per club; a user may admin multiple clubs.** |
| Notifications | **WP admin screen + email** to admins on new request; email to user on decision. |
| Migration | **Both mechanisms run in parallel** (`lgw_auth_mode`); no forced deadline yet. |
| Existing accounts | **Untouched.** WP admins already bypass passphrase; they keep full submit rights. |

### Consequence to accept
`administrator` = can submit for **any** club (unchanged from today). Acceptable because admins
are trusted league officers. Scoping admins is a separate future change, not v1.

---

## 3. Identity model — grounding in current code

Facts the design must respect (verified in source):

- Auth club is held in **PHP session**: `$_SESSION['lgw_club']` (`lgw-scorecards.php:25`).
- Clubs have **no stable numeric ID** — identified by **name string**.
  `lgw_clubs` option = `array( ['name'=>'Ards','pin'=>'<sha256>','can_submit'=>1], ... )`
  (`lgw-scorecards.php:34`).
- `lgw_club_matches_team()` already resolves "Ards" ↔ "Ards A"/"Ards B" (`lgw-scorecards.php:51`).
- WP admins are already allowed past the passphrase gate (`lgw-cup.php:111`).

**Therefore the user→club mapping stores club NAME strings, not IDs.**

---

## 4. Data model (new)

### Role & capability (created on activation)
- Role: `lgw_club_admin`
- Capability: `lgw_submit_scorecard`
- The role grants **only** `lgw_submit_scorecard` + `read`. No `edit_posts`, no wp-admin dashboard
  access (least privilege — keep club admins off the back end).

### User meta
| Meta key | Type | Meaning |
|---|---|---|
| `lgw_approval_status` | string | `pending` \| `approved` \| `rejected` \| `revoked` |
| `lgw_clubs` | array of club-name strings | Clubs this user is **approved** to submit for. Empty unless approved. |
| `lgw_requested_clubs` | array of club-name strings | Clubs requested at registration (pre-approval). |
| `lgw_request_note` | string | Free-text note from user (their role / contact). |
| `lgw_approved_by` | int | WP user ID of approving admin. |
| `lgw_approved_at` | int (timestamp) | When approved/decided. |
| `lgw_decision_reason` | string | Reason on reject/revoke (shown in emails / audit). |

### Options
| Option | Default | Meaning |
|---|---|---|
| `lgw_auth_mode` | `both` | `passphrase` \| `both` \| `login`. Controls which gates the modal offers. |
| `lgw_admin_notify_emails` | admin_email | Comma-list of recipients for new-request notifications. |

---

## 5. Flows

### 5.1 Registration (front-end, logged-in user)
1. User logs in via Google (social plugin) → becomes a standard WP user.
2. Redirected to **"Request club-admin access"** form (shortcode or fixture-modal panel).
3. Form fields: **club(s)** (multi-select dropdown from `lgw_get_clubs()`), **note** (their role/contact).
4. Submit (nonce-protected, logged-in-only, rate-limited):
   - Sets `lgw_approval_status = pending`, `lgw_requested_clubs`, `lgw_request_note`.
   - Fires email to `lgw_admin_notify_emails`.
5. Confirmation screen: *"Request received. Please contact a league administrator to confirm your
   details."* + admin contact info.

**Idempotency:** re-submitting while `pending` updates the request, does not duplicate. A user
already `approved` sees a "manage my clubs / request more" variant.

### 5.2 Approval (WP admin, **LGW → Access Requests**)
- Menu visible only to `manage_options`.
- Table of users with `lgw_approval_status = pending`: name, email, provider, requested clubs, note, date.
- Row actions:
  - **Approve** — admin selects which clubs to grant (may differ from requested). Sets role
    `lgw_club_admin`, `lgw_clubs`, `lgw_approval_status = approved`, `lgw_approved_by/at`. Emails user.
  - **Reject** — sets `rejected` + reason. Emails user.
- Second tab **Approved / Revoke**: list approved users; **Revoke** action clears `lgw_clubs`,
  sets `revoked`, removes role. Emails user.
- All actions nonce-protected + `manage_options` re-checked server-side.

### 5.3 Submission gate (the swap)
New precedence in `lgw_get_auth_club()` / submit handlers:

```php
// 1. WP administrator → unchanged, may submit for any club (admin override).
// 2. Logged-in approved club admin:
if ( is_user_logged_in() ) {
    $uid = get_current_user_id();
    if ( current_user_can('lgw_submit_scorecard')
         && get_user_meta($uid,'lgw_approval_status',true) === 'approved' ) {
        $user_clubs = (array) get_user_meta($uid,'lgw_clubs',true);
        // club resolved from user meta + fixture, NOT from session token
        // if fixture matches exactly one of $user_clubs -> use it
        // if multiple candidates -> require explicit club choice in the modal
    }
}
// 3. Passphrase path (session token) — only when lgw_auth_mode !== 'login'.
```

- Downstream two-party confirm/amend/dispute flow and cup-context tagging are **unchanged** —
  they consume the resolved club name the same way regardless of how it was authorised.
- When a logged-in user admins **multiple** clubs and both could match a fixture, the modal must
  prompt "submit as which club?" rather than guessing.

### 5.4 Migration control (`lgw_auth_mode`)
- `passphrase` — current behaviour, login path hidden.
- `both` (default rollout) — modal shows **"Log in to submit"** *and* the passphrase field.
- `login` — passphrase field hidden; login required. Passphrase code stays but dormant.
- Submission method is already tagged (`lgw-cup.php:148`) — use it to monitor adoption before flipping.

---

## 6. Security requirements

- **Verified email ≠ authorization.** A Google account is *anyone*. The human approval step is the
  only thing binding a person to a club. No auto-approve.
- **Identity by WP user ID, never email string.** (Future-proofs against Apple private-relay
  addresses and email changes.)
- **Revocation is first-class from day one** — not a later add-on. Multiple-admins-per-club widens
  the surface; make removing access a one-click, audited action.
- **Least privilege** — `lgw_club_admin` grants only submission, no dashboard/editing.
- **All state-changing endpoints** (register, approve, reject, revoke) require: logged-in + nonce +
  server-side capability re-check. Never trust a client-sent role/status/club.
- **OAuth secrets live only in the social-login plugin config**, never in LGW source or git.
- **Rate-limit** the registration endpoint to blunt spam requests.

---

## 7. Deliverables / file plan

| File | Change |
|---|---|
| `lgw-club-access.php` (**new**) | Role/cap registration, user-meta model, registration form handler, admin "Access Requests" screen, email helpers, gate helper `lgw_user_submit_clubs()`. |
| `lgw-scorecards.php` | `lgw_get_auth_club()` gains logged-in branch; passphrase gated behind `lgw_auth_mode`. |
| `lgw-cup.php` / submit handlers | Honour logged-in auth alongside score-token path. |
| `lgw-scorecard.js` | Modal: "Log in to submit" affordance; multi-club "submit as" prompt; hide passphrase field per `lgw_auth_mode`. |
| `lgw-division-widget.php` | Require new file; activation hook registers role/cap; settings UI for `lgw_auth_mode` + notify emails. |
| Deactivation/uninstall | Remove custom role/cap cleanly; decide whether to keep user meta. |

---

## 8. Build order (vertical slices)

1. **Slice A (no OAuth needed):** role/cap + user-meta model + gate swap + `lgw_auth_mode`.
   Testable with plain WP login. Lowest risk. *(Recommended first.)*
2. **Slice B:** registration form + admin Access Requests screen + emails + revoke.
3. **Slice C:** modal UX (login affordance, multi-club prompt, passphrase hide).
4. **Slice D (your side, no LGW code):** install + configure Google social-login plugin.

---

## 9. Open questions

1. **Migration end-state** — indefinite `both`, or a target date to flip divisions to `login`? (TBD.)
2. **Registration surface** — dedicated page/shortcode, or embed the request panel inside the
   existing fixture-submission modal?
3. **Deactivation policy** — on plugin deactivate, keep `lgw_clubs`/status user meta (so re-activate
   restores access) or purge?
4. **Multiple-club submit UX** — explicit dropdown every time, or remember last choice per fixture?

---

## 10. Test checklist (acceptance)

- [ ] New user, Google login, requests 1 club → appears as `pending` in admin screen; admin emailed.
- [ ] Admin approves → user gains role + `lgw_clubs`; user emailed; can submit for that club only.
- [ ] Approved user **cannot** submit for a club they don't administer.
- [ ] User admin of 2 clubs is prompted "submit as which club?" on an ambiguous fixture.
- [ ] Revoke removes access immediately; user emailed; subsequent submit blocked.
- [ ] `lgw_auth_mode=passphrase` → login path hidden, passphrase still works.
- [ ] `lgw_auth_mode=login` → passphrase hidden, login required.
- [ ] Existing WP administrator submits for any club with no change in behaviour.
- [ ] All state-changing endpoints reject missing/invalid nonce and non-capable users.

---

## Version bump (per release rule)

Feature release → bump across: plugin header + `LGW_VERSION` (`lgw-division-widget.php`),
`readme.txt` (Stable tag + changelog), `README.md`, and modified-file comments.
