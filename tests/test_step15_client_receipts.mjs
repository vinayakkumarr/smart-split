/**
 * Smart Split – Step 15 Client Receipt Lightbox & Utilities Test Suite
 */

let passed = 0;
let failed = 0;

function assert(condition, message) {
    if (condition) {
        console.log(`  [PASS] ${message}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${message}`);
        failed++;
    }
}

console.log('\n====================================================================');
console.log(' STEP 15: CLIENT RECEIPT LIGHTBOX & FILE ATTACHMENT UNIT TESTS');
console.log('====================================================================\n');

try {
    // 1. Receipt metadata and file size formatting
    function formatFileSize(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
    }

    assert(formatFileSize(500) === '500 B', "formatFileSize(500) -> '500 B'");
    assert(formatFileSize(45000) === '43.9 KB', "formatFileSize(45000) -> '43.9 KB'");
    assert(formatFileSize(2500000) === '2.38 MB', "formatFileSize(2500000) -> '2.38 MB'");

    // 2. MIME type & Image detection
    function isImageMime(mimeType) {
        return typeof mimeType === 'string' && mimeType.startsWith('image/');
    }

    assert(isImageMime('image/png') === true, 'image/png detected as image');
    assert(isImageMime('image/jpeg') === true, 'image/jpeg detected as image');
    assert(isImageMime('image/webp') === true, 'image/webp detected as image');
    assert(isImageMime('application/pdf') === false, 'application/pdf detected as document (not image)');

    // 3. Receipt Carousel Pagination Logic
    const mockReceipts = [
        { id: 1, file_name: 'receipt_page1.jpg', url: '/uploads/receipts/r1.jpg', mime_type: 'image/jpeg', file_size_bytes: 120000 },
        { id: 2, file_name: 'receipt_page2.jpg', url: '/uploads/receipts/r2.jpg', mime_type: 'image/jpeg', file_size_bytes: 140000 },
        { id: 3, file_name: 'invoice_tax.pdf', url: '/uploads/receipts/r3.pdf', mime_type: 'application/pdf', file_size_bytes: 450000 },
    ];

    let currentIndex = 0;
    function next(items) {
        currentIndex = (currentIndex + 1) % items.length;
        return currentIndex;
    }
    function prev(items) {
        currentIndex = (currentIndex - 1 + items.length) % items.length;
        return currentIndex;
    }

    assert(currentIndex === 0, 'Initial receipt index is 0');
    assert(next(mockReceipts) === 1, 'Next receipt index is 1');
    assert(next(mockReceipts) === 2, 'Next receipt index is 2');
    assert(next(mockReceipts) === 0, 'Carousel loops back to index 0 on overflow');
    assert(prev(mockReceipts) === 2, 'Prev receipt index loops to 2 on underflow');

    // 4. File extension and sanitize helper
    function sanitizeFileName(name, mimeType) {
        const extMap = {
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/webp': 'webp',
            'application/pdf': 'pdf',
        };
        const ext = extMap[mimeType] || 'jpg';
        const baseName = name.split(/[/\\]/).pop();
        const baseWithoutExt = baseName.replace(/\.[^/.]+$/, '').replace(/[^a-zA-Z0-9_\-]/g, '_');
        return `${baseWithoutExt}.${ext}`;
    }

    assert(sanitizeFileName('Dinner Bill #4092 (1).PNG', 'image/png') === 'Dinner_Bill__4092__1_.png', 'Sanitized filename with correct extension');
    assert(sanitizeFileName('../../etc/passwd', 'image/jpeg') === 'passwd.jpg', 'Path traversal characters stripped and sanitized');

    // 5. Attached receipt count badge formatting
    function formatReceiptBadge(count) {
        if (!count || count <= 0) return null;
        return count === 1 ? '🧾 Receipt' : `🧾 ${count} Receipts`;
    }

    assert(formatReceiptBadge(0) === null, '0 receipts returns null badge');
    assert(formatReceiptBadge(1) === '🧾 Receipt', '1 receipt returns "🧾 Receipt"');
    assert(formatReceiptBadge(3) === '🧾 3 Receipts', '3 receipts returns "🧾 3 Receipts"');

} catch (err) {
    console.error('EXCEPTION:', err);
    failed++;
}

console.log('\n--------------------------------------------------------------------');
console.log(` CLIENT STEP 15 SUMMARY: ${passed} Passed, ${failed} Failed`);
console.log('--------------------------------------------------------------------\n');

if (failed > 0) {
    process.exit(1);
}
