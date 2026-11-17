-- A plan version is the contract subscribers were sold (10_SaaS_Admin §3.3). Once it takes effect its prices, limits,
-- entitlements and trial length never change and it cannot be removed; a scheduled version may still be corrected.
-- Stripe ids and similar bookkeeping columns stay writable.
CREATE OR REPLACE FUNCTION plan_versions_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.effective_from <= now() THEN
    IF TG_OP = 'DELETE' THEN
      RAISE EXCEPTION 'plan_versions_immutable: an effective plan version cannot be deleted'
        USING ERRCODE = 'LG011';
    END IF;
    IF NEW.prices IS DISTINCT FROM OLD.prices
       OR NEW.limits IS DISTINCT FROM OLD.limits
       OR NEW.entitlements IS DISTINCT FROM OLD.entitlements
       OR NEW.trial_days IS DISTINCT FROM OLD.trial_days
       OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
       OR NEW.plan_id IS DISTINCT FROM OLD.plan_id
       OR NEW.version IS DISTINCT FROM OLD.version THEN
      RAISE EXCEPTION 'plan_versions_immutable: an effective plan version cannot be changed'
        USING ERRCODE = 'LG011';
    END IF;
  END IF;
  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  END IF;
  RETURN NEW;
END
$$;

DROP TRIGGER IF EXISTS plan_versions_guard ON plan_versions;
CREATE TRIGGER plan_versions_guard
  BEFORE UPDATE OR DELETE ON plan_versions
  FOR EACH ROW EXECUTE FUNCTION plan_versions_guard();
