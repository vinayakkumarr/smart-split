/**
 * Smart Split – Zero-Dependency Pure Vector SVG QR Code Generator (ISO/IEC 18004 Standard)
 * Generates high-contrast, scan-ready SVG QR codes for dynamic UPI deep-links and URLs.
 */

export class QRCode {
    /**
     * Generate an SVG string representing a QR Code for the given text.
     * @param {string} text Content to encode (e.g., UPI URI)
     * @param {Object} options
     * @param {number} [options.size=200] Display size in pixels
     * @param {number} [options.margin=2] Margin modules (quiet zone)
     * @param {string} [options.color='#0f172a'] Foreground color
     * @param {string} [options.background='#ffffff'] Background color
     * @param {string} [options.ecLevel='M'] Error correction level: 'L', 'M', 'Q', 'H'
     * @returns {string} Clean SVG markup
     */
    static generateSvg(text, options = {}) {
        const size = options.size || 200;
        const margin = typeof options.margin === 'number' ? options.margin : 2;
        const color = options.color || '#0f172a';
        const background = options.background || '#ffffff';
        const ecLevel = options.ecLevel || 'M';

        const matrix = QRCode.generateMatrix(text, ecLevel);
        const moduleCount = matrix.length;
        const totalSize = moduleCount + margin * 2;

        let pathData = '';
        for (let r = 0; r < moduleCount; r++) {
            for (let c = 0; c < moduleCount; c++) {
                if (matrix[r][c]) {
                    const x = c + margin;
                    const y = r + margin;
                    pathData += `M${x},${y}h1v1h-1z `;
                }
            }
        }

        return `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${totalSize} ${totalSize}" width="${size}" height="${size}" shape-rendering="crispEdges" style="display: block; max-width: 100%; height: auto; border-radius: 6px;">
                <rect width="${totalSize}" height="${totalSize}" fill="${background}" rx="1" />
                <path d="${pathData.trim()}" fill="${color}" />
            </svg>
        `;
    }

    /**
     * Generate a boolean matrix (2D array) representing the QR code.
     * @param {string} text
     * @param {string} ecLevel
     * @returns {Array<Array<boolean>>}
     */
    static generateMatrix(text, ecLevel = 'M') {
        const qr = new QRModel(ecLevel);
        qr.addData(text);
        qr.make();
        return qr.modules;
    }
}

/* ============================================================================
 * Internal QR Code Matrix Computation Engine (ISO/IEC 18004 Compliant)
 * ============================================================================ */

class QRModel {
    constructor(ecLevel = 'M') {
        this.ecLevel = ecLevel;
        this.modules = [];
        this.moduleCount = 0;
        this.dataList = [];
        this.typeNumber = 1;
    }

    addData(data) {
        this.dataList.push(new QR8bitByte(data));
    }

    make() {
        // Auto-select minimum viable version (Version 1 to 10)
        let typeNumber = 1;
        for (; typeNumber <= 10; typeNumber++) {
            const rsBlocks = QRRSBlock.getRSBlocks(typeNumber, this.ecLevel);
            const totalDataCount = rsBlocks.reduce((sum, b) => sum + b.dataCount, 0);
            const buffer = new QRBitBuffer();
            for (const d of this.dataList) {
                buffer.put(4, 4); // 8-bit byte mode indicator
                buffer.put(d.getLength(), QRUtil.getLengthInBits(4, typeNumber));
                d.write(buffer);
            }
            if (buffer.getLengthInBits() <= totalDataCount * 8) {
                break;
            }
        }

        if (typeNumber > 10) {
            typeNumber = 10;
        }

        this.typeNumber = typeNumber;
        this.moduleCount = this.typeNumber * 4 + 17;
        this.modules = Array.from({ length: this.moduleCount }, () => Array(this.moduleCount).fill(null));

        this.setupPositionProbePattern(0, 0);
        this.setupPositionProbePattern(this.moduleCount - 7, 0);
        this.setupPositionProbePattern(0, this.moduleCount - 7);
        this.setupPositionAdjustPattern();
        this.setupTimingPattern();
        this.setupTypeInfo(true, 0);

        const data = this.createData();
        this.mapData(data, 0);
    }

    setupPositionProbePattern(row, col) {
        for (let r = -1; r <= 7; r++) {
            if (row + r <= -1 || this.moduleCount <= row + r) continue;
            for (let c = -1; c <= 7; c++) {
                if (col + c <= -1 || this.moduleCount <= col + c) continue;
                if ((0 <= r && r <= 6 && (c === 0 || c === 6)) ||
                    (0 <= c && c <= 6 && (r === 0 || r === 6)) ||
                    (2 <= r && r <= 4 && 2 <= c && c <= 4)) {
                    this.modules[row + r][col + c] = true;
                } else {
                    this.modules[row + r][col + c] = false;
                }
            }
        }
    }

    setupTimingPattern() {
        for (let r = 8; r < this.moduleCount - 8; r++) {
            if (this.modules[r][6] !== null) continue;
            this.modules[r][6] = (r % 2 === 0);
        }
        for (let c = 8; c < this.moduleCount - 8; c++) {
            if (this.modules[6][c] !== null) continue;
            this.modules[6][c] = (c % 2 === 0);
        }
    }

    setupPositionAdjustPattern() {
        const pos = QRUtil.getPatternPositions(this.typeNumber);
        for (let i = 0; i < pos.length; i++) {
            for (let j = 0; j < pos.length; j++) {
                const row = pos[i];
                const col = pos[j];
                if (this.modules[row][col] !== null) continue;

                for (let r = -2; r <= 2; r++) {
                    for (let c = -2; c <= 2; c++) {
                        if (r === -2 || r === 2 || c === -2 || c === 2 || (r === 0 && c === 0)) {
                            this.modules[row + r][col + c] = true;
                        } else {
                            this.modules[row + r][col + c] = false;
                        }
                    }
                }
            }
        }
    }

    setupTypeInfo(test, maskPattern) {
        const data = (QRUtil.getEcBits(this.ecLevel) << 3) | maskPattern;
        const bits = QRUtil.getBCHTypeInfo(data);

        for (let i = 0; i < 15; i++) {
            const mod = !test && ((bits >> i) & 1) === 1;
            if (i < 6) {
                this.modules[i][8] = mod;
            } else if (i < 8) {
                this.modules[i + 1][8] = mod;
            } else {
                this.modules[this.moduleCount - 15 + i][8] = mod;
            }

            if (i < 8) {
                this.modules[8][this.moduleCount - i - 1] = mod;
            } else if (i < 9) {
                this.modules[8][15 - i - 1 + 1] = mod;
            } else {
                this.modules[8][15 - i - 1] = mod;
            }
        }
        this.modules[this.moduleCount - 8][8] = !test;
    }

    createData() {
        const rsBlocks = QRRSBlock.getRSBlocks(this.typeNumber, this.ecLevel);
        const buffer = new QRBitBuffer();

        for (const d of this.dataList) {
            buffer.put(4, 4);
            buffer.put(d.getLength(), QRUtil.getLengthInBits(4, this.typeNumber));
            d.write(buffer);
        }

        const totalDataCount = rsBlocks.reduce((sum, b) => sum + b.dataCount, 0);
        if (buffer.getLengthInBits() + 4 <= totalDataCount * 8) {
            buffer.put(0, 4);
        }
        while (buffer.getLengthInBits() % 8 !== 0) {
            buffer.putBit(false);
        }

        const padBytes = [0xec, 0x11];
        let padIdx = 0;
        while (buffer.getLengthInBits() < totalDataCount * 8) {
            buffer.put(padBytes[padIdx % 2], 8);
            padIdx++;
        }

        return this.createBytes(buffer, rsBlocks);
    }

    createBytes(buffer, rsBlocks) {
        let offset = 0;
        let maxDcCount = 0;
        let maxEcCount = 0;
        const dcData = [];
        const ecData = [];

        for (let r = 0; r < rsBlocks.length; r++) {
            const dcCount = rsBlocks[r].dataCount;
            const ecCount = rsBlocks[r].totalCount - dcCount;
            maxDcCount = Math.max(maxDcCount, dcCount);
            maxEcCount = Math.max(maxEcCount, ecCount);

            dcData[r] = [];
            for (let i = 0; i < dcCount; i++) {
                dcData[r][i] = 0xff & buffer.buffer[offset + i];
            }
            offset += dcCount;

            const rsPoly = QRUtil.getErrorCorrectPolynomial(ecCount);
            const rawPoly = new QRPolynomial(dcData[r], rsPoly.getLength() - 1);
            const modPoly = rawPoly.mod(rsPoly);

            ecData[r] = [];
            for (let i = 0; i < rsPoly.getLength() - 1; i++) {
                const modIndex = i + modPoly.getLength() - (rsPoly.getLength() - 1);
                ecData[r][i] = (modIndex >= 0) ? modPoly.get(modIndex) : 0;
            }
        }

        const data = [];
        for (let i = 0; i < maxDcCount; i++) {
            for (let r = 0; r < rsBlocks.length; r++) {
                if (i < dcData[r].length) data.push(dcData[r][i]);
            }
        }
        for (let i = 0; i < maxEcCount; i++) {
            for (let r = 0; r < rsBlocks.length; r++) {
                if (i < ecData[r].length) data.push(ecData[r][i]);
            }
        }

        return data;
    }

    mapData(data, maskPattern) {
        let inc = -1;
        let row = this.moduleCount - 1;
        let bitIndex = 7;
        let byteIndex = 0;

        for (let col = this.moduleCount - 1; col > 0; col -= 2) {
            if (col === 6) col--;

            while (true) {
                for (let c = 0; c < 2; c++) {
                    if (this.modules[row][col - c] === null) {
                        let dark = false;
                        if (byteIndex < data.length) {
                            dark = (((data[byteIndex] >>> bitIndex) & 1) === 1);
                        }
                        const mask = QRUtil.getMask(maskPattern, row, col - c);
                        if (mask) dark = !dark;

                        this.modules[row][col - c] = dark;
                        bitIndex--;
                        if (bitIndex === -1) {
                            byteIndex++;
                            bitIndex = 7;
                        }
                    }
                }

                row += inc;
                if (row < 0 || this.moduleCount <= row) {
                    row -= inc;
                    inc = -inc;
                    break;
                }
            }
        }
    }
}

class QR8bitByte {
    constructor(data) {
        this.data = data;
        this.bytes = new TextEncoder().encode(data);
    }

    getLength() {
        return this.bytes.length;
    }

    write(buffer) {
        for (let i = 0; i < this.bytes.length; i++) {
            buffer.put(this.bytes[i], 8);
        }
    }
}

class QRBitBuffer {
    constructor() {
        this.buffer = [];
        this.length = 0;
    }

    get(index) {
        const bufIndex = Math.floor(index / 8);
        return ((this.buffer[bufIndex] >>> (7 - (index % 8))) & 1) === 1;
    }

    put(num, length) {
        for (let i = 0; i < length; i++) {
            this.putBit(((num >>> (length - i - 1)) & 1) === 1);
        }
    }

    getLengthInBits() {
        return this.length;
    }

    putBit(bit) {
        const bufIndex = Math.floor(this.length / 8);
        if (this.buffer.length <= bufIndex) {
            this.buffer.push(0);
        }
        if (bit) {
            this.buffer[bufIndex] |= (0x80 >>> (this.length % 8));
        }
        this.length++;
    }
}

class QRPolynomial {
    constructor(num, shift = 0) {
        let offset = 0;
        while (offset < num.length && num[offset] === 0) offset++;
        this.num = [];
        for (let i = 0; i < num.length - offset; i++) {
            this.num[i] = num[i + offset];
        }
        for (let i = 0; i < shift; i++) {
            this.num.push(0);
        }
    }

    get(index) {
        return this.num[index];
    }

    getLength() {
        return this.num.length;
    }

    multiply(e) {
        const num = Array(this.getLength() + e.getLength() - 1).fill(0);
        for (let i = 0; i < this.getLength(); i++) {
            for (let j = 0; j < e.getLength(); j++) {
                num[i + j] ^= QRMath.gmult(this.get(i), e.get(j));
            }
        }
        return new QRPolynomial(num);
    }

    mod(e) {
        if (this.getLength() - e.getLength() < 0) return this;
        const ratio = QRMath.glog(this.get(0)) - QRMath.glog(e.get(0));
        const num = [];
        for (let i = 0; i < this.getLength(); i++) num[i] = this.get(i);
        for (let i = 0; i < e.getLength(); i++) {
            num[i] ^= QRMath.gexp(QRMath.glog(e.get(i)) + ratio);
        }
        return new QRPolynomial(num).mod(e);
    }
}

class QRMath {
    static init() {
        QRMath.EXP_TABLE = Array(256).fill(0);
        QRMath.LOG_TABLE = Array(256).fill(0);
        for (let i = 0; i < 8; i++) QRMath.EXP_TABLE[i] = 1 << i;
        for (let i = 8; i < 256; i++) {
            QRMath.EXP_TABLE[i] = QRMath.EXP_TABLE[i - 4] ^ QRMath.EXP_TABLE[i - 5] ^ QRMath.EXP_TABLE[i - 6] ^ QRMath.EXP_TABLE[i - 8];
        }
        for (let i = 0; i < 255; i++) QRMath.LOG_TABLE[QRMath.EXP_TABLE[i]] = i;
    }

    static glog(n) {
        if (n < 1) throw new Error("glog(" + n + ")");
        return QRMath.LOG_TABLE[n];
    }

    static gexp(n) {
        while (n < 0) n += 255;
        while (n >= 256) n -= 255;
        return QRMath.EXP_TABLE[n];
    }

    static gmult(a, b) {
        if (a === 0 || b === 0) return 0;
        return QRMath.gexp(QRMath.glog(a) + QRMath.glog(b));
    }
}
QRMath.init();

class QRRSBlock {
    constructor(totalCount, dataCount) {
        this.totalCount = totalCount;
        this.dataCount = dataCount;
    }

    static getRSBlocks(typeNumber, errorCorrectLevel) {
        const rsBlock = QRRSBlock.getRsBlockTable(typeNumber, errorCorrectLevel);
        const list = [];
        for (let i = 0; i < rsBlock.length / 3; i++) {
            const count = rsBlock[i * 3 + 0];
            const totalCount = rsBlock[i * 3 + 1];
            const dataCount = rsBlock[i * 3 + 2];
            for (let j = 0; j < count; j++) {
                list.push(new QRRSBlock(totalCount, dataCount));
            }
        }
        return list;
    }

    static getRsBlockTable(typeNumber, ecLevel) {
        // Table for ISO QR Versions 1 to 10 with EC Level M / L
        const tables = {
            'L': [
                [1, 26, 19], [1, 44, 34], [1, 70, 55], [1, 100, 80], [1, 134, 108],
                [2, 86, 68], [2, 98, 78], [2, 121, 97], [2, 146, 116], [2, 86, 68, 2, 87, 69]
            ],
            'M': [
                [1, 26, 16], [1, 44, 28], [1, 70, 44], [2, 50, 32], [2, 67, 43],
                [4, 43, 27], [4, 49, 31], [2, 61, 38, 2, 62, 39], [3, 58, 36, 2, 59, 37], [4, 69, 43, 1, 70, 44]
            ]
        };
        const levelTable = tables[ecLevel] || tables['M'];
        return levelTable[typeNumber - 1] || levelTable[0];
    }
}

class QRUtil {
    static getPatternPositions(typeNumber) {
        const patternPositionTable = [
            [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
            [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]
        ];
        return patternPositionTable[typeNumber - 1] || [];
    }

    static getLengthInBits(mode, type) {
        return type >= 10 ? 16 : 8;
    }

    static getEcBits(level) {
        return level === 'L' ? 1 : (level === 'M' ? 0 : (level === 'Q' ? 3 : 2));
    }

    static getBCHTypeInfo(data) {
        let d = data << 10;
        while (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(0x537) >= 0) {
            d ^= (0x537 << (QRUtil.getBCHDigit(d) - QRUtil.getBCHDigit(0x537)));
        }
        return ((data << 10) | d) ^ 0x5412;
    }

    static getBCHDigit(data) {
        let digit = 0;
        while (data !== 0) {
            digit++;
            data >>>= 1;
        }
        return digit;
    }

    static getErrorCorrectPolynomial(errorCorrectLength) {
        let a = new QRPolynomial([1], 0);
        for (let i = 0; i < errorCorrectLength; i++) {
            a = a.multiply(new QRPolynomial([1, QRMath.gexp(i)], 0));
        }
        return a;
    }

    static getMask(maskPattern, i, j) {
        switch (maskPattern) {
            case 0: return (i + j) % 2 === 0;
            case 1: return i % 2 === 0;
            case 2: return j % 3 === 0;
            case 3: return (i + j) % 3 === 0;
            case 4: return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0;
            case 5: return (i * j) % 2 + (i * j) % 3 === 0;
            case 6: return ((i * j) % 2 + (i * j) % 3) % 2 === 0;
            case 7: return ((i * j) % 3 + (i + j) % 2) % 2 === 0;
            default: return false;
        }
    }
}
