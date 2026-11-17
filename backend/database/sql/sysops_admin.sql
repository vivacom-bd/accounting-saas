-- Platform admin reads and narrow writes inside tenants (plan Task 7, 13 §6). The `ledgerly_platform` role has no
-- access to tenant tables and is subject to RLS; these SECURITY DEFINER functions (owned by ledgerly_sysops, which
-- bypasses RLS) are its only way in. Each takes explicit arguments, returns only the columns listed, never a secret
-- (no password, remember token, 2FA secret or recovery codes, signing PIN hash), and is EXECUTE-able by
-- ledgerly_platform alone. The app and report roles cannot call them.

-- Directory figures for a page of the tenants list. At most 500 ids per call (the list pages at 200).
-- companies         = active companies (archived ones are not counted, like the plan limit)
-- users             = active memberships (invited and disabled are not counted)
-- last_activity_at  = the latest tenant audit event (audit_events.occurred_at). Every tenant write (postings,
--                     documents, settings, members: everything through the unit of work) appends one, so this is
--                     "last time anything changed in the tenant"; reads and sign-ins alone do not move it. Served by
--                     the audit_events (tenant_id, occurred_at) index as a backward index scan. NULL = never.
CREATE OR REPLACE FUNCTION sysops.admin_tenant_counts(p_ids uuid[])
RETURNS TABLE (tenant_id uuid, companies integer, users integer, last_activity_at timestamptz)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
#variable_conflict use_column
BEGIN
  IF p_ids IS NULL OR cardinality(p_ids) > 500 THEN
    RAISE EXCEPTION 'admin_tenant_counts takes 0 to 500 tenant ids' USING ERRCODE = '22023';
  END IF;
  RETURN QUERY
  SELECT t.id,
         (SELECT count(*)::integer FROM public.companies c WHERE c.tenant_id = t.id AND c.status = 'active'),
         (SELECT count(*)::integer FROM public.tenant_users tu WHERE tu.tenant_id = t.id AND tu.status = 'active'),
         (SELECT max(a.occurred_at) FROM public.audit_events a WHERE a.tenant_id = t.id)
  FROM public.tenants t
  WHERE t.id = ANY (p_ids)
  ORDER BY t.id;
END
$$;

-- The tenant's companies for the tenant page. fiscal_year_start = start of the fiscal year containing today, else
-- the latest one. Periods are regular periods only (the adjustment period is not counted);
-- open = open or soft_closed, closed = closed or locked. posted_journals = journals with status posted.
CREATE OR REPLACE FUNCTION sysops.admin_tenant_companies(p_tenant uuid)
RETURNS TABLE (id uuid, name text, status text, base_currency text, country text, fiscal_year_start date,
               open_periods integer, closed_periods integer, posted_journals bigint)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT c.id, c.name::text, c.status::text, c.base_currency::text, c.country::text,
         (SELECT fy.starts_on FROM public.fiscal_years fy
           WHERE fy.tenant_id = c.tenant_id AND fy.company_id = c.id
           ORDER BY (current_date BETWEEN fy.starts_on AND fy.ends_on) DESC, fy.starts_on DESC LIMIT 1),
         (SELECT count(*)::integer FROM public.fiscal_periods p
           WHERE p.tenant_id = c.tenant_id AND p.company_id = c.id AND p.kind = 'regular' AND p.status IN ('open', 'soft_closed')),
         (SELECT count(*)::integer FROM public.fiscal_periods p
           WHERE p.tenant_id = c.tenant_id AND p.company_id = c.id AND p.kind = 'regular' AND p.status IN ('closed', 'locked')),
         (SELECT count(*) FROM public.journals j
           WHERE j.tenant_id = c.tenant_id AND j.company_id = c.id AND j.status = 'posted')
  FROM public.companies c
  WHERE c.tenant_id = p_tenant
  ORDER BY c.name, c.id
$$;

-- The tenant's members (every membership status) for the tenant page. two_factor = TOTP confirmed.
CREATE OR REPLACE FUNCTION sysops.admin_tenant_users(p_tenant uuid)
RETURNS TABLE (user_id uuid, name text, email text, org_role text, status text, two_factor boolean, last_login_at timestamptz)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT u.id, u.name::text, u.email::text, tu.role::text, tu.status::text,
         u.two_factor_confirmed_at IS NOT NULL, u.last_login_at
  FROM public.tenant_users tu
  JOIN public.users u ON u.id = tu.user_id
  WHERE tu.tenant_id = p_tenant
  ORDER BY u.name, u.id
$$;

-- Clears a member's 2FA (secret, recovery codes, confirmation) so they enrol again at next sign-in. Only when the
-- user has a membership (any status) in p_tenant: a staff member working on one tenant cannot reach a user of
-- another. Returns true when something was cleared; false for a non-member or a user without 2FA. The user row is
-- global, so the reset applies to every organisation the user belongs to. The caller audits.
CREATE OR REPLACE FUNCTION sysops.admin_reset_two_factor(p_tenant uuid, p_user uuid)
RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  n integer;
BEGIN
  UPDATE public.users u
     SET two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_confirmed_at = NULL, updated_at = now()
   WHERE u.id = p_user
     AND EXISTS (SELECT 1 FROM public.tenant_users tu WHERE tu.tenant_id = p_tenant AND tu.user_id = p_user)
     AND (u.two_factor_secret IS NOT NULL OR u.two_factor_recovery_codes IS NOT NULL OR u.two_factor_confirmed_at IS NOT NULL);
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n > 0;
END
$$;

-- Disables or re-enables a membership. Only 'active' <-> 'disabled' (22023 for any other target); an invited
-- membership is left to the invitation flow. Never disables the tenant's last active owner. The owner rows and the
-- target row are locked in user_id order first, so two concurrent disables of the last two owners cannot both pass.
-- Returns 'updated' | 'unchanged' | 'not_member' | 'pending_invitation' | 'last_owner'. The caller audits.
CREATE OR REPLACE FUNCTION sysops.admin_set_member_status(p_tenant uuid, p_user uuid, p_status text)
RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  m record;
BEGIN
  IF p_status IS NULL OR p_status NOT IN ('active', 'disabled') THEN
    RAISE EXCEPTION 'member status must be active or disabled' USING ERRCODE = '22023';
  END IF;

  PERFORM 1 FROM public.tenant_users tu
   WHERE tu.tenant_id = p_tenant AND (tu.role = 'owner' OR tu.user_id = p_user)
   ORDER BY tu.user_id
   FOR UPDATE;

  SELECT tu.role, tu.status INTO m FROM public.tenant_users tu WHERE tu.tenant_id = p_tenant AND tu.user_id = p_user;
  IF NOT FOUND THEN
    RETURN 'not_member';
  END IF;
  IF m.status = 'invited' THEN
    RETURN 'pending_invitation';
  END IF;
  IF m.status = p_status THEN
    RETURN 'unchanged';
  END IF;
  IF p_status = 'disabled' AND m.role = 'owner' AND NOT EXISTS (
    SELECT 1 FROM public.tenant_users tu
     WHERE tu.tenant_id = p_tenant AND tu.role = 'owner' AND tu.status = 'active' AND tu.user_id <> p_user
  ) THEN
    RETURN 'last_owner';
  END IF;

  UPDATE public.tenant_users tu SET status = p_status, updated_at = now()
   WHERE tu.tenant_id = p_tenant AND tu.user_id = p_user;
  RETURN 'updated';
END
$$;

REVOKE ALL ON FUNCTION sysops.admin_tenant_counts(uuid[]) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.admin_tenant_companies(uuid) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.admin_tenant_users(uuid) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.admin_reset_two_factor(uuid, uuid) FROM PUBLIC, ledgerly_app, ledgerly_report;
REVOKE ALL ON FUNCTION sysops.admin_set_member_status(uuid, uuid, text) FROM PUBLIC, ledgerly_app, ledgerly_report;
GRANT EXECUTE ON FUNCTION sysops.admin_tenant_counts(uuid[]) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_tenant_companies(uuid) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_tenant_users(uuid) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_reset_two_factor(uuid, uuid) TO ledgerly_platform;
GRANT EXECUTE ON FUNCTION sysops.admin_set_member_status(uuid, uuid, text) TO ledgerly_platform;
