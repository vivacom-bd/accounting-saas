-- Support tools (plan Task 15, 10 §3.8). Same rules as sysops_admin.sql: SECURITY DEFINER owned by ledgerly_sysops,
-- fixed search_path, explicit arguments, only the columns listed, never a secret. admin_* are EXECUTE-able by
-- ledgerly_platform alone; record_mail_sent (platform and app, both send mail) and active_announcements (app only)
-- are bound to the session's app.tenant_id.

-- Companies across tenants for the admin global search. At least two characters (after trimming), LIKE wildcards in
-- the query are literal, at most 10 rows whatever lim says. Matches name or legal name, case-insensitive.
CREATE OR REPLACE FUNCTION sysops.admin_search_companies(q text, lim integer)
RETURNS TABLE (id uuid, tenant_id uuid, tenant_name text, name text, country text, status text)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
#variable_conflict use_column
DECLARE
  v text := btrim(coalesce(q, ''));
  pattern text;
BEGIN
  IF char_length(v) < 2 THEN
    RETURN;
  END IF;
  pattern := '%' || replace(replace(replace(v, '\', '\\'), '%', '\%'), '_', '\_') || '%';
  RETURN QUERY
  SELECT c.id, c.tenant_id, t.name::text, c.name::text, c.country::text, c.status::text
  FROM public.companies c
  JOIN public.tenants t ON t.id = c.tenant_id
  WHERE c.name ILIKE pattern OR c.legal_name ILIKE pattern
  ORDER BY c.name, c.id
  LIMIT LEAST(GREATEST(coalesce(lim, 10), 1), 10);
END
$$;

-- A tenant's webhook deliveries, newest first, for the admin support page. The endpoint is reduced to its host
-- (no scheme, credentials, port, path or query: those can carry secrets). No payload, signature or response body.
-- At most 200 rows.
CREATE OR REPLACE FUNCTION sysops.admin_webhook_deliveries(t uuid, lim integer)
RETURNS TABLE (id uuid, endpoint_id uuid, host text, event_type text, status text, attempts integer, response_code integer,
               error text, last_attempt_at timestamptz, next_retry_at timestamptz, delivered_at timestamptz, created_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT d.id, d.endpoint_id,
         lower(substring(e.url from '^[A-Za-z][A-Za-z0-9+.-]*://(?:[^/?#@]*@)?([^/?#:]+)')),
         d.event_type::text, d.status::text, d.attempts::integer, d.response_code::integer, d.error::text,
         d.last_attempt_at, d.next_retry_at, d.delivered_at, d.created_at
  FROM public.webhook_deliveries d
  JOIN public.webhook_endpoints e ON e.tenant_id = d.tenant_id AND e.id = d.endpoint_id
  WHERE d.tenant_id = t
  ORDER BY d.created_at DESC, d.id DESC
  LIMIT LEAST(GREATEST(coalesce(lim, 50), 1), 200)
$$;

-- Records one sent email in the platform mail log: never the body, never the address in clear (a sha256 of the
-- lower-cased address and a masked form the caller computes). The tenant is the session's app.tenant_id (NULL outside
-- a tenant: admin and auth mail).
CREATE OR REPLACE FUNCTION sysops.record_mail_sent(p_to_hash text, p_to_masked text, p_subject text, p_notification text)
RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
BEGIN
  IF p_to_hash IS NULL OR p_to_hash !~ '^[0-9a-f]{64}$' THEN
    RAISE EXCEPTION 'to_hash must be a lower-case sha256 hex digest' USING ERRCODE = '22023';
  END IF;
  INSERT INTO public.mail_log (id, tenant_id, to_hash, to_masked, subject, notification, status, sent_at)
  VALUES (gen_random_uuid(), NULLIF(current_setting('app.tenant_id', true), '')::uuid, p_to_hash,
          left(coalesce(p_to_masked, ''), 320), left(coalesce(p_subject, ''), 255), left(p_notification, 255), 'sent', clock_timestamp());
END
$$;

-- The announcements a tenant should see now: started, not ended, and whose audience (plans by code, lifecycles,
-- countries; an absent or empty list means everyone) matches the session's tenant. Nothing without a tenant context.
CREATE OR REPLACE FUNCTION sysops.active_announcements()
RETURNS TABLE (id uuid, title text, body text, kind text, starts_at timestamptz, ends_at timestamptz)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
#variable_conflict use_column
DECLARE
  tid uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
BEGIN
  IF tid IS NULL THEN
    RETURN;
  END IF;
  RETURN QUERY
  SELECT a.id, a.title::text, a.body, a.kind::text, a.starts_at, a.ends_at
  FROM public.announcements a
  JOIN LATERAL (
    SELECT t.lifecycle::text AS lifecycle, t.country::text AS country,
           (SELECT p.code::text FROM public.plan_versions pv JOIN public.plans p ON p.id = pv.plan_id
             WHERE pv.id::text = t.settings->>'plan_version_id') AS plan
    FROM public.tenants t WHERE t.id = tid
  ) x ON true
  WHERE a.starts_at <= now() AND (a.ends_at IS NULL OR a.ends_at > now())
    AND (coalesce(jsonb_array_length(a.audience->'plans'), 0) = 0 OR (x.plan IS NOT NULL AND a.audience->'plans' @> to_jsonb(x.plan)))
    AND (coalesce(jsonb_array_length(a.audience->'lifecycles'), 0) = 0 OR a.audience->'lifecycles' @> to_jsonb(x.lifecycle))
    AND (coalesce(jsonb_array_length(a.audience->'countries'), 0) = 0 OR a.audience->'countries' @> to_jsonb(x.country))
  ORDER BY a.starts_at DESC, a.id;
END
$$;

REVOKE ALL ON FUNCTION sysops.admin_search_companies(text, integer) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.admin_webhook_deliveries(uuid, integer) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.record_mail_sent(text, text, text, text) FROM PUBLIC, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.active_announcements() FROM PUBLIC, ledgerly_report, ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_search_companies(text, integer) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_webhook_deliveries(uuid, integer) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.record_mail_sent(text, text, text, text) TO ledgerly_app, ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.active_announcements() TO ledgerly_app;
