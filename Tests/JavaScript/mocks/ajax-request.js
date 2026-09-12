/**
 * Minimal stand-in for @typo3/core/ajax/ajax-request.js.
 *
 * Tests install a responder with `__respond((request) => payload)`; every
 * request is recorded in `__calls` as {url, method, query, body}.
 */
export const __calls = [];
let responder = async () => ({});

export function __respond(fn) {
  responder = fn;
}

export function __reset() {
  responder = async () => ({});
  __calls.length = 0;
}

async function dispatch(request, method, body = null) {
  const record = { url: request.url, method, query: { ...request.query }, body };
  __calls.push(record);
  const payload = await responder(record);
  return {
    resolve: async () => payload,
    raw: () => ({ ok: true }),
  };
}

export default class AjaxRequest {
  constructor(url) {
    this.url = String(url);
    this.query = {};
  }

  withQueryArguments(query) {
    this.query = { ...this.query, ...query };
    return this;
  }

  get() {
    return dispatch(this, 'GET');
  }

  post(body) {
    return dispatch(this, 'POST', body);
  }
}
