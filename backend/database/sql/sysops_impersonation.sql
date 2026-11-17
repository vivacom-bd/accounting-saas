-- Consented impersonation (plan Task 14, G4/T6, 13 §18). Owned by ledgerly_sysops (bypasses RLS), fixed search_path,
-- explicit arguments, only the columns listed. Time is passed in (p_now) from the application clock so that every
-- expiry decision uses one clock; the single-use rule does not depend on it.
--
-- Who may call what:
--   ledgerly_platform  admin_support_access_open        (is there an open grant for this tenant?)
--   ledgerly_app       impersonation_by_ticket           (exchange a ticket, once, for a session)
--                      impersonation_state               (per request: is this session still live?)
--                      end_impersonation                 (the impersonator ends it from the banner)
--                      end_tenant_impersonations         (the tenant revokes support access; bound to app.tenant_id)
-- The report role can call none of them.

-- The tenant's open support-access grant at p_now (not revoked, not expired): the latest-ending one, or no row.
CREATE OR REPLACE FUNCTION sysops.admin_support_access_open(p_tenant uuid, p_now timestamptz)
RETURNS TABLE (grant_id uuid, expires_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT g.id, g.expires_at
  FROM public.support_access_grants g
  WHERE g.tenant_id = p_tenant AND g.revoked_at IS NULL AND g.expires_at > p_now
  ORDER BY g.expires_at DESC, g.id
  LIMIT 1
$$;

-- Exchanges a ticket (its SHA-256, lowercase hex) for the session it opens, exactly once: the row is claimed by an
-- UPDATE ... WHERE consumed_at IS NULL, so two concurrent exchanges of one ticket cannot both succeed. The ticket must
-- be unexpired, the session not ended or past its end, the target an active member, and the basis still valid (an
-- open grant, or an owner override). No row = refused; the caller does not learn why.
CREATE OR REPLACE FUNCTION sysops.impersonation_by_ticket(p_hash text, p_now timestamptz)
RETURNS TABLE (id uuid, tenant_id uuid, user_id uuid, admin_id uuid, admin_name text, mode text, ends_at timestamptz)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
#variable_conflict use_column
BEGIN
  IF p_hash IS NULL OR p_hash !~ '^[0-9a-f]{64}$' OR p_now IS NULL THEN
    RETURN;
  END IF;
  RETURN QUERY
  UPDATE public.impersonation_sessions s
     SET consumed_at = p_now, started_at = p_now
   WHERE s.ticket_hash = p_hash
     AND s.consumed_at IS NULL
     AND s.ended_at IS NULL
     AND s.ticket_expires_at > p_now
     AND s.ends_at > p_now
     AND EXISTS (SELECT 1 FROM public.tenant_users tu
                  WHERE tu.tenant_id = s.tenant_id AND tu.user_id = s.user_id AND tu.status = 'active')
     AND (s.owner_override OR EXISTS (
           SELECT 1 FROM public.support_access_grants g
            WHERE g.tenant_id = s.tenant_id AND g.revoked_at IS NULL AND g.expires_at > p_now))
  RETURNING s.id, s.tenant_id, s.user_id, s.admin_id,
            (SELECT a.name::text FROM public.admin_users a WHERE a.id = s.admin_id),
            s.mode::text, s.ends_at;
END
$$;

-- One session of p_tenant, checked on every impersonated request. live = started, not ended, before ends_at, the
-- target still an active member, and still inside an open grant (unless owner override). No row for another tenant.
CREATE OR REPLACE FUNCTION sysops.impersonation_state(p_id uuid, p_tenant uuid, p_now timestamptz)
RETURNS TABLE (user_id uuid, admin_id uuid, admin_name text, mode text, ends_at timestamptz, live boolean)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT s.user_id, s.admin_id,
         (SELECT a.name::text FROM public.admin_users a WHERE a.id = s.admin_id),
         s.mode::text, s.ends_at,
         s.consumed_at IS NOT NULL AND s.ended_at IS NULL AND s.ends_at > p_now
           AND EXISTS (SELECT 1 FROM public.tenant_users tu
                        WHERE tu.tenant_id = s.tenant_id AND tu.user_id = s.user_id AND tu.status = 'active')
           AND (s.owner_override OR EXISTS (
                 SELECT 1 FROM public.support_access_grants g
                  WHERE g.tenant_id = s.tenant_id AND g.revoked_at IS NULL AND g.expires_at > p_now))
  FROM public.impersonation_sessions s
  WHERE s.id = p_id AND s.tenant_id = p_tenant
$$;

-- Ends one session of p_tenant (the banner's "End"). True when it was still open.
CREATE OR REPLACE FUNCTION sysops.end_impersonation(p_id uuid, p_tenant uuid, p_now timestamptz)
RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  n integer;
BEGIN
  UPDATE public.impersonation_sessions s SET ended_at = p_now
   WHERE s.id = p_id AND s.tenant_id = p_tenant AND s.ended_at IS NULL;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n > 0;
END
$$;

-- Ends every open session of the tenant in app.tenant_id (support access revoked). Returns how many were open and
-- not yet past their end. Refuses without a tenant context.
CREATE OR REPLACE FUNCTION sysops.end_tenant_impersonations(p_now timestamptz)
RETURNS integer
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  t uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
  n integer;
BEGIN
  IF t IS NULL THEN
    RAISE EXCEPTION 'end_tenant_impersonations needs a tenant context' USING ERRCODE = '42501';
  END IF;
  UPDATE public.impersonation_sessions s SET ended_at = p_now
   WHERE s.tenant_id = t AND s.ended_at IS NULL AND s.ends_at > p_now;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n;
END
$$;

REVOKE ALL ON FUNCTION sysops.admin_support_access_open(uuid, timestamptz) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.impersonation_by_ticket(text, timestamptz) FROM PUBLIC, ledgerly_platform, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.impersonation_state(uuid, uuid, timestamptz) FROM PUBLIC, ledgerly_platform, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.end_impersonation(uuid, uuid, timestamptz) FROM PUBLIC, ledgerly_platform, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.end_tenant_impersonations(timestamptz) FROM PUBLIC, ledgerly_platform, ledgerly_report;
GRANT EXECUTE ON FUNCTION sysops.admin_support_access_open(uuid, timestamptz) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.impersonation_by_ticket(text, timestamptz) TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.impersonation_state(uuid, uuid, timestamptz) TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.end_impersonation(uuid, uuid, timestamptz) TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.end_tenant_impersonations(timestamptz) TO ledgerly_app;
