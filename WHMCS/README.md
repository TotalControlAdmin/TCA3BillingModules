# TCAdmin 3 WHMCS Module

A WHMCS provisioning module for [TCAdmin 3](https://www.tcadmin.com/). It creates and manages
game servers in TCAdmin directly from WHMCS, and gives your customers one-click access to their
control panel.

## What it does

- **Provisions a game server** automatically when an order is accepted
- **Suspends, unsuspends, and terminates** the service in step with the billing status
- **Changes the account password** when the customer updates it in WHMCS
- **Upgrades / downgrades** the service when the customer changes package
- **Start / Stop / Restart / Force-Kill** buttons in the client area
- **One-click login** to the TCAdmin panel from both the admin and client areas

## Requirements

- A working WHMCS installation
- A TCAdmin 3 server with the **REST API enabled**, reachable from WHMCS over **HTTPS**
- A **TCAdmin API key** (see [API key & permissions](#tcadmin-api-key--permissions))

## Installation

Copy the `modules/servers/tcadmin3` folder into your WHMCS installation's `modules/servers/`
directory. No other dependencies are required.

---

## Setup

### Step 1 — Create the TCAdmin API key

In TCAdmin, sign in as an **Admin** (or SubAdmin) user and go to **Account → API Keys → Create**.
Give the key a name, choose its permissions (see [API key & permissions](#tcadmin-api-key--permissions)),
and **copy the generated key** — it is shown only once.

### Step 2 — Add the server in WHMCS

Go to **Configuration → System Settings → Products/Services → Servers → Add New Server** and set:

| Field | Value |
|-------|-------|
| **Module** | TCAdmin3 |
| **Hostname** | Your TCAdmin host (e.g. `panel.example.com`). The module connects over HTTPS. |
| **Access Hash** | Your **TCAdmin API key** from Step 1. |
| Username / Password | Not used — leave blank. |

> **Port:** the module connects over HTTPS on port **443**. If your TCAdmin REST API uses a
> different port, include it in the Hostname (e.g. `panel.example.com:31001`). Self-signed
> certificates are accepted.

Click **Test Connection** to confirm WHMCS can reach TCAdmin.

### Step 3 — Create a server group

On the same Servers page, **Create New Group**, add the server you just created, and save.
(WHMCS products attach to a *group*, so the server must belong to one.)

### Step 4 — Assign the group to your product

Edit your product → **Module Settings** tab:

1. **Module Name:** TCAdmin3
2. **Server Group:** the group from Step 3 (not `None`)
3. **Save Changes**

> The product's dropdowns are filled from your live TCAdmin server. If the Server Group is
> `None`, you'll see *"No server found so unable to fetch values"* — assigning the group fixes it.

### Step 5 — Configure the product fields

| Field | What it does |
|-------|--------------|
| **Location Type** | How the server is chosen: **Region** or **Datacenter** lets TCAdmin auto-pick a server in that location; **Server** or **Virtual Server** pins the service to a specific one. |
| **Location ID** | The numeric ID of the region / datacenter / server / virtual server selected above (find it in the TCAdmin admin panel). Required. |
| **Game ID** | The game to install — pick from the dropdown. |
| **Config File** | Advanced, optional. Extra settings not covered by these fields. Leave as `default.php` unless you need it — see [Advanced](#advanced-configuration-file). |
| **Slots** | Number of player slots. |
| **Hostname** | The service name shown in TCAdmin. |
| **RCon Password** | Optional. TCAdmin generates one if left blank. |
| **Private Password** | Optional. TCAdmin generates one if left blank. |
| Branded / Private | Reserved — not currently used. |

> **Tip:** any field can use a fixed value, or pull from the order with `CustomField:FieldName`
> or `ConfigOption:OptionName` — handy for letting customers pick their location or slots at
> checkout.

### Usernames

The module reuses the client's existing TCAdmin user, matched by billing id — an existing client
keeps their TCAdmin username. For a **new** client it uses the service's **Username** field if one
is set, otherwise the client's first name plus their last-name initial. If that name is already
taken in TCAdmin a number is appended (`jimmy` → `jimmy1`), and the WHMCS service's Username is
updated to the final TCAdmin username.

---

## TCAdmin API key & permissions

The module authenticates to TCAdmin with the API key you entered in the server's **Access Hash**.

**Easiest:** give the key **full access** (a key owned by an Admin user with full/root
permissions). This is what most setups use, and it just works.

**Least privilege:** to scope the key down, create it under an **Admin or SubAdmin** user and
grant only the permissions below — this is the confirmed minimum for a game-server product. You
pick these from the permission list when creating the key.

| Feature | Permissions to grant |
|---------|----------------------|
| Create the user & service | `User.Create`, `User.SetRoleType`, `GameService.Create` |
| Suspend / Unsuspend | `Billing.Suspend`, `Billing.Unsuspend` |
| Terminate (and remove the leftover user) | `Billing.Terminate`, `User.Delete` |
| Change password | `User.ChangePassword` |
| Client-area Start / Stop / Restart | `GameService.Control` |
| Client-area Force-Kill | `GameService.KillService` |
| One-click login (both buttons) | `Sso.Create` |
| Look-ups — users, placement, dropdowns, terminate cleanup | `User.Get`, `Server.Get`, `VirtualServer.Get`, `DockerService.Get`, `Game.Get`, `DockerBlueprint.Get`, `ServiceCategory.Get` |

> **Docker-service products.** The list above is for products that provision **game servers**. For a
> product that provisions **Docker services**, swap the three service actions for their Docker
> equivalents: `GameService.Create` → `DockerService.Create`, `GameService.Control` →
> `DockerService.Control`, `GameService.KillService` → `DockerService.KillService`. The read
> permissions are unchanged — `DockerBlueprint.Get` and `DockerService.Get` are already included.

> **Change Package (upgrade/downgrade).** Not part of the confirmed set above. If you offer
> configurable-option upgrades, also grant the service-settings permission:
> `GameService.ServiceSettings` (Docker: `DockerService.ServiceSettings`).

> **Why an Admin or SubAdmin key?** TCAdmin limits what an API key can see and do to its owner's
> own access, and one-click login can only sign in customers ranked *below* the key's owner. A
> customer-level key cannot manage other customers' services.
>
> A key only ever holds a subset of its owner's permissions, and you can edit a key's permissions
> at any time — so it's fine to start with full access and tighten later. The service-type feature
> permissions (`GameService.*`) also satisfy the read checks on their service endpoints, which is
> why `GameService.Get` isn't listed separately.

---

## One-click login (SSO)

The module adds a login button in two places, each signing the customer into **their own**
TCAdmin account and opening their service directly:

- **Admin** → the service page's **Login to TCAdmin as User** button
- **Client area** → the **Login to Control Panel** button

Both require the API key to have the **`Sso.Create`** permission and to be owned by an Admin or
SubAdmin user.

---

## Advanced: configuration file

The **Config File** field lets you send TCAdmin values that aren't exposed as product fields —
for example custom service variables, memory or disk limits, or CPU affinity.

To use it, copy `modules/servers/tcadmin3/configs/default.php` to a new name (e.g. `reseller.php`),
edit your copy, and enter that filename in the product's **Config File** field. The template is
documented inline with ready-to-uncomment examples. Leaving the field blank (or `default.php`)
changes nothing.

---

## Troubleshooting

**"No server found so unable to fetch values"** — No server is attached to the product. Complete
Steps 2–4: add the server, put it in a group, and select that group on the product.

**Test Connection fails** — Check that the Hostname reaches TCAdmin over HTTPS (add `:port` if the
API isn't on 443) and that the Access Hash is a valid API key.

**Create fails / "Module Create Failed"** — Make sure **Location Type** and **Location ID** point
to a real target, and set **Slots** if the game needs it. If you scoped the API key down, confirm
it has the permissions listed above — a missing one returns a permission error.

**Login button shows an error** — The API key needs the **`Sso.Create`** permission and must be
owned by an Admin or SubAdmin user.

Every TCAdmin call the module makes is recorded in **Configuration → System Logs → Module Log** —
the first place to look when something fails.
