-- Entitlements and usage (plan Task 10, T7). The app role has no privilege on tenant_entitlement_overrides, usage_daily,
-- usage_dedup, plans or plan_versions; these functions are its only way in, and every one is bound to the caller's own
-- tenant through the app.tenant_id GUC (an unset context raises).

-- What the calling tenant may use: {plan_code, limits{...}, entitlements{...}} with unexpired overrides applied.
-- A limit that is null means unlimited. A tenant on no resolvable plan (no catalogue loaded) gets empty maps and a null
-- plan_code; the app treats that as "nothing to enforce".
--
-- TASK 11 SWITCH POINT: the plan version a tenant is on is resolved in the single SELECT marked [plan source] below.
-- Today it is the version provisioning stored in tenants.settings->>'plan_version_id', falling back to the current version
-- of p_default_plan (config('billing.default_plan')). Task 11 replaces the first branch with the tenant's live
-- subscription; nothing else in this function or in the app changes.
CREATE OR REPLACE FUNCTION sysops.current_entitlements(p_default_plan text DEFAULT 'starter')
RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  v_tenant uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
  v_code text;
  v_limits jsonb;
  v_ent jsonb;
  o record;
BEGIN
  IF v_tenant IS NULL THEN
    RAISE EXCEPTION 'tenant context not set' USING ERRCODE = '42501';
  END IF;

  -- [plan source] 1. the version recorded on the tenant
  SELECT p.code, pv.limits, pv.entitlements INTO v_code, v_limits, v_ent
  FROM public.tenants t
  JOIN public.plan_versions pv ON pv.id = NULLIF(t.settings ->> 'plan_version_id', '')::uuid
  JOIN public.plans p ON p.id = pv.plan_id
  WHERE t.id = v_tenant;

  -- [plan source] 2. the default plan's current version (effective, highest number)
  IF v_code IS NULL THEN
    SELECT p.code, pv.limits, pv.entitlements INTO v_code, v_limits, v_ent
    FROM public.plans p
    JOIN public.plan_versions pv ON pv.plan_id = p.id AND pv.effective_from <= now()
    WHERE lower(p.code) = lower(p_default_plan)
    ORDER BY pv.version DESC
    LIMIT 1;
  END IF;

  IF v_code IS NULL THEN
    RETURN jsonb_build_object('plan_code', NULL, 'limits', '{}'::jsonb, 'entitlements', '{}'::jsonb);
  END IF;

  FOR o IN
    SELECT e.key, e.value FROM public.tenant_entitlement_overrides e
    WHERE e.tenant_id = v_tenant AND (e.expires_at IS NULL OR e.expires_at > now())
  LOOP
    IF v_limits ? o.key THEN
      v_limits := jsonb_set(v_limits, ARRAY[o.key], o.value);
    ELSIF v_ent ? o.key THEN
      v_ent := jsonb_set(v_ent, ARRAY[o.key], o.value);
    END IF;
  END LOOP;

  RETURN jsonb_build_object('plan_code', v_code, 'limits', v_limits, 'entitlements', v_ent);
END
$$;

-- Adds n to today's counter for the calling tenant. p_dedup_key makes the call idempotent: a key already seen for this
-- tenant adds nothing (an outbox message redelivered, an attachment completed twice). Only storage_bytes may go down
-- (a deleted file); every other metric only counts up.
CREATE OR REPLACE FUNCTION sysops.bump_usage(p_metric text, p_n bigint, p_dedup_key text DEFAULT NULL)
RETURNS void
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  v_tenant uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
  v_rows bigint;
BEGIN
  IF v_tenant IS NULL THEN
    RAISE EXCEPTION 'tenant context not set' USING ERRCODE = '42501';
  END IF;
  IF p_metric IS NULL OR p_metric NOT IN ('journal_lines', 'api_calls', 'storage_bytes', 'reports') THEN
    RAISE EXCEPTION 'unknown usage metric %', p_metric USING ERRCODE = '22023';
  END IF;
  IF p_n IS NULL OR p_n = 0 OR (p_n < 0 AND p_metric <> 'storage_bytes') THEN
    RETURN;
  END IF;

  IF p_dedup_key IS NOT NULL THEN
    INSERT INTO public.usage_dedup (tenant_id, dedup_key) VALUES (v_tenant, p_dedup_key) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS v_rows = ROW_COUNT;
    IF v_rows = 0 THEN
      RETURN;
    END IF;
  END IF;

  INSERT INTO public.usage_daily AS u (tenant_id, day, metric, value)
  VALUES (v_tenant, (now() AT TIME ZONE 'utc')::date, p_metric, p_n)
  ON CONFLICT (tenant_id, day, metric) DO UPDATE SET value = u.value + EXCLUDED.value;
END
$$;

-- One row per metric for the calling tenant: today (UTC), the calendar month to date, and the all-time total
-- (the storage meter is a running total, not a daily figure).
CREATE OR REPLACE FUNCTION sysops.usage_summary()
RETURNS TABLE (metric text, today bigint, month bigint, total bigint)
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  v_tenant uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
  v_today date := (now() AT TIME ZONE 'utc')::date;
BEGIN
  IF v_tenant IS NULL THEN
    RAISE EXCEPTION 'tenant context not set' USING ERRCODE = '42501';
  END IF;
  RETURN QUERY
  SELECT m.name::text,
         COALESCE(SUM(u.value) FILTER (WHERE u.day = v_today), 0)::bigint,
         COALESCE(SUM(u.value) FILTER (WHERE u.day >= date_trunc('month', v_today::timestamp)::date), 0)::bigint,
         COALESCE(SUM(u.value), 0)::bigint
  FROM (VALUES ('journal_lines'), ('api_calls'), ('storage_bytes'), ('reports')) AS m(name)
  LEFT JOIN public.usage_daily u ON u.tenant_id = v_tenant AND u.metric = m.name
  GROUP BY m.name
  ORDER BY m.name;
END
$$;

REVOKE ALL ON FUNCTION sysops.current_entitlements(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sysops.bump_usage(text, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION sysops.usage_summary() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION sysops.current_entitlements(text) TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.bump_usage(text, bigint, text) TO ledgerly_app;
GRANT EXECUTE ON FUNCTION sysops.usage_summary() TO ledgerly_app;
