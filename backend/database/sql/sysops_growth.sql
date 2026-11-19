-- Growth funnel (plan Task 16). Same rules as sysops_admin.sql: SECURITY DEFINER owned by ledgerly_sysops, a fixed
-- search_path, explicit arguments, only the columns listed. `ledgerly_sysops` has BYPASSRLS, so it can read companies,
-- journals and tenant_users across tenants; the admin role only gets EXECUTE.

-- Activation flags for a set of organisations: whether each has an active company, has posted a journal, and how many
-- active members it has. Used by the trials funnel; a tenant unknown to the caller is simply absent.
CREATE OR REPLACE FUNCTION sysops.admin_activation_flags(ids uuid[])
RETURNS TABLE (tenant_id uuid, has_company boolean, has_posted boolean, members integer)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT t.id,
         EXISTS (SELECT 1 FROM public.companies c WHERE c.tenant_id = t.id AND c.status = 'active'),
         EXISTS (SELECT 1 FROM public.journals j WHERE j.tenant_id = t.id AND j.status = 'posted'),
         (SELECT count(*)::integer FROM public.tenant_users tu WHERE tu.tenant_id = t.id AND tu.status = 'active')
  FROM public.tenants t
  WHERE t.id = ANY(ids)
$$;

REVOKE ALL ON FUNCTION sysops.admin_activation_flags(uuid[]) FROM PUBLIC, ledgerly_app, ledgerly_report;
GRANT EXECUTE ON FUNCTION sysops.admin_activation_flags(uuid[]) TO ledgerly_platform;
