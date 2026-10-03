/**
 * Step 12: Client-Side UPI QR Code Generation & UPI Deep-Link Verification
 */

import { QRCode } from '../public/assets/js/utils/qrcode.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log("\n====================================================================");
console.log(" STEP 12: CLIENT-SIDE UPI QR CODE & PAYMENT ROUTER TEST");
console.log("====================================================================\n");

let passed = 0;
let failed = 0;

function assert(condition, msg) {
    if (condition) {
        console.log(`  [PASS] ${msg}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${msg}`);
        failed++;
    }
}

// 1. Test UPI VPA syntactic validation
assert(Formatters.isValidUpiVpa('rahul@okaxis') === true, 'isValidUpiVpa accepts standard valid VPA rahul@okaxis');
assert(Formatters.isValidUpiVpa('sneha.fintech@paytm') === true, 'isValidUpiVpa accepts dotted user part sneha.fintech@paytm');
assert(Formatters.isValidUpiVpa('alice_99@ybl') === true, 'isValidUpiVpa accepts underscore in user part alice_99@ybl');
assert(Formatters.isValidUpiVpa('bob-dev@icici') === true, 'isValidUpiVpa accepts hyphen in user part bob-dev@icici');
assert(Formatters.isValidUpiVpa('org.fin_123@hdfcbank') === true, 'isValidUpiVpa accepts complex user part org.fin_123@hdfcbank');

assert(Formatters.isValidUpiVpa('') === false, 'isValidUpiVpa rejects empty string');
assert(Formatters.isValidUpiVpa(null) === false, 'isValidUpiVpa rejects null');
assert(Formatters.isValidUpiVpa(undefined) === false, 'isValidUpiVpa rejects undefined');
assert(Formatters.isValidUpiVpa('invalid-vpa-no-at') === false, 'isValidUpiVpa rejects string without @');
assert(Formatters.isValidUpiVpa('user@') === false, 'isValidUpiVpa rejects missing handle');
assert(Formatters.isValidUpiVpa('@bank') === false, 'isValidUpiVpa rejects missing username');
assert(Formatters.isValidUpiVpa('user@b') === false, 'isValidUpiVpa rejects handle shorter than 2 chars');
assert(Formatters.isValidUpiVpa('user@bank!') === false, 'isValidUpiVpa rejects special character in bank handle');
assert(Formatters.isValidUpiVpa('user name@bank') === false, 'isValidUpiVpa rejects space in user part');
assert(Formatters.isValidUpiVpa('user@bank@extra') === false, 'isValidUpiVpa rejects multiple @ symbols');

// UPI-01: VPA length boundaries
const vpa80 = 'a'.repeat(70) + '@icicibank'; // 70 + 1 + 9 = 80 chars
assert(vpa80.length === 80, 'Constructed vpa80 length is exactly 80');
assert(Formatters.isValidUpiVpa(vpa80) === true, 'isValidUpiVpa accepts exactly 80-character valid VPA');

const vpa81 = 'a'.repeat(71) + '@icicibank'; // 71 + 1 + 9 = 81 chars
assert(vpa81.length === 81, 'Constructed vpa81 length is exactly 81');
assert(Formatters.isValidUpiVpa(vpa81) === false, 'isValidUpiVpa rejects 81-character VPA');

const vpa321 = 'a'.repeat(311) + '@icicibank'; // 311 + 1 + 9 = 321 chars
assert(vpa321.length === 321, 'Constructed vpa321 length is exactly 321');
assert(Formatters.isValidUpiVpa(vpa321) === false, 'isValidUpiVpa rejects 321-character VPA');

// 2. Test Canonical UPI Payment URI Builder
const url1 = Formatters.buildUpiPaymentUrl({
    payeeUpi: 'rahul@okaxis',
    payeeName: 'Rahul Sharma',
    amountDecimal: '1500.00',
    currency: 'INR',
    transactionNote: 'SmartSplit Settlement'
});
assert(url1 === 'upi://pay?pa=rahul%40okaxis&pn=Rahul%20Sharma&am=1500.00&cu=INR&tn=SmartSplit%20Settlement', 'buildUpiPaymentUrl builds exact canonical URI for valid payee');

const urlInvalid = Formatters.buildUpiPaymentUrl({
    payeeUpi: 'invalid_vpa',
    payeeName: 'Priya Patel',
    amountDecimal: '500.00'
});
assert(urlInvalid === null, 'buildUpiPaymentUrl returns null when payee UPI is invalid');

const urlUnset = Formatters.buildUpiPaymentUrl({
    payeeUpi: '',
    payeeName: 'Priya Patel',
    amountDecimal: '500.00'
});
assert(urlUnset === null, 'buildUpiPaymentUrl returns null when payee UPI is unset');

// UPI-02: Amount validation boundaries for buildUpiPaymentUrl
// Valid amounts
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 1 })?.includes('am=1.00'), 'buildUpiPaymentUrl accepts integer 1 -> am=1.00');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 1.0 })?.includes('am=1.00'), 'buildUpiPaymentUrl accepts float 1.0 -> am=1.00');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '1.00' })?.includes('am=1.00'), 'buildUpiPaymentUrl accepts string "1.00" -> am=1.00');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 10.5 })?.includes('am=10.50'), 'buildUpiPaymentUrl accepts float 10.5 -> am=10.50');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '10.50' })?.includes('am=10.50'), 'buildUpiPaymentUrl accepts string "10.50" -> am=10.50');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 0.01 })?.includes('am=0.01'), 'buildUpiPaymentUrl accepts float 0.01 -> am=0.01');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '100.99' })?.includes('am=100.99'), 'buildUpiPaymentUrl accepts string "100.99" -> am=100.99');

// Zero amounts (rejected)
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 0 }) === null, 'buildUpiPaymentUrl rejects number 0');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '0' }) === null, 'buildUpiPaymentUrl rejects string "0"');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '0.00' }) === null, 'buildUpiPaymentUrl rejects string "0.00"');

// Negative amounts (rejected)
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: -1 }) === null, 'buildUpiPaymentUrl rejects number -1');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '-1' }) === null, 'buildUpiPaymentUrl rejects string "-1"');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '-0.01' }) === null, 'buildUpiPaymentUrl rejects string "-0.01"');

// Non-finite values (rejected)
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: NaN }) === null, 'buildUpiPaymentUrl rejects NaN');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: Infinity }) === null, 'buildUpiPaymentUrl rejects Infinity');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: -Infinity }) === null, 'buildUpiPaymentUrl rejects -Infinity');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 'NaN' }) === null, 'buildUpiPaymentUrl rejects string "NaN"');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 'Infinity' }) === null, 'buildUpiPaymentUrl rejects string "Infinity"');

// Partially numeric strings (rejected)
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '1abc' }) === null, 'buildUpiPaymentUrl rejects string "1abc"');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: 'abc1' }) === null, 'buildUpiPaymentUrl rejects string "abc1"');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '10.50abc' }) === null, 'buildUpiPaymentUrl rejects string "10.50abc"');

// Empty / whitespace / null / undefined (rejected)
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '' }) === null, 'buildUpiPaymentUrl rejects empty string');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: '   ' }) === null, 'buildUpiPaymentUrl rejects whitespace string');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: null }) === null, 'buildUpiPaymentUrl rejects null amount');
assert(Formatters.buildUpiPaymentUrl({ payeeUpi: 'rahul@okaxis', amountDecimal: undefined }) === null, 'buildUpiPaymentUrl rejects undefined amount');

// 3. Test QR Code generation for standard UPI URI
const upiUri1 = url1;
const svg1 = QRCode.generateSvg(upiUri1, { size: 160, margin: 2, color: '#0f172a', background: '#ffffff' });

assert(svg1.includes('<svg xmlns="http://www.w3.org/2000/svg"'), "QR generator outputs valid SVG root element");
assert(svg1.includes('width="160" height="160"'), "QR SVG includes requested dimensions (160x160)");
assert(svg1.includes('fill="#0f172a"'), "QR SVG includes dark foreground module fill");
assert(svg1.includes('fill="#ffffff"'), "QR SVG includes white background rect");
assert(svg1.includes('<path d="M'), "QR SVG uses crisp path data for matrix rendering");

// 4. Test QR Matrix generation
const matrix = QRCode.generateMatrix(upiUri1, 'M');
assert(Array.isArray(matrix) && matrix.length >= 21, "QR matrix generated with valid dimension (>= 21x21)");
assert(matrix[0].length === matrix.length, "QR matrix is perfectly square");

// Top-left finder pattern check (7x7 with border)
assert(matrix[0][0] === true && matrix[0][6] === true && matrix[6][0] === true && matrix[6][6] === true, "Top-left finder pattern corner modules are dark");
assert(matrix[3][3] === true, "Top-left finder pattern center is dark");

// 5. Test QR Code generation for partial amount and customized UPI ID
const upiUri2 = 'upi://pay?pa=sneha.fintech%40paytm&pn=Sneha+Patel&am=540.00&cu=INR&tn=SmartSplit+Settlement';
const svg2 = QRCode.generateSvg(upiUri2, { size: 200, margin: 3 });
assert(svg2.includes('width="200" height="200"'), "QR SVG scales to size 200");
assert(svg2.length > 2000, "QR SVG contains complete module payload");

console.log("\n====================================================================");
console.log(` CLIENT QR RESULTS: ${passed} PASSED | ${failed} FAILED`);
console.log("====================================================================\n");

process.exit(failed > 0 ? 1 : 0);
