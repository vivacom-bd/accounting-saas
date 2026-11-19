-- Tenant-side billing (plan Task 24, 4b.8). The app role has no privilege on subscriptions, stripe_invoices, plans or
-- plan_versions; this function is its only way to read its own subscription and invoices, bound to app.tenant_id.
-- Read-only. Money is integer minor units; `monthly_amount` is normalised like MrrMovement (a yearly amount is one
-- twelfth, rounded half up), so the tenant page agrees with the operator dashboard.
CREATE OR REPLACE FUNCTION sysops.tenant_billing_summary()
RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $$
DECLARE
  v_tenant uuid := NULLIF(current_setting('app.tenant_id', true), '')::uuid;
  v_sub jsonb;
  v_inv jsonb;
BEGIN
  IF v_tenant IS NULL THEN
    RAISE EXCEPTION 'tenant context not set' USING ERRCODE = '42501';
  END IF;

  SELECT jsonb_build_object(
    'status', s.status,
    'interval', s.interval,
    'currency', s.currency,
    'custom_amount', s.custom_amount,
    'quantity', s.quantity,
    'trial_ends_at', s.trial_ends_at,
    'current_period_end', s.current_period_end,
    'cancel_at', s.cancel_at,
    'pause_until', s.pause_until,
    'plan_code', p.code,
    'plan_name', p.name,
    'monthly_amount', CASE WHEN s.interval = 'year'
      THEN (COALESCE(s.custom_amount, (pv.prices -> s.currency ->> 'annual')::bigint) * s.quantity + 6) / 12
      ELSE COALESCE(s.custom_amount, (pv.prices -> s.currency ->> 'monthly')::bigint) * s.quantity
    END
  ) INTO v_sub
  FROM public.subscriptions s
  JOIN public.plan_versions pv ON pv.id = s.plan_version_id
  JOIN public.plans p ON p.id = pv.plan_id
  WHERE s.tenant_id = v_tenant AND s.status <> 'cancelled'
  ORDER BY s.created_at DESC
  LIMIT 1;

  SELECT COALESCE(jsonb_agg(x), '[]'::jsonb) INTO v_inv FROM (
    SELECT id, number, status, currency, subtotal, tax, total, amount_paid, amount_refunded,
           due_at, paid_at, pdf_url, created_at
    FROM public.stripe_invoices
    WHERE tenant_id = v_tenant
    ORDER BY created_at DESC
    LIMIT 24
  ) x;

  RETURN jsonb_build_object('subscription', v_sub, 'invoices', v_inv);
END
$$;

REVOKE ALL ON FUNCTION sysops.tenant_billing_summary() FROM PUBLIC, ledgerly_platform, ledgerly_report;
GRANT EXECUTE ON FUNCTION sysops.tenant_billing_summary() TO ledgerly_app;
