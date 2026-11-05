-- E-invoicing sweep (plan 3c.27): submissions whose time has come, across tenants, so the scheduler can queue one tenant-aware
-- job per submission. Returns ids and tenants only; the job claims the row itself under its tenant context. A submission that is
-- 'submitting' past its lease (the process died) is due too. Dead-lettered ones are never returned. `as_of` comes from the app clock.
CREATE OR REPLACE FUNCTION sysops.einvoice_submissions_due(as_of timestamptz, batch integer)
RETURNS TABLE (submission_id uuid, tenant_id uuid)
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
  SELECT s.id, s.tenant_id
  FROM public.einvoice_submissions s
  WHERE s.status IN ('pending', 'failed_retryable', 'unknown', 'submitting')
    AND s.dead_lettered_at IS NULL
    AND s.next_attempt_at <= as_of
  ORDER BY s.next_attempt_at, s.id
  LIMIT LEAST(GREATEST(batch, 1), 1000)
$$;

REVOKE ALL ON FUNCTION sysops.einvoice_submissions_due(timestamptz, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sysops.einvoice_submissions_due(timestamptz, integer) TO ledgerly_app;
