<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Entitlements follow the live subscription (plan Tasks 10 and 11): sysops.current_entitlements() reads the tenant's
 * non-cancelled subscription first, then the plan version provisioning recorded in tenants.settings, then the default
 * plan. The function body lives in this migration so `down` can restore the settings-first body of Task 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT SELECT ON subscriptions TO ledgerly_sysops');
        DB::statement('SET LOCAL ROLE ledgerly_sysops');
        DB::unprepared(<<<'SQL'
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

  -- [plan source] 1. the tenant's live subscription (any status but cancelled)
  SELECT p.code, pv.limits, pv.entitlements INTO v_code, v_limits, v_ent
  FROM public.subscriptions s
  JOIN public.plan_versions pv ON pv.id = s.plan_version_id
  JOIN public.plans p ON p.id = pv.plan_id
  WHERE s.tenant_id = v_tenant AND s.status <> 'cancelled';

  -- [plan source] 2. the version recorded on the tenant at provisioning
  IF v_code IS NULL THEN
    SELECT p.code, pv.limits, pv.entitlements INTO v_code, v_limits, v_ent
    FROM public.tenants t
    JOIN public.plan_versions pv ON pv.id = NULLIF(t.settings ->> 'plan_version_id', '')::uuid
    JOIN public.plans p ON p.id = pv.plan_id
    WHERE t.id = v_tenant;
  END IF;

  -- [plan source] 3. the default plan's current version (effective, highest number)
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
SQL);
        DB::statement('RESET ROLE');
    }

    public function down(): void
    {
        DB::statement('SET LOCAL ROLE ledgerly_sysops');
        DB::unprepared(<<<'SQL'
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
SQL);
        DB::statement('RESET ROLE');
        DB::statement('REVOKE SELECT ON subscriptions FROM ledgerly_sysops');
    }
};
