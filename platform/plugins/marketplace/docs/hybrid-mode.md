# Hybrid mode (subscription + commission)

Marketplace mode is set at **Admin → Settings → Ecommerce → Marketplace**.

## The three modes

| Mode | Vendor pays a subscription | Marketplace takes a commission |
|---|---|---|
| Commission based | no | yes |
| Subscription based | yes | no |
| **Subscription + commission** (hybrid) | **yes** | **yes** |

Hybrid exists because subscription-only revenue does not scale with vendor volume. The
marketplace absorbs the payment gateway's fee on every transaction — typically a percentage plus
a fixed amount — so a high-volume vendor on a flat monthly plan can cost more in gateway fees
than the plan earns. Where surcharging the buyer is not permitted (for example under PSD2 in the
EU), that cost cannot be passed on, so it has to be recovered through commission.

In hybrid the commission block and the subscription block are both shown in settings, and both
apply.

## Commission rates

The global rates are the same fields commission mode uses:

- **Default commission fee (%)** — `fee_per_order`
- **Fixed commission fee (per order)** — `fee_per_order_fixed`, charged once per vendor order

Per-category commission rates, if enabled, continue to work exactly as they do in commission mode.

### Per-plan override

In hybrid only, each subscription plan gains two extra options
(**Admin → Marketplace → Subscription Plans → edit a plan**):

| Option | `-1` | `0` | any other value |
|---|---|---|---|
| Commission (%) | inherit the global percentage | this plan pays no percentage | that percentage |
| Fixed commission (per order) | inherit the global fixed fee | this plan pays no fixed fee | that amount |

Both default to `-1`, so existing plans keep the global rate until an admin changes them. The two
halves resolve independently — a plan can override the percentage and inherit the fixed fee.

This is what makes "higher plan, lower commission" possible: a €29/month plan can charge 2% while
the free default plan charges the global 5%.

**Precedence.** A per-category rate still wins where the admin configured one. The plan override
replaces the *default* rate applied to everything else. A vendor with no subscription row falls
back to the global rate.

**When a change takes effect.** A plan's options are snapshotted onto the vendor's subscription
when they subscribe, the same way the price is. Editing a plan's commission therefore applies from
each vendor's **next renewal or plan change**, not immediately. This is deliberate: a vendor keeps
the terms they signed up under.

## Refunds

A return reverses the percentage (or per-category) commission, but **not** the fixed fee. The
fixed fee is charged once per order, so refunding it on each partial return would over-credit the
vendor. This matches commission mode's existing behaviour exactly.

The rate used for a refund is the rate in force at the time of the refund. If an admin edits a
rate between the order and the return, the two can differ — a pre-existing characteristic of
commission mode, not something hybrid introduces.

## Switching modes

| From → To | Products republished? | Notes |
|---|---|---|
| commission → subscription | no | — |
| commission → hybrid | no | warns if no published default plan |
| subscription → commission | **yes** | anything expiry unpublished is restored |
| subscription → hybrid | no | subscriptions stay live, nothing to restore |
| hybrid → commission | **yes** | anything expiry unpublished is restored |
| hybrid → subscription | no | commission simply stops being charged |

Leaving a subscription-bearing mode for commission republishes every product that subscription
expiry had unpublished. Nothing else would ever republish them, and the vendor's catalogue would
stay dark.

**Before switching into subscription or hybrid, create a published default plan.** Vendors without
a subscription cannot publish products, and with no default plan for them to fall into they are
locked out of their dashboard. The settings screen warns when you save into one of those modes
with no default plan; the save still succeeds so you can create the plan next.

## The vendor's ledger

In hybrid a vendor sees both kinds of row in the same revenue ledger:

```
add-amount         sub_amount 100.00   fee 4.75   amount  95.25    (an order)
subscription-fee   sub_amount   0.00   fee 0.00   amount -29.00    (their plan payment)
```

## Known limitation: auto-renew

Subscription auto-renew charges the vendor's **marketplace balance** only. No payment gateway in
the platform can charge a stored card off-session, so there is no card-on-file renewal. If the
balance is short, the vendor is notified and renews manually from their dashboard.

Hybrid makes this materially more usable than subscription mode: commission-bearing vendors accrue
order revenue into the same balance the subscription is paid from.

## Downgrading

If the plugin is rolled back to a version without hybrid, an install left on `hybrid` degrades to
`commission` — the mode whitelist rejects the unknown value. Commission is charged and
subscriptions stop being enforced. **Switch the mode back manually before downgrading.**
