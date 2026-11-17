import React from 'react';
import { describe, it, expect } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import { CouponFormView } from '@/pages/Admin/Coupons/CouponsViews';
import { emptyCouponForm } from '@/api/adapters/platform/catalogue';

const noop = () => {};
const render = (over = {}) =>
  renderToStaticMarkup(<CouponFormView form={{ ...emptyCouponForm(), ...over }} errors={{}} onChange={noop} onSubmit={noop} onCancel={noop} busy={false} />);

describe('coupon form exclusivity', () => {
  it('percent mode shows the percent input and no amount or currency input', () => {
    const html = render({ mode: 'percent' });
    expect(html).toContain('id="coupon-percent"');
    expect(html).not.toContain('id="coupon-amount"');
    expect(html).not.toContain('id="coupon-currency"');
  });
  it('amount mode shows amount and currency and no percent input', () => {
    const html = render({ mode: 'amount' });
    expect(html).toContain('id="coupon-amount"');
    expect(html).toContain('id="coupon-currency"');
    expect(html).not.toContain('id="coupon-percent"');
  });
  it('months are asked only for a repeating coupon', () => {
    expect(render({ duration: 'once' })).not.toContain('id="coupon-months"');
    expect(render({ duration: 'repeating' })).toContain('id="coupon-months"');
  });
});
