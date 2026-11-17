/** Shown when the API refuses this network address (403 admin_ip_blocked). */
import React from 'react';
import { Link } from 'react-router-dom';
import { AuthLayout } from '@/pages/Auth/AuthLayout';

export const AdminBlocked: React.FC = () => (
  <AuthLayout>
    <h1 className="text-[22px] font-semibold text-text-primary">This network is not allowed</h1>
    <p className="mt-2 text-[13px] text-text-muted">
      The platform console only opens from approved office or VPN addresses, and your current address is not on the list. Connect to the company VPN, or ask an owner to add your address, then try again.
    </p>
    <Link to="/admin/login" className="mt-6 inline-block text-[13px] text-accent hover:underline">Try signing in again</Link>
  </AuthLayout>
);
