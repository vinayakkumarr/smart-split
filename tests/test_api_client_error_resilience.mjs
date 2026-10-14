/**
 * Smart Split V2 — Shared API Client HTTP Error Resilience Test
 * Validates safe handling of JSON success, structured JSON errors, HTML gateway pages,
 * malformed JSON, empty bodies, and network failures in ApiClient.request.
 */

import { api } from '../public/assets/js/api.js';

let passed = 0;
let total = 0;

function assertTest(condition, name) {
    total++;
    if (condition) {
        console.log(`  [PASS] ${name}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${name}`);
        process.exit(1);
    }
}

console.log('=====================================================');
console.log(' API Client HTTP Error Resilience Test Suite');
console.log('=====================================================\n');

const originalFetch = global.fetch;

try {
    // 1. Valid JSON success
    console.log('--- 1. Testing Valid JSON Success ---');
    global.fetch = async () => ({
        ok: true,
        status: 200,
        headers: new Headers({ 'Content-Type': 'application/json; charset=utf-8' }),
        json: async () => ({ success: true, data: { settlement: { id: 101, status: 'PENDING' } } }),
        text: async () => '{"success":true}',
    });

    const successRes = await api.request('/test-success');
    assertTest(successRes.success === true && successRes.data.settlement.id === 101, 'JSON success returns parsed payload cleanly');

    // 2. Structured application JSON error (e.g. 422 Unprocessable Entity)
    console.log('\n--- 2. Testing Structured Application JSON Error ---');
    global.fetch = async () => ({
        ok: false,
        status: 422,
        headers: new Headers({ 'Content-Type': 'application/json; charset=utf-8' }),
        json: async () => ({
            success: false,
            error: { code: 'UNPROCESSABLE_ENTITY', message: 'Valid payer_id and payee_id are required.' },
        }),
        text: async () => '{"success":false}',
    });

    let appError = null;
    try {
        await api.request('/test-app-error');
    } catch (err) {
        appError = err;
    }
    assertTest(appError !== null, 'Structured error triggers rejection');
    assertTest(appError.status === 422, 'HTTP status 422 is preserved on error object');
    assertTest(appError.code === 'UNPROCESSABLE_ENTITY', 'Application error code UNPROCESSABLE_ENTITY is preserved');
    assertTest(appError.message === 'Valid payer_id and payee_id are required.', 'Application error message is preserved verbatim');

    // 3. HTML 502 Bad Gateway response (Nginx / Render / Cloudflare gateway error)
    console.log('\n--- 3. Testing HTML 502 Bad Gateway Response ---');
    const nginx502Html = '<html>\r\n<head><title>502 Bad Gateway</title></head>\r\n<body><center><h1>502 Bad Gateway</h1></center><hr><center>nginx</center></body>\r\n</html>';
    global.fetch = async () => ({
        ok: false,
        status: 502,
        headers: new Headers({ 'Content-Type': 'text/html; charset=UTF-8' }),
        json: async () => { throw new SyntaxError("Unexpected token '<', \"<html> <h\"... is not valid JSON"); },
        text: async () => nginx502Html,
    });

    let gateway502Error = null;
    try {
        await api.request('/groups/tok/settlements', { method: 'POST', body: { amount_cents: 100 } });
    } catch (err) {
        gateway502Error = err;
    }

    assertTest(gateway502Error !== null, 'HTML 502 triggers rejection');
    assertTest(gateway502Error.status === 502, 'HTTP status 502 is preserved on error object');
    assertTest(gateway502Error.code === 'BAD_GATEWAY', 'Error code is set to BAD_GATEWAY');
    assertTest(!gateway502Error.message.includes('<'), 'Error message does not contain raw HTML tags');
    assertTest(!gateway502Error.message.includes('Unexpected token'), 'Error message does not contain raw V8 SyntaxError');
    assertTest(gateway502Error.message.includes('check settlement history'), 'Mutation 502 error advises checking settlement history');

    // Read 502 (query) advises trying again without settlement history reference
    let read502Error = null;
    try {
        await api.request('/groups/tok/settlements', { method: 'GET' });
    } catch (err) {
        read502Error = err;
    }
    assertTest(read502Error !== null && read502Error.status === 502, 'Read 502 triggers rejection with status 502');
    assertTest(read502Error.message.includes('try again in a few moments'), 'Read 502 error advises trying again in a few moments');
    assertTest(!read502Error.message.includes('settlement history'), 'Read 502 does not mention settlement history');

    // 4. HTML 503 Service Unavailable response (Render maintenance)
    console.log('\n--- 4. Testing HTML 503 Service Unavailable Response ---');
    const render503Html = '<html><head><title>503 Service Unavailable</title></head><body>Service Starting</body></html>';
    global.fetch = async () => ({
        ok: false,
        status: 503,
        headers: new Headers({ 'Content-Type': 'text/html' }),
        json: async () => { throw new SyntaxError("Unexpected token '<'"); },
        text: async () => render503Html,
    });

    let gateway503Error = null;
    try {
        await api.request('/test-503', { method: 'GET' });
    } catch (err) {
        gateway503Error = err;
    }
    assertTest(gateway503Error !== null, 'HTML 503 triggers rejection');
    assertTest(gateway503Error.status === 503, 'HTTP status 503 is preserved on error object');
    assertTest(gateway503Error.code === 'SERVICE_UNAVAILABLE', 'Error code is set to SERVICE_UNAVAILABLE');
    assertTest(gateway503Error.message.includes('temporarily unavailable'), 'Provides user-friendly service unavailable message');

    // 5. HTML 504 Gateway Timeout response (Edge timeout)
    console.log('\n--- 5. Testing HTML 504 Gateway Timeout Response ---');
    global.fetch = async () => ({
        ok: false,
        status: 504,
        headers: new Headers({ 'Content-Type': 'text/html' }),
        json: async () => { throw new SyntaxError("Unexpected token '<'"); },
        text: async () => '<html><head><title>504 Gateway Time-out</title></head></html>',
    });

    let gateway504Error = null;
    try {
        await api.request('/test-504', { method: 'GET' });
    } catch (err) {
        gateway504Error = err;
    }
    assertTest(gateway504Error !== null, 'HTML 504 triggers rejection');
    assertTest(gateway504Error.status === 504, 'HTTP status 504 is preserved on error object');
    assertTest(gateway504Error.code === 'GATEWAY_TIMEOUT', 'Error code is set to GATEWAY_TIMEOUT');
    assertTest(gateway504Error.message.includes('Gateway timed out'), 'Provides user-friendly gateway timeout message');

    // 6. Malformed JSON on HTTP 200 OK
    console.log('\n--- 6. Testing Malformed JSON on HTTP 200 OK ---');
    global.fetch = async () => ({
        ok: true,
        status: 200,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => { throw new SyntaxError('Unexpected end of JSON input'); },
        text: async () => '{"incomplete',
    });

    let malformedJsonError = null;
    try {
        await api.request('/test-corrupted-json');
    } catch (err) {
        malformedJsonError = err;
    }
    assertTest(malformedJsonError !== null, 'Malformed JSON triggers rejection');
    assertTest(malformedJsonError.code === 'INVALID_JSON_RESPONSE', 'Code is set to INVALID_JSON_RESPONSE');
    assertTest(malformedJsonError.status === 200, 'HTTP status 200 is preserved');
    assertTest(!malformedJsonError.message.includes('Unexpected end of JSON input'), 'Internal syntax error is replaced by user-safe message');

    // 7. Empty Body on HTTP 502
    console.log('\n--- 7. Testing Empty Body on HTTP 502 ---');
    global.fetch = async () => ({
        ok: false,
        status: 502,
        headers: new Headers({ 'Content-Type': 'text/plain' }),
        json: async () => { throw new SyntaxError('Unexpected end of JSON input'); },
        text: async () => '',
    });

    let empty502Error = null;
    try {
        await api.request('/test-empty-502', { method: 'GET' });
    } catch (err) {
        empty502Error = err;
    }
    assertTest(empty502Error !== null, 'Empty 502 triggers rejection');
    assertTest(empty502Error.status === 502, 'Status 502 preserved for empty body');
    assertTest(empty502Error.code === 'BAD_GATEWAY', 'Code set to BAD_GATEWAY for empty body');

    // 8. Network Failure (e.g. offline or DNS failure)
    console.log('\n--- 8. Testing Network Rejection ---');
    global.fetch = async () => {
        const fetchErr = new TypeError('Failed to fetch');
        throw fetchErr;
    };

    let networkError = null;
    try {
        await api.request('/test-network-fail');
    } catch (err) {
        networkError = err;
    }
    assertTest(networkError !== null && networkError instanceof TypeError, 'Network rejection propagates safely');

    // 9. Ambiguous Mutation 502 Followed by Safe Idempotency Retry
    console.log('\n--- 9. Ambiguous Mutation 502 Followed by Safe Idempotency Retry ---');
    let requestCount = 0;
    const testIdempotencyKey = 'idemp_test_retry_uuid_001';
    const testPayload = { payer_id: 1, payee_id: 2, amount_cents: 2500 };

    global.fetch = async (url, config) => {
        requestCount++;
        // First request: server encounters 502 Bad Gateway
        if (requestCount === 1) {
            return {
                ok: false,
                status: 502,
                headers: new Headers({ 'Content-Type': 'text/html' }),
                text: async () => '<html><head><title>502 Bad Gateway</title></head></html>',
            };
        }
        // Second request (retry with identical key): returns committed settlement
        return {
            ok: true,
            status: 200,
            headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => ({
                success: true,
                data: { settlement: { id: 77, amount_cents: 2500, status: 'PENDING' } },
            }),
            text: async () => '{"success":true}',
        };
    };

    let firstAttemptError = null;
    try {
        await api.createSettlement('tok123', testPayload, testIdempotencyKey);
    } catch (err) {
        firstAttemptError = err;
    }

    assertTest(firstAttemptError !== null, 'First attempt receives 502 error safely');
    assertTest(firstAttemptError.status === 502, 'First attempt preserves status 502');
    assertTest(firstAttemptError.message.includes('check settlement history'), 'Instructs user to check settlement history');

    // User or client retries with the exact same idempotency key
    const retryRes = await api.createSettlement('tok123', testPayload, testIdempotencyKey);
    assertTest(retryRes?.success === true, 'Retry with same key succeeds');
    assertTest(retryRes?.data?.settlement?.id === 77, 'Retry returns committed settlement ID without duplicate creation');

    // 10. Mismatched Payload on Reused Key Rejection (HTTP 409)
    console.log('\n--- 10. Mismatched Payload Rejection on Reused Key (HTTP 409) ---');
    global.fetch = async () => ({
        ok: false,
        status: 409,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => ({
            success: false,
            error: {
                code: 'CONFLICT',
                message: 'Idempotency key reused with mismatched payload data.',
            },
        }),
        text: async () => '{"success":false}',
    });

    let conflictError = null;
    try {
        await api.createSettlement('tok123', { ...testPayload, amount_cents: 9999 }, testIdempotencyKey);
    } catch (err) {
        conflictError = err;
    }

    assertTest(conflictError !== null, 'Mismatched payload triggers error rejection');
    assertTest(conflictError.status === 409, 'Conflict status 409 is preserved');
    assertTest(conflictError.code === 'CONFLICT', 'Conflict code is preserved');
    assertTest(conflictError.message.includes('mismatched payload'), 'Preserves backend mismatch message');

    // 11. Legitimate HTTP 204 No Content Response
    console.log('\n--- 11. Legitimate HTTP 204 No Content Response ---');
    global.fetch = async () => ({
        ok: true,
        status: 204,
        headers: new Headers(),
        text: async () => '',
    });

    const res204 = await api.request('/test-204-endpoint', { method: 'DELETE' });
    assertTest(res204 !== null && res204.success === true, 'HTTP 204 returns success envelope cleanly');
    assertTest(res204.data === null, 'HTTP 204 sets data payload to null without throwing INVALID_JSON_RESPONSE');

    // 12. Plain-Text Non-JSON Error Response (e.g. Nginx/Proxy raw 500 error)
    console.log('\n--- 12. Plain-Text Non-JSON Error Response ---');
    global.fetch = async () => ({
        ok: false,
        status: 500,
        headers: new Headers({ 'Content-Type': 'text/plain' }),
        json: async () => { throw new SyntaxError('Unexpected token \'I\', "Internal s"... is not valid JSON'); },
        text: async () => 'Internal server error',
    });

    let plainTextError = null;
    try {
        await api.request('/test-plain-text-error', { method: 'GET' });
    } catch (err) {
        plainTextError = err;
    }
    assertTest(plainTextError !== null, 'Plain-text error triggers rejection');
    assertTest(plainTextError.status === 500, 'Plain-text error preserves status 500');
    assertTest(plainTextError.code === 'SERVER_ERROR_500', 'Plain-text error assigns stable code SERVER_ERROR_500');
    assertTest(!plainTextError.message.includes('SyntaxError'), 'Does not leak parser SyntaxError');
    assertTest(plainTextError.message.includes('Server error (HTTP 500)'), 'Provides user-friendly server error message');

    // 13. Malformed JSON on Non-2xx Response (e.g. truncated 400 error)
    console.log('\n--- 13. Malformed JSON on Non-2xx Response ---');
    global.fetch = async () => ({
        ok: false,
        status: 400,
        headers: new Headers({ 'Content-Type': 'application/json' }),
        json: async () => { throw new SyntaxError('Unexpected end of JSON input'); },
        text: async () => '{"error": {"code": "BAD_REQ',
    });

    let malformedError = null;
    try {
        await api.request('/test-malformed-400', { method: 'GET' });
    } catch (err) {
        malformedError = err;
    }
    assertTest(malformedError !== null, 'Malformed non-2xx error triggers rejection');
    assertTest(malformedError.status === 400, 'Malformed non-2xx error preserves status 400');
    assertTest(malformedError.code === 'HTTP_400', 'Assigns stable code HTTP_400');
    assertTest(!malformedError.message.includes('Unexpected end of JSON input'), 'Does not leak parser SyntaxError');

} finally {
    global.fetch = originalFetch;
}

console.log(`\n=====================================================`);
console.log(` Results: ${passed}/${total} assertions passed (100%)`);
console.log(`=====================================================\n`);
