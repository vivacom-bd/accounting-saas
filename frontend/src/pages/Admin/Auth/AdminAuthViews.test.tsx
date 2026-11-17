import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { EnrolPanel, RecoveryCodesPanel, recoveryCodesText } from '@/pages/Admin/Auth/AdminAuthViews';
import { AdminBlocked } from '@/pages/Admin/Auth/AdminBlocked';

const render = (el: React.ReactElement) => renderToStaticMarkup(<MemoryRouter>{el}</MemoryRouter>);
const noop = () => {};

describe('enrolment panel', () => {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>';

  it('shows the QR as an image data URI, never as inline markup, and the secret to type by hand', () => {
    const html = render(<EnrolPanel enrolment={{ secret: 'ABCDEFGH', otpauth_url: 'otpauth://totp/x', qr_svg: svg }} code="" onCode={noop} onSubmit={noop} busy={false} error={null} />);
    expect(html).toContain('<img');
    expect(html).toContain('src="data:image/svg+xml;base64,');
    expect(html).not.toContain('<svg');
    expect(html).toContain('ABCD EFGH');
  });

  it('refuses an SVG that carries script and still offers the secret to type', () => {
    const bad = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    const html = render(<EnrolPanel enrolment={{ secret: 'ABCDEFGH', otpauth_url: 'otpauth://totp/x', qr_svg: bad }} code="" onCode={noop} onSubmit={noop} busy={false} error={null} />);
    expect(html).not.toContain('<img');
    expect(html).not.toContain('<script');
    expect(html).toContain('ABCD EFGH');
  });
});

describe('recovery codes panel', () => {
  it('lists every code once, with copy and download, and warns they are not shown again', () => {
    const html = render(<RecoveryCodesPanel codes={['aaaa-1111', 'bbbb-2222']} onDone={noop} />);
    expect(html).toContain('aaaa-1111');
    expect(html).toContain('bbbb-2222');
    expect(html).toMatch(/Copy/);
    expect(html).toMatch(/Download/);
    expect(html).toMatch(/will not be shown again/i);
  });
  it('formats the codes one per line for copy and download', () => {
    expect(recoveryCodesText(['a', 'b'])).toBe('a\nb\n');
  });
});

describe('blocked page', () => {
  it('says plainly that this network is not allowed and offers a way back', () => {
    const html = render(<AdminBlocked />);
    expect(html).toMatch(/network|address/i);
    expect(html).toContain('/admin/login');
  });
});
