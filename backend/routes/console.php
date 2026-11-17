<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// 13 §15 scheduler. Per-tenant work fans out through sysops.tenants_due_for, never a tenant loop.
Schedule::command('outbox:sweep')->everyTenSeconds()->withoutOverlapping()->onOneServer();
// Practice-overview figures flagged by posting and period events (13 §7), coalesced per tenant.
Schedule::command('kpi:refresh')->everyFifteenSeconds()->withoutOverlapping()->onOneServer();
// Webhook deliveries that are due: first attempts that missed the queue, and every retry (stored next_retry_at).
Schedule::command('webhooks:sweep')->everyTenSeconds()->withoutOverlapping()->onOneServer();
Schedule::command('einvoice:sweep')->everyThirtySeconds()->withoutOverlapping()->onOneServer();
Schedule::command('idempotency:purge')->hourly()->onOneServer();
// Verify before sealing: the nightly check starts after the newest valid seal, so it covers everything written since, and the seal then pins the tip.
Schedule::command('audit:verify-all')->dailyAt('01:00')->timezone('UTC')->onOneServer();
Schedule::command('audit:verify-all --full')->weeklyOn(0, '03:00')->timezone('UTC')->onOneServer();
Schedule::command('audit:seal')->dailyAt('01:30')->timezone('UTC')->onOneServer();
// Stored balances against the posted journal lines, journal balance and trial balance (13 §9, plan 4.2); findings are recorded and owners told.
Schedule::command('ledger:check-drift')->dailyAt('02:00')->timezone('UTC')->onOneServer();
// ECB publishes around 16:00 CET; fetch after that, then retry pending FX revaluation reversals.
Schedule::command('fx:fetch-rates')->dailyAt('17:30')->timezone('Europe/Berlin')->onOneServer();
// Uploaded files whose scan is still pending: queue them again (scanner was down, or the sync queue skipped them).
Schedule::command('attachments:scan-pending')->everyMinute()->withoutOverlapping()->onOneServer();
// Recurring invoices, bills and journals that are due become drafts (never posted); each company's own date applies.
Schedule::command('recurring:run')->dailyAt('02:00')->timezone('UTC')->onOneServer();
// Documents that have waited on an approval step past their workflow's reminder time (plan 3c.7); once per interval, unique in the database.
Schedule::command('approvals:remind')->hourly()->onOneServer();
// Overdue-invoice reminders for companies that switched them on (plan 3c.7); each company's own date applies.
Schedule::command('dunning:run')->dailyAt('06:00')->timezone('UTC')->onOneServer();
// Scheduled report deliveries (plan 3c.23): every hour, each company's own timezone and hour decide what is due; one run per schedule and date, unique in the database.
Schedule::command('reports:run-schedules')->hourly()->onOneServer();
// Platform admin audit chain: recompute it every night; a broken link exits non-zero (plan T10).
Schedule::command('admin-audit:verify')->dailyAt('03:30')->timezone('UTC')->onOneServer();
// Tenant lifecycle clocks (plan Task 5, G5/G6): trials and overdue payments that ran out move on, each step dated when due.
Schedule::command('lifecycle:advance')->dailyAt('00:15')->timezone('UTC')->withoutOverlapping()->onOneServer();

// Offline (invoiced) subscriptions: due cancellations, ended pauses, renewals (plan Task 11).
Schedule::command('subscriptions:advance')->dailyAt('00:30')->timezone('UTC')->withoutOverlapping()->onOneServer();

// SaaS dunning (plan Task 12): payment reminders on days 1, 4 and 7 after a failure, trial-ending notices 3 and 1 days before; once each.
Schedule::command('saas-dunning:run')->dailyAt('07:00')->timezone('UTC')->onOneServer();
