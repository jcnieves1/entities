# Entity System

A self-hosted PHP + MySQL/MariaDB engine that lets an admin define **entities**
(database tables) through a web UI — fields, types, relationships — and
immediately get a working CRUD website around them: navigation, list/detail
views, forms, parent → child record trees, roles/permissions, multi-language
(English/Spanish), light/dark theme, and super-user impersonation.

Built with plain HTML/CSS/JS and PHP (PDO + MySQL) — no framework, no
build step, no Composer dependencies.

## Requirements

- PHP 7.4+ (tested on 8.1) with the `pdo_mysql` extension
- MySQL 5.7+ or MariaDB 10.3+
- Any standard web server (Apache/XAMPP/WAMP/MAMP/nginx) with PHP support

## Setup

1. Copy this `entity-system-app` folder into your web server's document root
   (e.g. `htdocs/` for XAMPP).
2. Create an empty database (default name `entity_system`) in MySQL/MariaDB.
3. Edit `config/config.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   to match your database.
4. Open `install.php` in your browser. It creates the core schema (users,
   roles, entities, fields, relationships, permissions) and asks you to
   create the initial **Super Admin** account (name, email, password, and a
   secret question/answer for password recovery).
5. Log in at `login.php` with that account.

## Using it

- **Admin area** (left nav, visible to admins/super users): create
  **Entities** — internal name, display label, whether it should appear as a
  top-level item in the left navigation, and its fields (Int, String, Date,
  Boolean, Float — with max length, default value, required flag). Saving
  creates a real SQL table (`ent_<name>`) behind the scenes.
- **Relationships**: connect a "child" entity to a "parent" entity via a
  foreign key column, as one-to-one or one-to-many (e.g. Tasks → Project).
  This lets you build parent/child entity trees (a project with many tasks,
  each stored in their own table and linked by FK).
- **Roles & Permissions**: create roles (e.g. "Editor", "Viewer") and grant
  each one View/Create/Edit/Delete per entity. Admin/Super Admin roles always
  have full access and don't need explicit permissions.
- **Users**: create accounts and assign roles. Users can also self-register
  from the login screen (a basic math captcha guards both login and
  registration); self-registered accounts get the default "Viewer" role
  until an admin adjusts it.
- **Impersonation**: from Users, a Super Admin can "Impersonate" any user to
  see the system exactly as they do (for troubleshooting). A banner appears
  at the top with a "Stop impersonating" link to return to the admin's own
  session — this only ever affects the real super user's own session.
- **Language / theme**: toggled from the top bar on every page (English /
  Spanish, light / dark), persisted per session and via cookie.

## Notes & limitations (by design, for a v1 engine)

- Once a field is created its type/length cannot be changed from the UI
  (avoids destructive `ALTER TABLE` on live data) — you can still add new
  fields to an existing entity at any time.
- The signup captcha is a simple arithmetic challenge, adequate to stop
  naive bots but not a substitute for a hardened service (e.g. reCAPTCHA)
  if you expect to be a spam target.
- `config/`, `includes/`, and `lang/` ship with `.htaccess` files denying
  direct web access (Apache). If you deploy on nginx, add an equivalent
  `location` block denying those paths.
- This was built and verified against MariaDB 10.6 / PHP 8.1 (full install →
  entity/relationship creation → CRUD → RBAC → impersonation flow tested
  end-to-end). Always run it behind HTTPS in production and turn
  `display_errors` off in `config/config.php`.
