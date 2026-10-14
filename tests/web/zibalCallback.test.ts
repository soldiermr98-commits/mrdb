/**
 * Hardening (prelive 18-b — یافتهٔ 2-16): تست‌های پارس کال‌بک زیبال.
 *
 * واریانت‌های واقعی که باید همه پشتیبانی شوند:
 *  ۱) هش + زیبال با «&» می‌چسباند:   #/payment/callback?orderId=ord_x&cb_token=abc&trackId=123&success=1
 *  ۲) هش + زیبال با «?» می‌چسباند:   #/payment/callback?orderId=ord_x&cb_token=abc?trackId=123&success=1
 *  ۳) مسیر سرور با «&»:              /payment/callback?orderId=..&cb_token=..&trackId=..&success=1
 *  ۴) مسیر سرور با «?»:              /payment/callback?orderId=..&cb_token=..?trackId=..&success=1
 *  ۵) snake_case سرور (track_id)
 *  ۶) کال‌بک ناقص (بدون trackId) → پاک‌سازی پارامترها + null
 *
 * history.replaceState با stub سراسری mock می‌شود.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { clearZibalCallbackParams, detectZibalCallback } from '../../src/services/zibalCallback';

interface FakeLocation {
  pathname: string;
  search: string;
  hash: string;
}

const replaceStateMock = vi.fn();

function stubWindow(location: FakeLocation): void {
  vi.stubGlobal('window', {
    location,
    history: { replaceState: replaceStateMock },
  });
}

beforeEach(() => {
  replaceStateMock.mockClear();
  replaceStateMock.mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('detectZibalCallback', () => {
  it('variant 1 — hash URL with Zibal appending via "&"', () => {
    stubWindow({
      pathname: '/',
      search: '',
      hash: '#/payment/callback?orderId=ord_x&cb_token=abc&trackId=123&success=1',
    });
    expect(detectZibalCallback()).toEqual({
      trackId: '123',
      success: '1',
      cbToken: 'abc',
      orderId: 'ord_x',
      source: 'hash',
    });
  });

  it('variant 2 — hash URL with Zibal appending via "?" (glued query)', () => {
    stubWindow({
      pathname: '/',
      search: '',
      hash: '#/payment/callback?orderId=ord_x&cb_token=abc?trackId=123&success=1',
    });
    expect(detectZibalCallback()).toEqual({
      trackId: '123',
      success: '1',
      cbToken: 'abc',
      orderId: 'ord_x',
      source: 'hash',
    });
  });

  it('variant 3 — server path with regular query', () => {
    stubWindow({
      pathname: '/payment/callback',
      search: '?orderId=ord_x&cb_token=abc&trackId=123&success=1',
      hash: '',
    });
    expect(detectZibalCallback()).toEqual({
      trackId: '123',
      success: '1',
      cbToken: 'abc',
      orderId: 'ord_x',
      source: 'search',
    });
  });

  it('variant 4 — server path with "?"-glued query', () => {
    stubWindow({
      pathname: '/payment/callback',
      search: '?orderId=ord_x&cb_token=abc?trackId=123&success=1',
      hash: '',
    });
    expect(detectZibalCallback()).toEqual({
      trackId: '123',
      success: '1',
      cbToken: 'abc',
      orderId: 'ord_x',
      source: 'search',
    });
  });

  it('variant 5 — server-style snake_case track_id is accepted', () => {
    stubWindow({
      pathname: '/payment/callback',
      search: '?orderId=ord_x&cb_token=abc&track_id=987&success=2',
      hash: '',
    });
    expect(detectZibalCallback()).toEqual({
      trackId: '987',
      success: '2',
      cbToken: 'abc',
      orderId: 'ord_x',
      source: 'search',
    });
  });

  it('variant 6 — incomplete callback (no trackId) is cleared and returns null', () => {
    stubWindow({
      pathname: '/',
      search: '',
      hash: '#/payment/callback?orderId=ord_x&cb_token=abc',
    });
    expect(detectZibalCallback()).toBeNull();
    // پارامترها باید پاک شده باشند (replaceState به #/).
    expect(replaceStateMock).toHaveBeenCalledTimes(1);
    expect(replaceStateMock).toHaveBeenCalledWith(null, '', '/#/');
  });

  it('returns null when there is no callback in the URL', () => {
    stubWindow({ pathname: '/', search: '', hash: '#/catalogue' });
    expect(detectZibalCallback()).toBeNull();
  });
});

describe('clearZibalCallbackParams', () => {
  it('search source — strips the whole query down to pathname', () => {
    stubWindow({ pathname: '/payment/callback', search: '?orderId=ord_x&cb_token=abc&trackId=1&success=1', hash: '' });
    clearZibalCallbackParams('search');
    expect(replaceStateMock).toHaveBeenCalledWith(null, '', '/payment/callback');
  });

  it('hash source — resets the hash to root, keeps pathname/search', () => {
    stubWindow({ pathname: '/', search: '?utm=1', hash: '#/payment/callback?orderId=ord_x&cb_token=abc&trackId=1&success=1' });
    clearZibalCallbackParams('hash');
    expect(replaceStateMock).toHaveBeenCalledWith(null, '', '/?utm=1#/');
  });

  it('replaceState failures are swallowed (no throw)', () => {
    stubWindow({ pathname: '/', search: '', hash: '#/payment/callback?cb_token=abc&trackId=1' });
    replaceStateMock.mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(() => clearZibalCallbackParams('hash')).not.toThrow();
  });
});
