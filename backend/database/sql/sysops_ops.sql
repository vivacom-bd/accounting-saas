-- Platform operations (plan Task 17). Maintenance mode and per-tenant rate limits are platform data the tenant app role
-- must read without holding a grant on the tables: both functions return only what the caller needs.

-- Maintenance switch (T8): the `maintenance` row of platform_settings. Off when unset, disabled or past its end time.
CREATE OR REPLACE FUNCTION sysops.platform_maintenance()
RETURNS TABLE (enabled boolean, message text, ends_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  WITH s AS (SELECT p.value FROM public.platform_settings p WHERE p.key = 'maintenance'),
       v AS (
         SELECT COALESCE((s.value ->> 'enabled')::boolean, false) AS on_flag,
                NULLIF(s.value ->> 'message', '') AS msg,
                NULLIF(s.value ->> 'ends_at', '')::timestamptz AS ends
         FROM s
       )
  SELECT COALESCE(v.on_flag AND (v.ends IS NULL OR v.ends > now()), false), v.msg, v.ends
  FROM (SELECT 1) one LEFT JOIN v ON true
$$;

-- The current tenant's rate-limit override (bound to app.tenant_id), none when absent or expired.
CREATE OR REPLACE FUNCTION sysops.tenant_rate_limit()
RETURNS TABLE (per_minute integer, expires_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT r.per_minute, r.expires_at
  FROM public.tenant_rate_limits r
  WHERE r.tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
    AND (r.expires_at IS NULL OR r.expires_at > now())
$$;

-- Outbox backlog for the admin health page: undispatched messages and the oldest one. Counts only; never a payload.
CREATE OR REPLACE FUNCTION sysops.admin_outbox_backlog()
RETURNS TABLE (pending bigint, oldest timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT count(*), min(m.occurred_at) FROM public.outbox_messages m WHERE m.dispatched_at IS NULL
$$;

REVOKE ALL ON FUNCTION sysops.platform_maintenance() FROM PUBLIC, ledgerly_report, ledgerly_platform;
REVOKE ALL ON FUNCTION sysops.tenant_rate_limit() FROM PUBLIC, ledgerly_report, ledgerly_platform;
REVOKE ALL ON FUNCTION sysops.admin_outbox_backlog() FROM PUBLIC, ledgerly_app, ledgerly_report;
GRANT EXECUTE ON FUNCTION sysops.platform_maintenance() TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.tenant_rate_limit() TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.admin_outbox_backlog() TO ledgerly_platform;
