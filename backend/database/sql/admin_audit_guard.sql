-- Platform admin audit events are append-only (10_SaaS_Admin §6, plan T10). No purge exception, unlike tenant audit.
CREATE OR REPLACE FUNCTION admin_audit_append_only() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'admin_audit_append_only: % on % is not allowed', TG_OP, TG_TABLE_NAME
    USING ERRCODE = 'LG010';
END
$$;

DROP TRIGGER IF EXISTS admin_audit_events_immutable ON admin_audit_events;
CREATE TRIGGER admin_audit_events_immutable
  BEFORE UPDATE OR DELETE ON admin_audit_events
  FOR EACH ROW EXECUTE FUNCTION admin_audit_append_only();
